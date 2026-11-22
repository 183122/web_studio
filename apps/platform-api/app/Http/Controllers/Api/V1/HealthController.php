<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** Readiness probe: database and cache reachable. */
    public function __invoke(): JsonResponse
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (Throwable) {
            $checks['database'] = 'error';
        }
        try {
            Cache::put('health:probe', '1', 5);
            $checks['cache'] = Cache::get('health:probe') === '1' ? 'ok' : 'error';
        } catch (Throwable) {
            $checks['cache'] = 'error';
        }
        $ok = ! in_array('error', $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }
}
