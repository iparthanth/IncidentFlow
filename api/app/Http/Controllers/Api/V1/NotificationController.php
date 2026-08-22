<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in user's own notification inbox.
 *
 * No policy is involved and none should be: authorization here is the query
 * itself. Every read and write is scoped to `user_id = the caller`, so there is
 * no code path that could return someone else's notifications for a policy to
 * have to catch.
 */
final class NotificationController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'unread_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $query = Notification::query()
            ->forUser((int) $user->getKey())
            ->forOrganization($organization);

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        return NotificationResource::collection(
            $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25))->withQueryString(),
        );
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 404 rather than 403: the caller should not learn that a notification
        // with this id exists for somebody else.
        abort_if($notification->user_id !== $user->getKey(), Response::HTTP_NOT_FOUND);

        $notification->markRead();

        return response()->json(['data' => new NotificationResource($notification)]);
    }

    public function markAllRead(Request $request, Organization $organization): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = Notification::query()
            ->forUser((int) $user->getKey())
            ->forOrganization($organization)
            ->unread()
            ->update([
                'read_at' => now(),
                'status' => NotificationStatus::Read->value,
                'updated_at' => now(),
            ]);

        return response()->json(['data' => ['marked_read' => $updated]]);
    }
}
