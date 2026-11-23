<?php

namespace App\Http\Controllers;

use App\Domain\Data\Actor;
use App\Domain\Data\DataContext;
use App\Domain\Data\RecordService;
use App\Domain\Publishing\AppUserAuth;
use App\Domain\Publishing\Hostnames;
use App\Domain\Publishing\RuntimeAssets;
use App\Domain\Publishing\SiteResolver;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\Upcaster;
use App\Models\Asset;
use App\Services\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Serves published sites: HTML shell with SEO metadata + the release document for the runtime
 * renderer, release assets, and the production data/auth API. Only immutable releases are served.
 */
class RuntimeController extends Controller
{
    public function __construct(
        private readonly SiteResolver $sites,
        private readonly RecordService $records,
        private readonly AppUserAuth $auth,
    ) {}

    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; font-src 'self' data:; connect-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    /** Public view of the release: workflows (recipients, templates) and internal settings are never sent. */
    public static function publicDocument(array $doc): array
    {
        $doc['workflows'] = [];
        unset($doc['projectId']);

        return $doc;
    }

    public function page(Request $request, ?string $path = null)
    {
        $host = $request->getHost();
        if (Hostnames::isPlatformHost($host)) {
            if (($path ?? '') === '') {
                return response()->json(['service' => 'ai-studio-cloud/platform-api', 'api' => '/api/v1', 'health' => '/api/health']);
            }
            abort(404);
        }
        $site = $this->sites->resolve($host);
        if ($site === null) {
            return $this->html(404, 'ไม่พบเว็บไซต์', '<main style="font-family:system-ui;padding:4rem;text-align:center"><h1>404</h1><p>ไม่พบเว็บไซต์นี้ หรือยังไม่ได้เผยแพร่</p></main>', null, true);
        }
        if ($blocked = $this->meter($site)) {
            return $blocked;
        }
        $doc = Upcaster::upcast(DocumentCodec::decode($site['release']->document));
        $route = '/'.ltrim((string) $path, '/');
        $route = $route === '/' ? '/' : rtrim($route, '/');
        foreach ($doc['routes'] as $r) {
            if ($r['from'] === $route) {
                return redirect($r['to'], $r['status']);
            }
        }
        $page = collect($doc['pages'])->firstWhere('path', $route);
        $status = 200;
        if ($page === null) {
            $status = 404;
            $page = collect($doc['pages'])->firstWhere('id', $doc['settings']['notFoundPageId'] ?? '') ?? ['name' => '404', 'seo' => ['title' => 'ไม่พบหน้า', 'description' => '', 'noindex' => true]];
        }
        $title = ($page['seo']['title'] ?: $page['name']).($doc['seo']['siteName'] ? ' | '.$doc['seo']['siteName'] : '');
        $bootstrap = [
            'release' => ['version' => $site['release']->version, 'hash' => $site['release']->content_hash],
            'document' => DocumentCodec::restoreObjects(self::publicDocument($doc)),
            'path' => $route,
        ];

        return $this->html($status, $title, '', $bootstrap, (bool) ($page['seo']['noindex'] ?? false), $page['seo']['description'] ?: $doc['seo']['description'], $doc['seo']['lang']);
    }

