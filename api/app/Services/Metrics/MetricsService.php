<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reliability metrics: MTTA, MTTR, throughput and acknowledgement-SLA
 * attainment.
 *
 * Two design notes worth defending:
 *
 * **Averages come from stored durations, not from date arithmetic.** The
 * columns `time_to_acknowledge_seconds` and `time_to_resolve_seconds` are
 * written once, inside the transaction that performed the transition. The
 * aggregate is therefore a plain AVG() that behaves identically on PostgreSQL
 * and on the SQLite the test suite runs against — and it stays correct even if
 * an administrator later corrects a timestamp, because the metric records what
 * the response actually took at the time.
 *
 * **Percentiles and daily buckets are computed in PHP, not SQL.** Neither
 * `percentile_cont` nor `date_trunc` exists in SQLite, and a metrics endpoint
 * that only works on one engine is a metrics endpoint that is never covered by
 * tests. The row set is capped, so the cost is bounded.
 *
 * ponytail: when a tenant's history outgrows the cap, replace this with a
 * nightly rollup table keyed on (organization_id, date, severity) — the shape
 * of the output here is already what such a table would store.
 */
final class MetricsService
{
    /** Ceiling on rows pulled into memory for percentile and bucket maths. */
    private const int MAX_SAMPLE_ROWS = 20_000;

