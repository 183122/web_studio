<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform operator area. Non-admins get 404 (the area's existence is not disclosed). */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            throw new ApiException(404, 'not_found', 'ไม่พบหน้าที่ต้องการ');
        }

        return $next($request);
    }
}
