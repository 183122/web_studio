<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ApiException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional `Idempotency-Key` header on POST requests: a retried request with the same key and
 * body returns the stored response instead of being applied twice (e.g. autosave retries).
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        if ($key === null || $user === null || ! $request->isMethod('POST')) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            throw ApiException::unprocessable('invalid_idempotency_key', 'Idempotency-Key must be 8–100 URL-safe characters');
        }
        $route = $request->method().' '.$request->path();
        $hash = hash('sha256', $route."\n".$request->getContent());

        $existing = IdempotencyKey::query()->where('user_id', $user->id)->where('key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->request_hash, $hash)) {
                throw ApiException::unprocessable('idempotency_key_reused', 'this Idempotency-Key was already used for a different request');
            }

            return response()->json($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
        }

        $response = $next($request);
        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            try {
                IdempotencyKey::create([
                    'user_id' => $user->id,
                    'key' => $key,
                    'route' => mb_substr($route, 0, 120),
                    'request_hash' => $hash,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getData(true),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent duplicate: the first writer wins; this response is still correct for this caller.
            }
        }

        return $response;
    }
}
