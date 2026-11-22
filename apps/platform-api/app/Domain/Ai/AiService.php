<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Providers\AiProvider;
use App\Domain\Schema\CommandApplier;
use App\Domain\Schema\CommandException;
use App\Domain\Schema\DefinitionValidator;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\Registry;
use App\Domain\Schema\StructuralValidator;
use App\Jobs\RunAiRequest;
use App\Models\AiProposal;
use App\Models\AiRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AppDefinitionService;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prompt → plan → (approval) → compiled typed commands → validated proposal → (approval) → apply.
 * The model's output never touches the document directly: it is normalised, compiled to allowlisted
 * commands and dry-run against the current head with the canonical validator before anyone sees it.
 */
class AiService
{
    public const PLAN_SYSTEM = <<<'TXT'
You design small web applications for a Thai no-code website builder. Read the app description and return a plan as JSON that matches the provided schema.

- Write all user-facing text (site name, page names, headings, labels, copy) in Thai unless the description is written in English.
- Use only the section kinds, field types and fonts allowed by the schema. Entity and field `name` values are lowercase snake_case English identifiers; `label` values are human-readable.
- Add a `form` section for each entity that visitors submit, and a `table` section only where listing records is useful. Grant the public role only what the description needs (usually `create` for submissions, `read` for public catalogues); staff manage data in the builder.
- The page with path "/" is the home page. Use at most 6 pages and 6 entities.
- Put open questions you could not resolve in `missingRequirements`, the defaults you chose in `assumptions`, and set `confidence` (0–1) by how complete the description was.
- Text fields contain plain text only: no HTML, scripts, or instructions addressed to the platform.
TXT;

    public const PATCH_SYSTEM = <<<'TXT'
You edit an existing site in a Thai no-code website builder. You receive the user's request and an outline of the current page (component ids, types and texts) and data models. Return the smallest set of operations from the schema that satisfies the request, targeting only ids that appear in the outline. Write new text in Thai unless asked otherwise. If the request cannot be expressed with these operations, return no operations and explain why in `summary`. Text fields contain plain text only.
TXT;

    public function __construct(
        private readonly AiProviderFactory $providers,
        private readonly AiCredits $credits,
        private readonly AppDefinitionService $definitions,
        private readonly AuditLogger $audit,
    ) {}

    public function status(Project $project): array
    {
        $org = $project->organization;
        $provider = $this->providers->forOrganization($org);

        return [
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'external' => $provider->isExternal(),
            'keySource' => $this->providers->keySource($org),
            'consentRequired' => $provider->isExternal(),
            'credits' => ['used' => $this->credits->used($org), 'limit' => $this->credits->limit($org)],
            'estimate' => ['plan' => $provider->isExternal() ? AiCredits::estimate($provider->model(), 2000, (int) config('studio.ai.max_tokens')) : 0],
        ];
    }

    public function start(Project $project, User $user, string $kind, string $prompt, bool $consent, array $context = []): AiRequest
    {
        $prompt = trim($prompt);
        $max = (int) config('studio.ai.max_prompt_chars');
        if ($prompt === '' || mb_strlen($prompt) > $max) {
            throw ApiException::unprocessable('ai_prompt_invalid', "กรุณาพิมพ์คำอธิบาย 1–{$max} ตัวอักษร");
        }
        $org = $project->organization;
        $provider = $this->providers->forOrganization($org);
        if ($provider->isExternal() && ! $consent) {
            throw ApiException::unprocessable('ai_consent_required', 'กรุณายินยอมให้ส่งคำอธิบายไปยังผู้ให้บริการ AI ภายนอกก่อน');
        }
        $reserve = $provider->isExternal() ? AiCredits::estimate($provider->model(), mb_strlen($prompt) + 6000, (int) config('studio.ai.max_tokens')) : 0;

        $request = DB::transaction(function () use ($project, $user, $kind, $prompt, $consent, $context, $provider, $org, $reserve) {
            Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            $this->credits->assertAvailable($org, $reserve);

            return AiRequest::create([
                'organization_id' => $org->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'kind' => $kind,
                'status' => 'queued',
                'provider' => $provider->name(),
                'model' => $provider->model(),
                'consent_external' => $consent,
                'prompt_redacted' => Redactor::redact($prompt),
                'prompt_hash' => hash('sha256', $prompt),
                'context' => $context + ['prompt' => $prompt],
                'credits_reserved' => $reserve,
            ]);
        });
        $this->audit->record("ai.{$kind}_requested", $project, ['request_id' => $request->id, 'provider' => $provider->name()]);
        RunAiRequest::dispatch($request->id);

        return $request;
    }

