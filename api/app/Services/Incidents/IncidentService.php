<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Enums\AssigneeRole;
use App\Enums\IncidentEventType;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Exceptions\IncidentTransitionException;
use App\Models\Incident;
use App\Models\IncidentAssignee;
use App\Models\IncidentComment;
use App\Models\IncidentUpdate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Realtime\RealtimeEvent;
use App\Services\Realtime\RealtimePublisher;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every state change an incident can undergo.
 *
 * Controllers do authorization and shape-checking; this class owns the rules.
 * The pattern is identical for every mutation:
 *
 *   1. open a transaction
 *   2. re-read the incident FOR UPDATE, because the copy the controller
 *      resolved was read before the request did its authorization work and may
 *      already be stale
 *   3. apply the change, append exactly one timeline event, write the audit
 *      row, and prepare notification rows — all atomically
 *   4. commit
 *   5. only then publish to Redis and enqueue email
 *
 * Steps 3 and 5 are the two halves of "why database transactions should wrap
 * incident state changes and timeline events": the state and its history must
 * become true together or not at all, while anything the outside world can
 * observe must wait until that truth is durable.
 */
final class IncidentService
{
    public function __construct(
        private readonly TimelineRecorder $timeline,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly RealtimePublisher $publisher,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, impact?: string|null, severity: IncidentSeverity, service_id?: int|null, detected_at?: string|null, source?: string, external_reference?: string|null, commander_id?: int|null, assignee_ids?: list<int>}  $data
     */
    public function create(Organization $organization, User $reporter, array $data): Incident
    {
        return $this->withEffects(function (PendingEffects $effects) use ($organization, $reporter, $data): Incident {
            // Allocates under a row lock; see Organization::allocateIncidentNumber().
            $sequence = $organization->allocateIncidentNumber();

            $incident = new Incident([
                'organization_id' => $organization->getKey(),
                'service_id' => $data['service_id'] ?? null,
                'reference' => Incident::referenceFor($sequence),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'impact' => $data['impact'] ?? null,
                'severity' => $data['severity'],
                'status' => IncidentStatus::Open,
                'reported_by' => $reporter->getKey(),
                'commander_id' => $data['commander_id'] ?? null,
                'detected_at' => $data['detected_at'] ?? null,
                'source' => $data['source'] ?? 'web',
                'external_reference' => $data['external_reference'] ?? null,
            ]);
            $incident->save();

            foreach ($data['assignee_ids'] ?? [] as $assigneeId) {
                $this->attachAssignee($incident, (int) $assigneeId, AssigneeRole::Responder, $reporter);
            }

            $event = $effects->addEvent($this->timeline->record(
                $incident,
                IncidentEventType::Created,
                $reporter,
                [
                    'severity' => $incident->severity->value,
                    'status' => $incident->status->value,
                    'service_id' => $incident->service_id,
                    'title' => $incident->title,
                ],
            ));

            $this->audit->record(
                'incident.created',
                $incident,
                $reporter,
                $organization->getKey(),
                ['after' => $incident->only(['reference', 'title', 'severity', 'status', 'service_id'])],
            );

            $effects->addNotifications(
                $this->notifications->prepare($incident, IncidentEventType::Created, $reporter, [
                    'event_id' => $event->ulid,
                ]),
            );

            return $incident;
        });
    }

