<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncidentEventType;
use App\Enums\PostmortemStatus;
use App\Exceptions\PostmortemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostmortemResource;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Postmortem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Incidents\TimelineRecorder;
use App\Services\Realtime\RealtimeEvent;
use App\Services\Realtime\RealtimePublisher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PostmortemController extends Controller
{
    public function __construct(
        private readonly TimelineRecorder $timeline,
        private readonly AuditLogger $audit,
        private readonly RealtimePublisher $publisher,
    ) {}

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Postmortem::class);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(PostmortemStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Postmortem::query()
            ->forOrganization($organization)
            ->with(['author', 'incident']);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return PostmortemResource::collection(
            $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25))->withQueryString(),
        );
    }

    public function show(Incident $incident): PostmortemResource
    {
        Gate::authorize('view', $incident);

        $postmortem = $incident->postmortem()->with('author')->firstOrFail();

        Gate::authorize('view', $postmortem);

        return new PostmortemResource($postmortem);
    }

    /**
     * Create-or-replace, because a postmortem is one document per incident and
     * the editor saves the whole thing. PUT rather than POST/PATCH makes the
     * idempotency explicit: saving the same draft twice is one document.
     */
    public function upsert(Request $request, Incident $incident, Organization $organization): PostmortemResource
    {
        Gate::authorize('view', $incident);

        $existing = $incident->postmortem;

        if ($existing !== null) {
            Gate::authorize('update', $existing);
        } else {
            Gate::authorize('create', Postmortem::class);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:5', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'contributing_factors' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'impact' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'detection_notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'lessons_learned' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'action_items' => ['sometimes', 'array', 'max:50'],
            'action_items.*.title' => ['required', 'string', 'max:200'],
            'action_items.*.owner_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->getKey()),
            ],
            'action_items.*.due_on' => ['sometimes', 'nullable', 'date'],
            'action_items.*.status' => ['sometimes', Rule::in(['open', 'in_progress', 'done'])],
            'status' => ['sometimes', Rule::in([PostmortemStatus::Draft->value, PostmortemStatus::InReview->value])],
        ]);

        /** @var User $user */
        $user = $request->user();

        $postmortem = DB::transaction(function () use ($incident, $organization, $user, $validated, $existing): Postmortem {
            $isNew = $existing === null;

            $postmortem = $existing ?? new Postmortem([
                'incident_id' => $incident->getKey(),
                'organization_id' => $organization->getKey(),
                'author_id' => $user->getKey(),
                'title' => 'Postmortem: '.$incident->title,
                'status' => PostmortemStatus::Draft,
            ]);

            $postmortem->fill($validated);
            $postmortem->organization_id = $organization->getKey();
            $postmortem->incident_id = $incident->getKey();

            $dirty = $postmortem->isDirty();
            $postmortem->save();

            if ($isNew) {
                $this->timeline->record($incident, IncidentEventType::PostmortemDrafted, $user, [
                    'postmortem_id' => $postmortem->getKey(),
                ]);
                $this->audit->record('postmortem.created', $postmortem, $user, $organization->getKey());
            } elseif ($dirty) {
                $this->audit->recordModelUpdate('postmortem.updated', $postmortem, $user, $organization->getKey());
            }

            return $postmortem;
        });

        return new PostmortemResource($postmortem->load('author'));
    }

    /**
     * Publishing is gated on content as well as on role.
     *
     * A postmortem with no root cause is a document that records that an
     * incident happened and teaches nobody anything. Refusing to publish it is
     * the one moment the system can insist the work was actually done.
     */
    public function publish(Request $request, Incident $incident, Organization $organization): PostmortemResource
    {
        $postmortem = $incident->postmortem()->firstOrFail();

        Gate::authorize('publish', $postmortem);

        if ($postmortem->isPublished()) {
            return new PostmortemResource($postmortem->load('author'));
        }

        $missing = $postmortem->missingRequiredSections();
        if ($missing !== []) {
            throw PostmortemException::incomplete($missing);
        }

        /** @var User $user */
        $user = $request->user();

        $event = DB::transaction(function () use ($postmortem, $incident, $user, $organization) {
            $postmortem->forceFill([
                'status' => PostmortemStatus::Published,
                'published_at' => now(),
            ])->save();

            $this->audit->record('postmortem.published', $postmortem, $user, $organization->getKey());

            return $this->timeline->record($incident, IncidentEventType::PostmortemPublished, $user, [
                'postmortem_id' => $postmortem->getKey(),
            ]);
        });

        // Same discipline as IncidentService: nothing observable escapes before
        // the write is durable, so the publish happens after the closure returns.
        $this->publisher->publish(RealtimeEvent::fromTimelineEvent($event));

        return new PostmortemResource($postmortem->fresh()?->load('author') ?? $postmortem);
    }
}
