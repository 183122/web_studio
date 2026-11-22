<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** PSR-18 client that replays canned Anthropic API responses and records requests (no network). */
class FakeAnthropicTransport implements ClientInterface
{
    /** @var list<ResponseInterface> */
    public array $queue = [];

    /** @var list<array{headers: array, body: array}> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = ['headers' => array_map(fn ($v) => implode(',', $v), $request->getHeaders()), 'body' => json_decode((string) $request->getBody(), true), 'uri' => (string) $request->getUri()];

        return array_shift($this->queue) ?? new Response(500, ['content-type' => 'application/json'], '{"type":"error","error":{"type":"api_error","message":"no more fakes"}}');
    }

    public function message(string $text, string $stop = 'end_turn', int $in = 1200, int $out = 900): self
    {
        $this->queue[] = new Response(200, ['content-type' => 'application/json', 'request-id' => 'req_test'], json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ], JSON_UNESCAPED_UNICODE));

        return $this;
    }

    public function error(int $status, string $type): self
    {
        $this->queue[] = new Response($status, ['content-type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'fake '.$type]]));

        return $this;
    }
}
