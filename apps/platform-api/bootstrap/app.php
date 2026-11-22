<?php

use App\Domain\Schema\SchemaException;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$error = static fn (int $status, string $code, string $message, array $details = []) => response()->json(
    ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null], fn ($v) => $v !== null)],
    $status,
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', (string) env('TRUSTED_PROXIES')) : null);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(fn (ApiException $e) => $error($e->status, $e->errorCode, $e->getMessage(), $e->details));
        $exceptions->render(fn (SchemaException $e) => $error(422, 'schema_invalid', $e->getMessage(), ['issues' => $e->issues]));
        $exceptions->render(function (ValidationException $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error(422, 'validation_failed', 'ข้อมูลไม่ถูกต้อง', ['fields' => $e->errors()]);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error(401, 'unauthenticated', 'กรุณาเข้าสู่ระบบ');
            }
        });
        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error(419, 'csrf_mismatch', 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่');
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error(404, 'not_found', 'ไม่พบข้อมูลที่ต้องการ');
            }
        });
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error(403, 'forbidden', $e->getMessage() ?: 'คุณไม่มีสิทธิ์ดำเนินการนี้');
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error(429, 'rate_limited', 'มีคำขอมากเกินไป กรุณาลองใหม่ภายหลัง', ['retryAfter' => (int) ($e->getHeaders()['Retry-After'] ?? 60)])
                    ->withHeaders($e->getHeaders());
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($error) {
            if ($request->is('api/*')) {
                return $error($e->getStatusCode(), 'http_'.$e->getStatusCode(), $e->getMessage() ?: 'Request failed');
            }
        });
    })->create();