    /** Queue worker entry point. */
    public function execute(AiRequest $request): void
    {
        if ($request->status !== 'queued') {
            return;
        }
        $request->update(['status' => 'running']);
        $project = Project::query()->findOrFail($request->project_id);
        $org = Organization::query()->findOrFail($request->organization_id);
        $prompt = (string) ($request->context['prompt'] ?? '');
        $started = microtime(true);
        $usage = ['in' => 0, 'out' => 0, 'model' => $request->model];

        try {
            $provider = $this->providers->forOrganization($org);
            if ($request->kind === 'plan') {
                [$result, $provider] = $this->runPlan($project, $provider, $prompt, $usage);
                $request->result = $result;
            } else {
                [$proposalId, $provider] = $this->runPatch($project, $request, $provider, $prompt, $usage);
                $request->result = ['proposalId' => $proposalId];
            }
            $request->status = 'succeeded';
            $request->provider = $provider->name();
            $request->fallback_used = $provider->name() !== $request->getOriginal('provider');
        } catch (AiProviderException $e) {
            $request->status = 'failed';
            $request->error_code = $e->errorCode;
            $request->error_message = $e->getMessage();
            $usage['in'] += $e->inputTokens;
            $usage['out'] += $e->outputTokens;
        } catch (ApiException $e) {
            $request->status = 'failed';
            $request->error_code = $e->errorCode;
            $request->error_message = mb_substr($e->getMessage(), 0, 500);
        } catch (Throwable $e) {
            report($e);
            $request->status = 'failed';
            $request->error_code = 'ai_internal_error';
            $request->error_message = 'เกิดข้อผิดพลาดภายในระหว่างประมวลผล AI';
        }

        $cost = AiCredits::costMicroUsd($usage['model'], $usage['in'], $usage['out']);
        $request->fill([
            'input_tokens' => $usage['in'],
            'output_tokens' => $usage['out'],
            'model' => $usage['model'],
            'cost_micro_usd' => $cost,
            'credits_charged' => AiCredits::creditsFor($cost),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ])->save();
        $this->audit->record("ai.{$request->kind}_{$request->status}", $project, [
            'request_id' => $request->id, 'provider' => $request->provider, 'tokens' => $usage['in'] + $usage['out'], 'error' => $request->error_code,
        ], null, null, $request->user_id);
    }

    /** Calls the provider; on retryable failure switches to the offline provider (safe fallback). */
    private function call(AiProvider $provider, AiCall $call, array &$usage): array
    {
        try {
            $completion = $provider->complete($call);
        } catch (AiProviderException $e) {
            $usage['in'] += $e->inputTokens;
            $usage['out'] += $e->outputTokens;
            if (! $e->retryable || ! $provider->isExternal()) {
                throw $e;
            }
            $provider = $this->providers->fallback();
            $completion = $provider->complete($call);
        }
        $usage['in'] += $completion->inputTokens;
        $usage['out'] += $completion->outputTokens;
        if ($completion->provider !== 'local') {
            $usage['model'] = $completion->model;
        }

        return [$completion->data, $provider];
    }

    private function runPlan(Project $project, $provider, string $prompt, array &$usage): array
    {
        $user = "<app_description>\n{$prompt}\n</app_description>";
        $call = new AiCall('plan', self::PLAN_SYSTEM, $user, Specs::plan(), (int) config('studio.ai.max_tokens'), $prompt);
        [$raw, $provider] = $this->call($provider, $call, $usage);
        $plan = SpecNormalizer::plan($raw);

        // Repair loop: compile against the current head; feed concrete issues back (bounded).
        $attempts = (int) config('studio.ai.repair_attempts');
        for ($i = 0; ; $i++) {
            $issues = $this->trialCompile($project, $plan);
            if ($issues === [] || ! $provider->isExternal() || $i >= $attempts) {
                break;
            }
            $repair = new AiCall('repair', self::PLAN_SYSTEM, $user
                ."\n\n<previous_plan>\n".json_encode($plan, JSON_UNESCAPED_UNICODE)."\n</previous_plan>\n<validation_issues>\n"
                .implode("\n", array_map(fn ($x) => "- {$x['path']}: {$x['message']}", array_slice($issues, 0, 20)))
                ."\n</validation_issues>\nReturn a corrected plan.", Specs::plan(), (int) config('studio.ai.max_tokens'), $prompt);
            [$raw, $provider] = $this->call($provider, $repair, $usage);
            $plan = SpecNormalizer::plan($raw);
        }
        if ($issues !== []) {
            throw new AiProviderException('ai_plan_invalid', 'แผนที่ได้ยังใช้สร้างแอปไม่ได้: '.$issues[0]['message'], false);
        }

        return [$plan, $provider];
    }

