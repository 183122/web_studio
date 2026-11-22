<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum only uses the session for requests whose Origin/Referer is the studio. Typed or bookmarked
 * GET links (invoice print, file download, data export) carry neither header and would get 401.
 * For safe methods with *no* Origin and *no* Referer, mark the request as coming from the studio.
 * Safe: cross-site pages always send a (foreign) Referer/Origin or are blocked by SameSite=Lax for
 * subresource/fetch requests, and GET endpoints never change state.
 */
class DirectNavigationIsFrontend
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() && $request->is('api/v1/*') && ! $request->headers->has('Origin') && ! $request->headers->has('Referer')) {
            $request->headers->set('Referer', rtrim((string) config('studio.frontend_url'), '/').'/');
        }

        return $next($request);
    }
}
