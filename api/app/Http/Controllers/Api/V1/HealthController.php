<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Liveness and readiness, kept deliberately distinct.
 *
 * **Liveness** answers "is this process wedged?" and touches nothing. If it
 * checked PostgreSQL, a database outage would make the orchestrator kill and
 * restart every API container — turning a recoverable dependency failure into
 * a crash loop that guarantees a full outage.
 *
 * **Readiness** answers "should this instance receive traffic?" and does check
 * dependencies, because an instance that cannot reach its database should be
 * taken out of rotation rather than serve 500s.
 *
 * Redis is reported but does *not* fail readiness: the API degrades to a
 * polling frontend without it, and refusing all traffic because live updates
 * are unavailable would be a self-inflicted outage — a particularly poor
 * outcome for the tool people open when something is already broken.
 */
final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'incidentflow-api',
            'version' => config('app.version', 'dev'),
            'time' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::connection()->select('select 1')),
            'cache' => $this->check(static function (): void {
                cache()->put('health:probe', '1', 5);
                cache()->get('health:probe');
            }),
            'redis' => $this->check(static fn () => Redis::connection(config('realtime.connection'))->ping()),
        ];

        $critical = ['database', 'cache'];
        $ready = collect($critical)->every(fn (string $key): bool => $checks[$key]['ok']);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
            'degraded' => ! $checks['redis']['ok'],
            'time' => now()->toIso8601String(),
        ], $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /** @return array{ok: bool, latency_ms: float, error: string|null} */
    private function check(callable $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $probe();
            $error = null;
            $ok = true;
        } catch (Throwable $e) {
            // The message goes to the operator running the probe, never to an
            // anonymous caller — this endpoint is not exposed publicly.
            $error = $e->getMessage();
            $ok = false;
        }

        return [
            'ok' => $ok,
            'latency_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'error' => $error,
        ];
    }
}
