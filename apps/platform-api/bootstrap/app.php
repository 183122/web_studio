<?php

use App\Domain\Integrations\Google\GoogleApiException;
use App\Domain\Schema\SchemaException;
use App\Http\Middleware\DirectNavigationIsFrontend;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiException;
use Illuminate\Auth\AuthenticationException;
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
    ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null, 'requestId' => request()?->attributes->get('request_id')], fn ($v) => $v !== null)],
    $status,
);

$app = Application::configure(basePath: dirname(__DIR__))
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
        $middleware->prepend(RequestId::class);
        $middleware->prependToGroup('api', DirectNavigationIsFrontend::class);
        // The API never redirects guests (there is no server-side login page): it answers 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : rtrim((string) config('studio.frontend_url'), '/').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(fn (ApiException $e) => $error($e->status, $e->errorCode, $e->getMessage(), $e->details));
        // Google failures surface as actionable messages; never include tokens or raw responses.
        $exceptions->render(fn (GoogleApiException $e) => $error(
            match ($e->kind) {
                'rate_limited', 'transient' => 503, 'not_found' => 404, default => 422
            },
            'google_'.$e->kind, $e->userMessage(),
        ));
        // Expected, user-facing errors are not application faults.
        $exceptions->dontReport([GoogleApiException::class, ApiException::class]);
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
                $status = $e->getStatusCode();
                // Generic framework messages ("Not Found", "Forbidden", "Invalid signature.") are replaced with clear Thai ones.
                $generic = in_array($e->getMessage(), ['', 'Not Found', 'Forbidden', 'This action is unauthorized.', 'Invalid signature.'], true);

                return match (true) {
                    $status === 404 => $error(404, 'not_found', 'ไม่พบข้อมูลที่ต้องการ'),
                    $status === 403 => $error(403, 'forbidden', $generic ? 'คุณไม่มีสิทธิ์ดำเนินการนี้' : $e->getMessage()),
                    default => $error($status, 'http_'.$status, $e->getMessage() ?: 'Request failed'),
                };
            }
        });
    })->create();

// Shared hosting (DirectAdmin/cPanel): the web root is a sibling folder (public_html) instead of ./public.
if (! is_dir($app->basePath('public')) && is_dir($app->basePath('../public_html'))) {
    $app->usePublicPath(realpath($app->basePath('../public_html')));
}

return $app;
