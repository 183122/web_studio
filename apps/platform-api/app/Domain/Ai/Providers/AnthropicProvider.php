<?php

namespace App\Domain\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiProviderException;
use Psr\Http\Client\ClientInterface;

/**
 * Claude via the official Anthropic PHP SDK. Structured outputs constrain the answer to our JSON
 * Schema; server-side refusal fallback (beta) is enabled by default for Claude Opus 5.5.
 */
class AnthropicProvider implements AiProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId,
        private readonly array $options = [],
        private readonly ?ClientInterface $transporter = null, // tests inject a PSR-18 fake
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->modelId;
    }

    public function isExternal(): bool
    {
        return true;
    }

    public function complete(AiCall $call): AiCompletion
    {
        $requestOptions = array_filter([
            'timeout' => (float) ($this->options['timeout_seconds'] ?? 240),
            'maxRetries' => (int) ($this->options['max_retries'] ?? 2),
            'transporter' => $this->transporter,
        ], fn ($v) => $v !== null);
        $client = new Client(apiKey: $this->apiKey, baseUrl: $this->options['anthropic_base_url'] ?? null, requestOptions: $requestOptions);
        $fallbacks = $this->options['fallbacks'] ?? 'default';

        try {
            $message = $client->beta->messages->create(
                maxTokens: $call->maxTokens,
                messages: [['role' => 'user', 'content' => $call->user]],
                model: $this->modelId,
                system: $call->system,
                outputConfig: [
                    'effort' => $this->options['effort'] ?? 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => $call->schema],
                ],
                fallbacks: $fallbacks ?: null,
                betas: $fallbacks ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new AiProviderException('ai_auth_failed', 'API key ของผู้ให้บริการ AI ไม่ถูกต้องหรือไม่มีสิทธิ์', false);
        } catch (BadRequestException $e) {
            throw new AiProviderException('ai_bad_request', 'ผู้ให้บริการ AI ปฏิเสธคำขอ: '.mb_substr($e->getMessage(), 0, 200), false);
        } catch (RateLimitException) {
            throw new AiProviderException('ai_rate_limited', 'ผู้ให้บริการ AI จำกัดจำนวนคำขอชั่วคราว', true);
        } catch (OverloadedException|InternalServerException) {
            throw new AiProviderException('ai_unavailable', 'ผู้ให้บริการ AI ไม่พร้อมให้บริการชั่วคราว', true);
        } catch (APITimeoutException) {
            throw new AiProviderException('ai_timeout', 'ผู้ให้บริการ AI ตอบช้าเกินกำหนด', true);
        } catch (APIConnectionException) {
            throw new AiProviderException('ai_unreachable', 'เชื่อมต่อผู้ให้บริการ AI ไม่ได้', true);
        } catch (APIStatusException $e) {
            throw new AiProviderException('ai_unavailable', 'ผู้ให้บริการ AI ตอบกลับผิดพลาด ('.($e->type?->value ?? 'error').')', true);
        }

        $in = $message->usage->inputTokens;
        $out = $message->usage->outputTokens;
        if ($message->stopReason === 'refusal') {
            throw new AiProviderException('ai_refused', 'AI ปฏิเสธคำขอนี้ตามนโยบายความปลอดภัย กรุณาปรับคำอธิบาย', false, $in, $out);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AiProviderException('ai_truncated', 'คำตอบของ AI ยาวเกินกำหนด กรุณาลดขอบเขตคำขอ', false, $in, $out);
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new AiProviderException('ai_invalid_output', 'AI ตอบกลับในรูปแบบที่ไม่ถูกต้อง', false, $in, $out);
        }

        return new AiCompletion($data, 'anthropic', $message->model, $in, $out);
    }
}