    /**
     * Edits the descriptive fields. Severity and status have their own methods
     * because they are transitions with consequences, not mere attribute writes.
     *
     * @param  array{title?: string, description?: string|null, impact?: string|null, service_id?: int|null, detected_at?: string|null, external_reference?: string|null}  $data
     */
    public function update(Incident $incident, User $actor, array $data): Incident
    {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $data): Incident {
            $fresh = $this->lock($incident);
            $this->guardNotTerminal($fresh);

            $fresh->fill($data);

            if (! $fresh->isDirty()) {
                return $fresh;
            }

            $changed = array_keys($fresh->getDirty());
            $fresh->save();

            $effects->addEvent($this->timeline->record(
                $fresh,
                IncidentEventType::Updated,
                $actor,
                ['fields' => $changed],
            ));

            $this->audit->recordModelUpdate('incident.updated', $fresh, $actor, $fresh->organization_id);

            return $fresh;
        });
    }

    /**
     * Moves the incident through its lifecycle.
     *
     * Timestamps are written here and nowhere else, which is what keeps MTTA
     * and MTTR trustworthy — there is exactly one code path that can decide an
     * incident became acknowledged or resolved.
     */
    public function transition(
        Incident $incident,
        User $actor,
        IncidentStatus $to,
        ?string $note = null,
        bool $publicUpdate = false,
    ): Incident {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $to, $note, $publicUpdate): Incident {
            $fresh = $this->lock($incident);
            $from = $fresh->status;

            if ($from === $to) {
                throw IncidentTransitionException::alreadyInStatus($from);
            }

            if ($from->isTerminal()) {
                throw IncidentTransitionException::terminal($from);
            }

            if (! $from->canTransitionTo($to)) {
                throw IncidentTransitionException::illegal($from, $to);
            }

            $now = Carbon::now();
            $reopening = $to->isReopeningFrom($from);

            $fresh->status = $to;
            $this->applyTransitionTimestamps($fresh, $to, $now, $reopening);
            $fresh->save();

            $eventType = $reopening
                ? IncidentEventType::Reopened
                : (IncidentEventType::forStatus($to) ?? IncidentEventType::StatusChanged);

            $payload = [
                'from' => $from->value,
                'to' => $to->value,
                'note' => $note,
                'time_to_acknowledge_seconds' => $fresh->time_to_acknowledge_seconds,
                'time_to_resolve_seconds' => $fresh->time_to_resolve_seconds,
            ];

            $event = $effects->addEvent($this->timeline->record($fresh, $eventType, $actor, $payload, $now));

            // A note attached to a transition is also a narrative update, so it
            // shows up in the communications log and not only on the timeline.
            if ($note !== null && trim($note) !== '') {
                IncidentUpdate::query()->create([
                    'incident_id' => $fresh->getKey(),
                    'user_id' => $actor->getKey(),
                    'previous_status' => $from,
                    'status' => $to,
                    'message' => $note,
                    'is_public' => $publicUpdate,
                ]);
            }

            $this->audit->recordModelUpdate('incident.status_changed', $fresh, $actor, $fresh->organization_id);

            $effects->addNotifications(
                $this->notifications->prepare($fresh, $eventType, $actor, [
                    ...$payload,
                    'event_id' => $event->ulid,
                ]),
            );

            return $fresh;
        });
    }

    public function changeSeverity(Incident $incident, User $actor, IncidentSeverity $to, ?string $reason = null): Incident
    {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $to, $reason): Incident {
            $fresh = $this->lock($incident);
            $this->guardNotTerminal($fresh);

            $from = $fresh->severity;
            if ($from === $to) {
                return $fresh;
            }

            $fresh->severity = $to;
            $fresh->save();

            $payload = [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
                'escalated' => $to->weight() < $from->weight(),
            ];

            $event = $effects->addEvent(
                $this->timeline->record($fresh, IncidentEventType::SeverityChanged, $actor, $payload),
            );

            $this->audit->recordModelUpdate('incident.severity_changed', $fresh, $actor, $fresh->organization_id);

            $effects->addNotifications(
                $this->notifications->prepare($fresh, IncidentEventType::SeverityChanged, $actor, [
                    ...$payload,
                    'event_id' => $event->ulid,
                ]),
            );

            return $fresh;
        });
    }

    public function assign(Incident $incident, User $actor, User $assignee, AssigneeRole $role = AssigneeRole::Responder): IncidentAssignee
    {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $assignee, $role): IncidentAssignee {
            $fresh = $this->lock($incident);
            $this->guardNotTerminal($fresh);

            $assignment = $this->attachAssignee($fresh, $assignee->getKey(), $role, $actor);

            $payload = [
                'assignee_id' => $assignee->getKey(),
                'assignee_name' => $assignee->name,
                'assignment_role' => $role->value,
            ];

            $event = $effects->addEvent(
                $this->timeline->record($fresh, IncidentEventType::Assigned, $actor, $payload),
            );

            $this->audit->record('incident.assigned', $fresh, $actor, $fresh->organization_id, ['after' => $payload]);

            $effects->addNotifications(
                $this->notifications->prepare($fresh, IncidentEventType::Assigned, $actor, [
                    ...$payload,
                    'event_id' => $event->ulid,
                ]),
            );

            return $assignment;
        });
    }

    public function unassign(Incident $incident, User $actor, User $assignee): void
    {
        $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $assignee): void {
            $fresh = $this->lock($incident);

            $deleted = IncidentAssignee::query()
                ->where('incident_id', $fresh->getKey())
                ->where('user_id', $assignee->getKey())
                ->delete();

            if ($deleted === 0) {
                return;
            }

            $payload = [
                'assignee_id' => $assignee->getKey(),
                'assignee_name' => $assignee->name,
            ];

            $effects->addEvent(
                $this->timeline->record($fresh, IncidentEventType::Unassigned, $actor, $payload),
            );

            $this->audit->record('incident.unassigned', $fresh, $actor, $fresh->organization_id, ['before' => $payload]);
        });
    }

    public function setCommander(Incident $incident, User $actor, ?User $commander): Incident
    {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $commander): Incident {
            $fresh = $this->lock($incident);
            $this->guardNotTerminal($fresh);

            if ($fresh->commander_id === $commander?->getKey()) {
                return $fresh;
            }

            $fresh->commander_id = $commander?->getKey();
            $fresh->save();

            // The commander is automatically on the roster: being in charge of
            // an incident you are not assigned to is a contradiction.
            if ($commander !== null) {
                $this->attachAssignee($fresh, $commander->getKey(), AssigneeRole::Responder, $actor);
            }

            $effects->addEvent($this->timeline->record($fresh, IncidentEventType::CommanderChanged, $actor, [
                'commander_id' => $commander?->getKey(),
                'commander_name' => $commander?->name,
            ]));

            $this->audit->recordModelUpdate('incident.commander_changed', $fresh, $actor, $fresh->organization_id);

            return $fresh;
        });
    }

    public function postUpdate(
        Incident $incident,
        User $actor,
        string $message,
        bool $isPublic = false,
    ): IncidentUpdate {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $message, $isPublic): IncidentUpdate {
            $fresh = $this->lock($incident);

            $update = IncidentUpdate::query()->create([
                'incident_id' => $fresh->getKey(),
                'user_id' => $actor->getKey(),
                'previous_status' => $fresh->status,
                'status' => $fresh->status,
                'message' => $message,
                'is_public' => $isPublic,
            ]);

            $effects->addEvent($this->timeline->record($fresh, IncidentEventType::UpdatePosted, $actor, [
                'update_id' => $update->getKey(),
                'is_public' => $isPublic,
                'excerpt' => mb_strimwidth($message, 0, 200, '…'),
            ]));

            $this->audit->record('incident.update_posted', $fresh, $actor, $fresh->organization_id);

            return $update;
        });
    }

    public function comment(Incident $incident, User $actor, string $body): IncidentComment
    {
        return $this->withEffects(function (PendingEffects $effects) use ($incident, $actor, $body): IncidentComment {
            $comment = IncidentComment::query()->create([
                'incident_id' => $incident->getKey(),
                'user_id' => $actor->getKey(),
                'body' => $body,
            ]);

            $effects->addEvent($this->timeline->record($incident, IncidentEventType::Commented, $actor, [
                'comment_id' => $comment->getKey(),
                'excerpt' => mb_strimwidth($body, 0, 200, '…'),
            ]));

            return $comment;
        });
    }

    /** Soft delete. The timeline and audit trail survive, as they must. */
    public function delete(Incident $incident, User $actor): void
    {
        $this->withEffects(function (PendingEffects $effects) use ($incident, $actor): void {
            $fresh = $this->lock($incident);

            $effects->addEvent($this->timeline->record($fresh, IncidentEventType::Deleted, $actor, [
                'reference' => $fresh->reference,
            ]));

            $this->audit->record('incident.deleted', $fresh, $actor, $fresh->organization_id, [
                'before' => $fresh->only(['reference', 'title', 'status', 'severity']),
            ]);

            $fresh->delete();
        });
    }

    // --------------------------------------------------------------- private

    /**
     * Runs the closure in a transaction, then flushes the effects it collected.
     *
     * @template TResult
     *
     * @param  Closure(PendingEffects): TResult  $work
     * @return TResult
     */
    private function withEffects(Closure $work): mixed
    {
        $effects = new PendingEffects;

        $result = DB::transaction(static fn (): mixed => $work($effects));

        // Past this line the write is durable, so it is safe to tell the world.
        foreach ($effects->timelineEvents as $event) {
            $this->publisher->publish(RealtimeEvent::fromTimelineEvent($event));
        }

        $this->notifications->dispatch($effects->notifications);

        return $result;
    }

    /**
     * Re-reads the incident with a row lock.
     *
     * Without this, two responders resolving the same incident simultaneously
     * would both read status=acknowledged, both pass the transition check, and
     * both write a "resolved" event — one incident, two resolutions, and an
     * MTTR computed from whichever write landed last.
     */
    private function lock(Incident $incident): Incident
    {
        /** @var Incident $locked */
        $locked = Incident::query()->whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function guardNotTerminal(Incident $incident): void
    {
        if ($incident->status->isTerminal()) {
            throw IncidentTransitionException::terminal($incident->status);
        }
    }

    private function attachAssignee(Incident $incident, int $userId, AssigneeRole $role, User $actor): IncidentAssignee
    {
        /** @var IncidentAssignee $assignment */
        $assignment = IncidentAssignee::query()->updateOrCreate(
            ['incident_id' => $incident->getKey(), 'user_id' => $userId],
            ['role' => $role, 'assigned_by' => $actor->getKey(), 'assigned_at' => Carbon::now()],
        );

        return $assignment;
    }

    /**
     * The only place incident clocks are written.
     *
     * `acknowledged_at` is never overwritten: time-to-acknowledge measures the
     * first human response, and an incident that is acknowledged twice was not
     * acknowledged faster the second time. `resolved_at` *is* cleared on
     * reopen, because an incident that came back was not resolved at all — and
     * leaving a stale resolution time would quietly flatter MTTR.
     */
    private function applyTransitionTimestamps(Incident $incident, IncidentStatus $to, Carbon $now, bool $reopening): void
    {
        if ($reopening) {
            $incident->resolved_at = null;
            $incident->time_to_resolve_seconds = null;
            $incident->mitigated_at = null;
        }

        match ($to) {
            IncidentStatus::Acknowledged => $this->recordAcknowledgement($incident, $now),
            IncidentStatus::Mitigated => $incident->mitigated_at ??= $now,
            IncidentStatus::Resolved => $this->recordResolution($incident, $now),
            IncidentStatus::Closed => $incident->closed_at ??= $now,
            IncidentStatus::Open => null,
        };
    }

    private function recordAcknowledgement(Incident $incident, Carbon $now): void
    {
        if ($incident->acknowledged_at !== null) {
            return;
        }

        $incident->acknowledged_at = $now;
        $incident->time_to_acknowledge_seconds = $incident->elapsedSecondsUntil($now);
    }

    private function recordResolution(Incident $incident, Carbon $now): void
    {
        $incident->resolved_at = $now;
        $incident->time_to_resolve_seconds = $incident->elapsedSecondsUntil($now);

        // Resolving without an explicit acknowledgement still implies someone
        // responded; leaving TTA null would drop these incidents out of the
        // MTTA average entirely and make the number look better than reality.
        if ($incident->acknowledged_at === null) {
            $incident->acknowledged_at = $now;
            $incident->time_to_acknowledge_seconds = $incident->time_to_resolve_seconds;
        }
    }
}