    private function html(int $status, string $title, string $body, ?array $bootstrap, bool $noindex, string $description = '', string $lang = 'th'): Response
    {
        return response(self::shell($title, $body, $bootstrap, $noindex, $description, $lang), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => self::CSP, 'Cache-Control' => 'no-cache']);
    }

    /** HTML document for a page (also used by the static export, which passes a CSP <meta>). */
    public static function shell(string $title, string $body, ?array $bootstrap, bool $noindex, string $description = '', string $lang = 'th', bool $cspMeta = false): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assets = RuntimeAssets::entry();
        $head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .($cspMeta ? '<meta http-equiv="Content-Security-Policy" content="'.$e(str_replace("; frame-ancestors 'self'", '', self::CSP)).'">' : '')
            .'<title>'.$e($title).'</title>'
            .($description !== '' ? '<meta name="description" content="'.$e($description).'">' : '')
            .($noindex ? '<meta name="robots" content="noindex">' : '');
        $data = '';
        if ($bootstrap !== null) {
            // JSON data block (not executed); HEX flags make "</script>" and similar inert.
            $json = json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $data = '<script id="__SITE__" type="application/json">'.$json.'</script>';
            if ($assets) {
                foreach ($assets['css'] as $css) {
                    $head .= '<link rel="stylesheet" href="'.$e($css).'">';
                }
                $data .= '<script type="module" src="'.$e($assets['js']).'"></script>';
            } else {
                $body = '<noscript>runtime not built</noscript>';
            }
        }

        return '<!doctype html><html lang="'.$e($lang).'"><head>'.$head.'</head><body><div id="root">'.$body.'</div>'.$data.'</body></html>';
    }

    /** Release assets: public, but only files referenced by the live release of this host. */
    public function asset(Request $request, string $asset)
    {
        $site = $this->sites->resolve($request->getHost());
        abort_if($site === null || ! in_array($asset, $site['release']->manifest['assets'] ?? [], true), 404);
        $file = Asset::query()->where('workspace_id', $site['project']->workspace_id)->whereKey($asset)->first();
        abort_if($file === null || ! $file->isServable() || ! Storage::disk($file->disk)->exists($file->path), 404);
        if ($blocked = $this->meter($site)) {
            return $blocked;
        }
        $body = Storage::disk($file->disk)->get($file->path);
        app(UsageMeter::class)->add($site['project']->organization_id, 'bandwidth_bytes', strlen($body));

        return response($body, 200, [
            'Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Counts the request; past the hard limit the site pauses (503) until the next period or an upgrade. */
    private function meter(array $site): ?Response
    {
        $orgId = $site['project']->organization_id;
        $meter = app(UsageMeter::class);
        if ($meter->overHardLimit($orgId, 'runtime_requests')) {
            return $this->html(503, 'หยุดให้บริการชั่วคราว', '<main style="font-family:system-ui;padding:4rem;text-align:center"><h1>หยุดให้บริการชั่วคราว</h1><p>เว็บไซต์นี้ใช้งานเกินโควตาของแพ็กเกจเดือนนี้ เจ้าของเว็บไซต์สามารถอัปเกรดแพ็กเกจเพื่อเปิดใช้งานต่อได้ทันที</p></main>', null, true);
        }
        $meter->add($orgId, 'runtime_requests');

        return null;
    }

    // ---------------------------------------------------------------- runtime API (middleware: ResolveSite)

    private function context(Request $request): array
    {
        $site = $request->attributes->get('site');

        return [DataContext::forRelease($site['project'], $site['release']), $site['project']];
    }

    public function listRecords(Request $request, string $modelId): JsonResponse
    {
        [$ctx, $project] = $this->context($request);
        $model = $ctx->model($modelId);
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'string', 'max:64'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])], 'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $actor = Actor::appUser($this->auth->current($request, $project), $request->ip());
        $page = $this->records->list($ctx, $model, $actor, $q, 'production');

        return response()->json([
            'data' => array_map(fn ($r) => RecordService::present($r, $model), $page->items()),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function createRecord(Request $request, string $modelId): JsonResponse
    {
        [$ctx, $project] = $this->context($request);
        $model = $ctx->model($modelId);
        $body = $request->validate([
            'data' => ['required', 'array', 'max:100'],
            'meta' => ['required', 'array'],
            'meta.hp' => ['present', 'nullable', 'string', 'max:0'],
            'meta.elapsedMs' => ['required', 'integer', 'min:1500'],
        ]);
        $actor = Actor::appUser($this->auth->current($request, $project), $request->ip());
        $record = $this->records->create($ctx, $model, $body['data'], $actor, 'form', 'production');

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function me(Request $request): JsonResponse
    {
        [, $project] = $this->context($request);
        $user = $this->auth->current($request, $project);

        return response()->json(['data' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role] : null]);
    }

    public function register(Request $request): JsonResponse
    {
        [, $project] = $this->context($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $user = $this->auth->register($project, $data['name'], $data['email'], $data['password']);

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role]], 201)
            ->withCookie($this->auth->startSession($request, $user));
    }

    public function login(Request $request): JsonResponse
    {
        [, $project] = $this->context($request);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = $this->auth->attempt($project, $data['email'], $data['password']);
        if ($user === null) {
            throw ValidationException::withMessages(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        }

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role]])
            ->withCookie($this->auth->startSession($request, $user));
    }

    public function logout(Request $request): JsonResponse
    {
        return response()->json(['data' => ['ok' => true]])->withCookie($this->auth->endSession($request));
    }
}
