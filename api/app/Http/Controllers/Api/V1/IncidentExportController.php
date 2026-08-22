<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\IndexIncidentsRequest;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the filtered incident list as CSV.
 *
 * Two things make this production-grade rather than a one-liner:
 *
 * **It streams.** `chunkById` walks the result set in batches and each row is
 * flushed as it is produced, so peak memory is a few hundred rows regardless of
 * whether the tenant exports fifty incidents or fifty thousand. Building the
 * whole file in a string first is how an export endpoint becomes the thing that
 * exhausts a container's memory limit.
 *
 * **It defends against CSV injection.** A title of `=cmd|'/c calc'!A1` is
 * inert in this database and inert in the API's JSON — and then Excel executes
 * it when someone opens the export. The escaping below is the control for that:
 * it is a spreadsheet vulnerability, but this endpoint is where it is
 * introduced, so this is where it has to be stopped.
 */
final class IncidentExportController extends Controller
{
    /** Leading characters a spreadsheet treats as the start of a formula. */
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(IndexIncidentsRequest $request, Organization $organization): StreamedResponse
    {
        Gate::authorize('export', Incident::class);

        /** @var User $user */
        $user = $request->user();

        $query = Incident::query()
            ->forOrganization($organization)
            ->with(['service', 'reporter', 'commander'])
            ->whereStatusIn($request->statuses())
            ->whereSeverityIn($request->severities());

        // Must mirror IncidentController::index() exactly. An export that
        // quietly drops a filter the user can see on screen hands them a
        // spreadsheet that disagrees with the page it came from — and they
        // will trust the spreadsheet.
        if ($request->boolean('active_only')) {
            $query->activeOnly();
        }

        if (($serviceId = $request->validated('service_id')) !== null) {
            $query->where('service_id', (int) $serviceId);
        }

        if (($assigneeId = $request->validated('assignee_id')) !== null) {
            $query->assignedTo((int) $assigneeId);
        }

        if (($commanderId = $request->validated('commander_id')) !== null) {
            $query->where('commander_id', (int) $commanderId);
        }

        if (($term = $request->validated('q')) !== null && $term !== '') {
            $query->search((string) $term);
        }

        if (($from = $request->validated('from')) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $request->validated('to')) !== null) {
            $query->where('created_at', '<=', $to);
        }

        // Exports are a data-egress event; the audit trail should show who
        // pulled what, and when.
        $this->audit->record('incident.exported', null, $user, $organization->getKey(), [
            'after' => ['filters' => $request->validated()],
        ]);

        $filename = sprintf('incidents-%s-%s.csv', $organization->slug, now()->format('Ymd-His'));
        $maxRows = (int) config('incidents.export.max_rows');
        $chunkSize = (int) config('incidents.export.chunk_size');

        return response()->streamDownload(function () use ($query, $maxRows, $chunkSize): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM: without it Excel on Windows renders non-ASCII names
            // as mojibake, which makes the export look broken to the exact
            // audience most likely to open it in Excel.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Reference', 'Title', 'Severity', 'Status', 'Service', 'Reporter', 'Commander',
                'Created At', 'Acknowledged At', 'Resolved At', 'Closed At',
                'Time To Acknowledge (min)', 'Time To Resolve (min)', 'Source', 'External Reference',
            ]);

            $written = 0;

            $query->chunkById($chunkSize, function ($incidents) use ($handle, &$written, $maxRows): bool {
                foreach ($incidents as $incident) {
                    if ($written >= $maxRows) {
                        return false; // stop chunking
                    }

                    fputcsv($handle, array_map([$this, 'escapeCell'], [
                        $incident->reference,
                        $incident->title,
                        strtoupper($incident->severity->value),
                        $incident->status->value,
                        $incident->service?->name ?? '',
                        $incident->reporter?->name ?? '',
                        $incident->commander?->name ?? '',
                        $incident->created_at?->toIso8601String() ?? '',
                        $incident->acknowledged_at?->toIso8601String() ?? '',
                        $incident->resolved_at?->toIso8601String() ?? '',
                        $incident->closed_at?->toIso8601String() ?? '',
                        $incident->time_to_acknowledge_seconds !== null
                            ? round($incident->time_to_acknowledge_seconds / 60, 1)
                            : '',
                        $incident->time_to_resolve_seconds !== null
                            ? round($incident->time_to_resolve_seconds / 60, 1)
                            : '',
                        $incident->source,
                        $incident->external_reference ?? '',
                    ]));

                    $written++;
                }

                // Push each chunk to the client instead of buffering the file.
                flush();

                return true;
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            // Tells nginx not to buffer the whole download before sending it.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Neutralises spreadsheet formula injection.
     *
     * Prefixing with an apostrophe forces Excel, LibreOffice and Google Sheets
     * to treat the cell as text. The apostrophe is not displayed, so a title
     * that legitimately starts with "-" still reads correctly.
     */
    private function escapeCell(mixed $value): string
    {
        $string = (string) $value;

        if ($string === '') {
            return $string;
        }

        return in_array($string[0], self::FORMULA_TRIGGERS, strict: true)
            ? "'".$string
            : $string;
    }
}
