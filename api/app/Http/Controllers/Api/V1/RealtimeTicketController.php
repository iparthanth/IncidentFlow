<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mints a short-lived ticket for opening a realtime stream.
 *
 * Why a separate credential at all: `EventSource` cannot set request headers,
 * so a browser SSE client has to put its credential in the query string —
 * where it lands in nginx access logs, browser history and any proxy in
 * between. Handing over the 15-minute API access token for that would be
 * careless. Instead the client exchanges it for a 60-second token with
 * `aud: incidentflow-realtime`, which grants nothing but a read stream and is
 * worthless by the time it reaches a log rotation.
 *
 * The ticket carries the organization and role because the Express service has
 * no database. That is a deliberate, bounded staleness: a role revoked right
 * now remains effective on an open stream for at most the ticket's lifetime.
 */
final class RealtimeTicketController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function __invoke(Request $request, Organization $organization): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $role = $user->roleIn($organization);

        if ($role === null) {
            return response()->json([
                'error' => [
                    'code' => 'organization_not_found',
                    'message' => 'You are not a member of this organization.',
                    'request_id' => $request->headers->get('X-Request-Id'),
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $ticket = $this->tokens->issueRealtimeTicket($user, $organization, $role);

        return response()->json([
            'data' => [
                'ticket' => $ticket->token,
                'expires_in' => $ticket->expiresIn(),
                'expires_at' => now()->setTimestamp($ticket->expiresAt)->toIso8601String(),
                'stream_url' => rtrim((string) config('realtime.public_url'), '/').'/stream',
                // The topics this ticket is entitled to. The client sends them
                // back as a filter; the server-side guarantee is that events
                // for other organizations never reach this connection at all.
                'topics' => ['org:'.$organization->getKey()],
            ],
        ]);
    }
}
