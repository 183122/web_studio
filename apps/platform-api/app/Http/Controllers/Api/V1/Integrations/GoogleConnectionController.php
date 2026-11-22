<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Domain\Integrations\Google\GoogleApiException;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleScopes;
use App\Domain\Platform\GoogleOAuthSettings;
use App\Http\Controllers\Controller;
use App\Models\GoogleConnection;
use App\Models\Project;
use App\Models\SheetSync;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** M22: workspace Google connection (explicit grant by a workspace admin). */
class GoogleConnectionController extends Controller
{
    public function __construct(private readonly GoogleOAuth $oauth, private readonly GoogleOAuthSettings $settings) {}

    public static function present(?GoogleConnection $c): ?array
    {
        return $c ? [
            'email' => $c->email, 'status' => $c->status, 'features' => GoogleScopes::features($c->scopes ?? []),
            'connectedBy' => $c->connected_by ? User::query()->whereKey($c->connected_by)->value('name') : null,
            'connectedAt' => $c->created_at?->toIso8601String(), 'lastUsedAt' => $c->last_used_at?->toIso8601String(), 'lastError' => $c->last_error,
        ] : null;
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json(['data' => [
            'platformConfigured' => $this->settings->credentials() !== null,
            'canManage' => $request->user()->can('admin', $workspace),
            'connection' => self::present(GoogleConnection::query()->where('workspace_id', $workspace->id)->first()),
            'features' => array_keys(GoogleScopes::FEATURES),
        ]]);
    }

    public function connect(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $data = $request->validate(['features' => ['required', 'array', 'min:1'], 'features.*' => [Rule::in(array_keys(GoogleScopes::FEATURES))]]);

        return response()->json(['data' => ['url' => $this->oauth->authorizationUrl($request->user(), $workspace, array_values(array_unique($data['features'])))]]);
    }

    /**
     * OAuth redirect target (web middleware: the studio session cookie is sent on this top-level
     * navigation). Always redirects back to the studio with a result code, never shows tokens.
     */
    public function callback(Request $request): RedirectResponse
    {
        $back = fn (string $result, string $reason = '') => redirect()->away(config('studio.frontend_url').'/app/integrations?'.http_build_query(array_filter(['google' => $result, 'reason' => $reason])));
        $user = Auth::guard('web')->user();
        if ($user === null) {
            return $back('error', 'session');
        }
        $state = $this->oauth->consumeState((string) $request->query('state', ''), $user);
        if ($state === null) {
            return $back('error', 'state');
        }
        if ($request->query('error')) {
            return $back('error', $request->query('error') === 'access_denied' ? 'denied' : 'google');
        }
        $workspace = Workspace::query()->find($state['workspace_id']);
        if ($workspace === null || ! $user->can('admin', $workspace)) {
            return $back('error', 'forbidden');
        }
        try {
            $connection = $this->oauth->completeConnection($state, (string) $request->query('code', ''), $user);
        } catch (GoogleApiException $e) {
            return $back('error', $e->kind);
        }
        $missing = array_diff(GoogleScopes::for($state['features']), $connection->scopes);
        // Syncs paused by a previous disconnect can resume once access is granted again.
        SheetSync::query()->whereIn('project_id', Project::query()->where('workspace_id', $workspace->id)->select('id'))
            ->where('status', 'disconnected')->update(['status' => 'paused']);

        return $back($missing === [] ? 'connected' : 'partial');
    }

    public function disconnect(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $connection = GoogleConnection::query()->where('workspace_id', $workspace->id)->first();
        $revoked = false;
        if ($connection) {
            $revoked = $this->oauth->disconnect($connection);
            SheetSync::query()->whereIn('project_id', Project::query()->where('workspace_id', $workspace->id)->select('id'))
                ->update(['status' => 'disconnected']);
        }

        return response()->json(['data' => ['revokedAtGoogle' => $revoked]]);
    }
}