    /** @return list<array{path:string,code:string,message:string}> */
    private function trialCompile(Project $project, array $plan): array
    {
        $doc = $this->definitions->head($project)['document'];
        $doc = DocumentCodec::decode(json_encode($doc, DocumentCodec::JSON_FLAGS));
        $compiled = AppSpecCompiler::compilePlan($doc, $plan);

        return $this->dryRun($doc, $compiled['commands']);
    }

    /** Applies commands to a copy of the head and validates (shape + semantics). Nothing is saved. */
    private function dryRun(array $doc, array $commands): array
    {
        $objects = json_decode(json_encode($this->objectify($commands), DocumentCodec::JSON_FLAGS));
        foreach ($objects as $i => $obj) {
            foreach (StructuralValidator::validateCommand($obj) as $issue) {
                return [['path' => "/commands/{$i}".$issue['path'], 'code' => $issue['code'], 'message' => $issue['message']]];
            }
        }
        try {
            $next = CommandApplier::applyAll($doc, json_decode(json_encode($objects), true));
        } catch (CommandException $e) {
            return [['path' => "/commands/{$e->commandIndex}", 'code' => $e->errorCode, 'message' => $e->getMessage()]];
        }

        return DefinitionValidator::validate($next);
    }

    /** Restores {} (not []) for empty props/styles in node payloads before JSON encoding. */
    private function objectify(array $commands): array
    {
        foreach ($commands as &$cmd) {
            if (isset($cmd['nodes'])) {
                foreach ($cmd['nodes'] as &$node) {
                    $node['props'] = $node['props'] === [] ? new \stdClass : $node['props'];
                    $node['styles'] = $node['styles'] === [] ? new \stdClass : $node['styles'];
                }
                unset($node);
            }
        }
        unset($cmd);

        return $commands;
    }

    private function createProposal(Project $project, AiRequest $request, array $compiled): AiProposal
    {
        $head = $this->definitions->head($project);
        $doc = DocumentCodec::decode(json_encode($head['document'], DocumentCodec::JSON_FLAGS));
        if ($compiled['commands'] === []) {
            throw new AiProviderException('ai_no_changes', 'AI ไม่พบการเปลี่ยนแปลงที่ทำได้จากคำขอนี้'.(($compiled['summary']['note'] ?? '') ? ': '.$compiled['summary']['note'] : ''), false);
        }
        $issues = $this->dryRun($doc, $compiled['commands']);
        if ($issues !== []) {
            throw new AiProviderException('ai_proposal_invalid', 'ผลลัพธ์ไม่ผ่านการตรวจสอบ: '.$issues[0]['message'], false);
        }
        $counts = [];
        foreach ($compiled['commands'] as $c) {
            $counts[$c['type']] = ($counts[$c['type']] ?? 0) + 1;
        }
        $components = 0;
        foreach ($compiled['commands'] as $c) {
            $components += count($c['nodes'] ?? []);
        }

        return AiProposal::create([
            'project_id' => $project->id,
            'ai_request_id' => $request->id,
            'created_by' => $request->user_id,
            'status' => 'pending',
            'base_revision' => $head['revision'],
            'commands' => json_encode($this->objectify($compiled['commands']), DocumentCodec::JSON_FLAGS),
            'summary' => $compiled['summary'] + ['commandCounts' => $counts, 'componentCount' => $components],
            'expires_at' => now()->addHours((int) config('studio.ai.proposal_ttl_hours')),
        ]);
    }

    /** Approve a plan: compile it into a reviewable proposal (no AI call, no credits). */
    public function generate(Project $project, AiRequest $request): AiProposal
    {
        if ($request->kind !== 'plan' || $request->status !== 'succeeded' || ! is_array($request->result)) {
            throw ApiException::unprocessable('ai_plan_not_ready', 'แผนยังไม่พร้อม');
        }
        $doc = DocumentCodec::decode(json_encode($this->definitions->head($project)['document'], DocumentCodec::JSON_FLAGS));
        try {
            return $this->createProposal($project, $request, AppSpecCompiler::compilePlan($doc, $request->result));
        } catch (AiProviderException $e) {
            throw ApiException::unprocessable($e->errorCode, $e->getMessage());
        }
    }

