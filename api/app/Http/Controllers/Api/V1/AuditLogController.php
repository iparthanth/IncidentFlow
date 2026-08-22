<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only access to the audit trail, for administrators.
 *
 * There is no store, update or destroy here by design. The table is written
 * only by `AuditLogger` inside the transactions it describes, and the model
 * refuses updates and deletes outright — an audit log that an administrator can
 * edit is an audit log that proves nothing about administrators.
 *
 * Cursor pagination because this table only grows, and offset pagination over
 * a growing table skips and repeats rows between pages.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAudit', $organization);

        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'max:64'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'auditable_type' => ['sometimes', 'string', 'max:64'],
            'auditable_id' => ['sometimes', 'integer', 'min:1'],
            'request_id' => ['sometimes', 'string', 'max:64'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $query = AuditLog::query()
            ->forOrganization($organization)
            ->with('actor');

        foreach (['action', 'actor_id', 'auditable_type', 'auditable_id', 'request_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if (isset($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        return AuditLogResource::collection(
            $query->orderByDesc('id')
                ->cursorPaginate((int) ($validated['per_page'] ?? 50))
                ->withQueryString(),
        );
    }
}
