<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class MetricsController extends Controller
{
    /** Longest window a single request may span, in days. */
    private const int MAX_WINDOW_DAYS = 366;

    public function __construct(private readonly MetricsService $metrics) {}

    public function summary(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewMetrics', $organization);

        [$from, $to] = $this->window($request);

        return response()->json([
            'data' => $this->metrics->summary($organization, $from, $to),
        ]);
    }

    public function trends(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewMetrics', $organization);

        [$from, $to] = $this->window($request);

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                'series' => $this->metrics->trends($organization, $from, $to),
            ],
        ]);
    }

    /**
     * Resolves and bounds the reporting window.
     *
     * The cap is not arbitrary politeness: `?from=1970-01-01` would ask the
     * service to bucket fifty years of days and pull every incident the tenant
     * has ever had into memory. Bounding the input is cheaper than defending
     * against it downstream.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_WINDOW_DAYS],
        ]);

        $to = isset($validated['to']) ? Carbon::parse((string) $validated['to'])->endOfDay() : Carbon::now();

        $from = isset($validated['from'])
            ? Carbon::parse((string) $validated['from'])->startOfDay()
            : $to->copy()->subDays((int) ($validated['days'] ?? 30))->startOfDay();

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $from = $to->copy()->subDays(self::MAX_WINDOW_DAYS)->startOfDay();
        }

        return [$from, $to];
    }
}