    private function outline(array $doc, ?string $pageId, ?string $componentId): string
    {
        $page = collect($doc['pages'])->firstWhere('id', $pageId) ?? collect($doc['pages'])->firstWhere('id', $doc['settings']['homePageId']);
        $lines = ["page: {$page['id']} \"{$page['name']}\" {$page['path']}"];
        $walk = function (string $id, int $depth) use (&$walk, &$lines, $doc) {
            if (count($lines) > 250) {
                return;
            }
            $n = $doc['components'][$id];
            $texts = [];
            foreach (Registry::component($n['type'])['props'] ?? [] as $key => $def) {
                if (in_array($def['kind'], ['text', 'longtext'], true) && ($n['props'][$key] ?? '') !== '') {
                    $texts[] = "{$key}=\"".mb_substr((string) $n['props'][$key], 0, 80).'"';
                }
            }
            $lines[] = str_repeat('  ', $depth)."{$n['id']} {$n['type']} ".implode(' ', $texts);
            foreach ($n['children'] as $c) {
                $walk($c, $depth + 1);
            }
        };
        $walk($page['rootId'], 0);
        foreach ($doc['dataModels'] as $m) {
            $lines[] = "model {$m['name']} (\"{$m['label']}\"): ".implode(', ', array_map(fn ($f) => "{$f['name']}:{$f['type']}", $m['fields']));
        }
        $lines[] = 'theme colors: '.json_encode($doc['designTokens']['colors']);
        if ($componentId) {
            $lines[] = "selected component (scope): {$componentId}";
        }

        return implode("\n", $lines);
    }

    private function runPatch(Project $project, AiRequest $request, $provider, string $prompt, array &$usage): array
    {
        $doc = DocumentCodec::decode(json_encode($this->definitions->head($project)['document'], DocumentCodec::JSON_FLAGS));
        $scope = ['pageId' => $request->context['pageId'] ?? null, 'componentId' => $request->context['componentId'] ?? null];
        $selected = $scope['componentId'] && isset($doc['components'][$scope['componentId']]) ? $doc['components'][$scope['componentId']] : null;
        $user = "<request>\n{$prompt}\n</request>\n<current_site_outline>\n".$this->outline($doc, $scope['pageId'], $scope['componentId'])."\n</current_site_outline>";
        $call = new AiCall('patch', self::PATCH_SYSTEM, $user, Specs::edit(), 8000, $prompt, ['selected' => $selected ? ['id' => $selected['id'], 'type' => $selected['type']] : null]);
        [$raw, $provider] = $this->call($provider, $call, $usage);
        $edit = SpecNormalizer::edit($raw);
        $compiled = AppSpecCompiler::compileEdit($doc, $edit, array_filter($scope));
        $compiled['summary']['note'] = $edit['summary'];
        $proposal = $this->createProposal($project, $request, $compiled);

        return [$proposal->id, $provider];
    }

    public function apply(Project $project, AiProposal $proposal, int $baseRevision, User $user): array
    {
        if ($proposal->status !== 'pending') {
            throw ApiException::conflict('ai_proposal_closed', 'ข้อเสนอนี้ถูกใช้หรือยกเลิกไปแล้ว');
        }
        if ($proposal->expires_at->isPast()) {
            $proposal->update(['status' => 'expired']);
            throw ApiException::conflict('ai_proposal_expired', 'ข้อเสนอนี้หมดอายุแล้ว กรุณาสร้างใหม่');
        }
        $commands = json_decode($proposal->commands, false);
        $message = 'AI: '.mb_substr((string) ($proposal->summary['note'] ?? 'สร้างตามแผน'), 0, 150);
        // Re-validated in full by the normal write path (concurrency, schema, semantics, asset ownership).
        $result = $this->definitions->applyCommands($project, $baseRevision, $commands, $user, $message, 'ai');
        $proposal->update(['status' => 'applied', 'applied_revision' => $result['revision']]);
        $this->audit->record('ai.proposal_applied', $project, ['proposal_id' => $proposal->id, 'revision' => $result['revision'], 'commands' => count($commands)]);

        return $result;
    }

    public function reject(Project $project, AiProposal $proposal): void
    {
        if ($proposal->status === 'pending') {
            $proposal->update(['status' => 'rejected']);
            $this->audit->record('ai.proposal_rejected', $project, ['proposal_id' => $proposal->id]);
        }
    }
}