    /**
     * @return array<string, mixed>
     */
    public function summary(Organization $organization, Carbon $from, Carbon $to): array
    {
        $sample = $this->sample($organization, $from, $to);

        $acknowledgeDurations = $sample
            ->pluck('time_to_acknowledge_seconds')
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->map(static fn (mixed $value): int => (int) $value)
            ->values();

        $resolveDurations = $sample
            ->pluck('time_to_resolve_seconds')
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->map(static fn (mixed $value): int => (int) $value)
            ->values();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                // Carbon 3 returns a float here, so a 30-day window reports
                // 30.2587… — a fractional day count is meaningless to a reader
                // and breaks any client that types this as an integer.
                'days' => max(1, (int) round($from->diffInDays($to))),
            ],
            'totals' => [
                'created' => $sample->count(),
                'resolved' => $sample->whereNotNull('resolved_at')->count(),
                'currently_open' => $this->openCount($organization),
                'truncated' => $sample->count() >= self::MAX_SAMPLE_ROWS,
            ],
            'by_status' => $this->countBy($sample, 'status', IncidentStatus::values()),
            'by_severity' => $this->countBy($sample, 'severity', IncidentSeverity::values()),
            'mtta_seconds' => $this->statistics($acknowledgeDurations),
            'mttr_seconds' => $this->statistics($resolveDurations),
            'acknowledgement_sla' => $this->acknowledgementSla($sample),
        ];
    }

    /**
     * Daily counts for the dashboard chart.
     *
     * @return list<array<string, mixed>>
     */
    public function trends(Organization $organization, Carbon $from, Carbon $to): array
    {
        $sample = $this->sample($organization, $from, $to);

        // Seed every day in the range so the chart shows real zeroes rather
        // than skipping quiet days and implying continuous activity.
        $buckets = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $buckets[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'created' => 0,
                'resolved' => 0,
                'resolve_seconds_total' => 0,
                'resolve_samples' => 0,
            ];
        }

        foreach ($sample as $incident) {
            $createdKey = $incident->created_at?->toDateString();
            if ($createdKey !== null && isset($buckets[$createdKey])) {
                $buckets[$createdKey]['created']++;
            }

            $resolvedKey = $incident->resolved_at?->toDateString();
            if ($resolvedKey !== null && isset($buckets[$resolvedKey])) {
                $buckets[$resolvedKey]['resolved']++;

                if ($incident->time_to_resolve_seconds !== null) {
                    $buckets[$resolvedKey]['resolve_seconds_total'] += $incident->time_to_resolve_seconds;
                    $buckets[$resolvedKey]['resolve_samples']++;
                }
            }
        }

        return array_values(array_map(static function (array $bucket): array {
            $mttr = $bucket['resolve_samples'] > 0
                ? (int) round($bucket['resolve_seconds_total'] / $bucket['resolve_samples'])
                : null;

            return [
                'date' => $bucket['date'],
                'created' => $bucket['created'],
                'resolved' => $bucket['resolved'],
                'mttr_seconds' => $mttr,
            ];
        }, $buckets));
    }

    /** @return Collection<int, Incident> */
    private function sample(Organization $organization, Carbon $from, Carbon $to): Collection
    {
        return Incident::query()
            ->forOrganization($organization)
            ->whereBetween('created_at', [$from, $to])
            // Only the columns the maths needs; pulling full rows would be
            // several times the memory for no benefit.
            ->select([
                'id', 'status', 'severity', 'created_at', 'acknowledged_at', 'resolved_at',
                'time_to_acknowledge_seconds', 'time_to_resolve_seconds',
            ])
            ->orderBy('id')
            ->limit(self::MAX_SAMPLE_ROWS)
            ->get();
    }

    private function openCount(Organization $organization): int
    {
        return Incident::query()
            ->forOrganization($organization)
            ->activeOnly()
            ->count();
    }

    /**
     * @param  Collection<int, Incident>  $sample
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function countBy(Collection $sample, string $attribute, array $keys): array
    {
        // Zero-fill: a severity with no incidents is information, and omitting
        // it makes the chart's categories shift between requests.
        $counts = array_fill_keys($keys, 0);

        foreach ($sample as $incident) {
            $value = $incident->{$attribute};
            $key = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, int>  $durations
     * @return array{count: int, average: int|null, p50: int|null, p90: int|null, p95: int|null, max: int|null}
     */
    private function statistics(Collection $durations): array
    {
        if ($durations->isEmpty()) {
            return ['count' => 0, 'average' => null, 'p50' => null, 'p90' => null, 'p95' => null, 'max' => null];
        }

        $sorted = $durations->sort()->values();

        return [
            'count' => $sorted->count(),
            'average' => (int) round($sorted->avg()),
            // The average is the number people quote; the percentiles are the
            // ones that tell you whether it means anything. A 12-minute mean
            // hides a p95 of two hours.
            'p50' => $this->percentile($sorted, 0.50),
            'p90' => $this->percentile($sorted, 0.90),
            'p95' => $this->percentile($sorted, 0.95),
            'max' => (int) $sorted->last(),
        ];
    }

    /** Nearest-rank percentile over an ascending collection. */
    private function percentile(Collection $sorted, float $fraction): int
    {
        $count = $sorted->count();
        $rank = (int) max(1, (int) ceil($fraction * $count));

        return (int) $sorted->get($rank - 1, $sorted->last());
    }

    /**
     * Share of incidents acknowledged inside their severity's target.
     *
     * @param  Collection<int, Incident>  $sample
     * @return array<string, mixed>
     */
    private function acknowledgementSla(Collection $sample): array
    {
        $perSeverity = [];

        foreach (IncidentSeverity::cases() as $severity) {
            $perSeverity[$severity->value] = ['total' => 0, 'within_target' => 0, 'target_minutes' => $severity->acknowledgementTargetMinutes()];
        }

        $total = 0;
        $met = 0;

        foreach ($sample as $incident) {
            if ($incident->time_to_acknowledge_seconds === null) {
                continue;
            }

            $severity = $incident->severity;
            $targetSeconds = $severity->acknowledgementTargetMinutes() * 60;
            $withinTarget = $incident->time_to_acknowledge_seconds <= $targetSeconds;

            $perSeverity[$severity->value]['total']++;
            $total++;

            if ($withinTarget) {
                $perSeverity[$severity->value]['within_target']++;
                $met++;
            }
        }

        foreach ($perSeverity as $key => $row) {
            $perSeverity[$key]['attainment'] = $row['total'] > 0
                ? round($row['within_target'] / $row['total'] * 100, 1)
                : null;
        }

        return [
            'overall_attainment' => $total > 0 ? round($met / $total * 100, 1) : null,
            'acknowledged_incidents' => $total,
            'by_severity' => $perSeverity,
        ];
    }
}
