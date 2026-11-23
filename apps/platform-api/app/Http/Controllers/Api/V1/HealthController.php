<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    /**
     * Readiness probe. Critical (503 when failing): database, cache, asset storage.
     * Informational: scheduler heartbeat, failed queue jobs — reported for monitoring, never 503.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $check = function (string $name, callable $fn) use (&$checks) {
            try {
                $checks[$name] = $fn() ? 'ok' : 'error';
            } catch (Throwable) {
                $checks[$name] = 'error';
            }
        };
        $check('database', fn () => DB::select('select 1') !== null);
        $check('cache', function () {
            Cache::put('health:probe', '1', 5);

            return Cache::get('health:probe') === '1';
        });
        $check('storage', function () {
            $disk = Storage::disk('assets');
            $disk->put('.health', (string) time());

            return $disk->exists('.health');
        });
        $ok = ! in_array('error', $checks, true);

        $beat = rescue(fn () => Cache::get('scheduler:heartbeat'), null, false); // cache may live in the (down) database
        $info = [
            'scheduler' => $beat === null ? 'unknown' : (time() - (int) $beat < 180 ? 'ok' : 'stale'),
            'failedJobs' => rescue(fn () => DB::table('failed_jobs')->count(), null, false),
        ];

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks, 'info' => $info], $ok ? 200 : 503);
    }
}
