<?php

namespace App\Http\Middleware;

use App\Domain\Publishing\SiteResolver;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runtime API: resolve the published site from the Host header. State-changing requests must carry
 * `X-Runtime-Request: 1` — a custom header a cross-site form cannot send (no CORS is offered here),
 * which together with SameSite=Lax cookies blocks CSRF against app-user sessions.
 */
class ResolveSite
{
    public function __construct(private readonly SiteResolver $sites) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->sites->resolve($request->getHost());
        if ($site === null) {
            throw new ApiException(404, 'site_not_found', 'ไม่พบเว็บไซต์นี้');
        }
        if (! $request->isMethodSafe() && $request->header('X-Runtime-Request') !== '1') {
            throw new ApiException(403, 'csrf_header_missing', 'คำขอไม่ถูกต้อง');
        }
        $request->attributes->set('site', $site);

        return $next($request);
    }
}
