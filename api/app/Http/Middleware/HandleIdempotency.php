<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\IdempotencyConflictException;
use App\Models\IdempotencyKey;
use App\Models\Organization;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes unsafe POSTs safe to retry.
 *
 * The problem this solves is mundane and constant: a responder taps "Report
 * incident" on a phone with two bars of signal, the request succeeds, the
 * response is lost, the client retries — and now there are two SEV-1s for one
 * outage, two pages, and two people fighting the same fire from different
 * records.
 *
 * The mechanism, in order:
 *
 *   1. INSERT the key as `in_progress` *before* running the handler. This is
 *      the concurrency control. Two simultaneous retries race on a unique
 *      index and the database picks a winner — no read-then-write window for
 *      them to interleave in, which a "check if it exists first" approach
 *      would leave wide open.
 *   2. Compare a hash of the body. Reusing a key with different content is a
 *      client bug, and returning the *first* request's resource for it would
 *      hide that bug behind a plausible-looking success.
 *   3. Store the response, so a replay returns byte-identical output rather
 *      than re-running the work.
 *   4. Delete the key if the handler failed, so a genuine retry after a 500
 *      is allowed to succeed.
 */
final class HandleIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $key = trim((string) $request->headers->get(self::HEADER, ''));

        if ($key === '') {
            if ($mode === 'required') {
                $this->reject(
                    'idempotency.key_required',
                    'This endpoint requires an Idempotency-Key header (a UUID your client generates per attempt).',
                    Response::HTTP_BAD_REQUEST,
                    $request,
                );
            }

            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            $this->reject(
                'idempotency.key_too_long',
                'Idempotency-Key must be 255 characters or fewer.',
                Response::HTTP_BAD_REQUEST,
                $request,
            );
        }

        $endpoint = $request->method().' '.$request->route()?->uri();
        $hash = IdempotencyKey::hashPayload($request->all());
        $user = $request->user();

        $record = $this->claim($key, $endpoint, $hash, $user?->getKey(), $this->organizationId());

        if ($record === null) {
            // Someone else holds the key. Decide whether to replay or refuse.
            return $this->handleExisting($key, $endpoint, $hash, $user?->getKey());
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            // The attempt failed; release the key so the client may retry it.
            $record->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->complete($record, $response);
        } else {
            $record->delete();
        }

        $response->headers->set('Idempotency-Key', $key);

        return $response;
    }

    /**
     * Attempts to take ownership of the key. Returns null if it is already held.
     */
    private function claim(string $key, string $endpoint, string $hash, ?int $userId, ?int $organizationId): ?IdempotencyKey
    {
        try {
            // Wrapped in a transaction so the insert runs inside a SAVEPOINT
            // whenever there is already an open transaction.
            //
            // This is not defensive tidiness — it is required for correctness on
            // PostgreSQL. A statement that errors inside a transaction puts the
            // whole transaction into an aborted state (SQLSTATE 25P02), and
            // every subsequent statement is refused until it is rolled back. The
            // unique violation below is *expected* here, so without a savepoint
            // to roll back to, the very next query — the SELECT in
            // handleExisting() that finds the original response — fails, and a
            // duplicate submission returns 500 instead of replaying the original.
            //
            // SQLite has no such rule, which is exactly why this survived the
            // local suite and only surfaced on the PostgreSQL CI run.
            return DB::transaction(fn (): IdempotencyKey => IdempotencyKey::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                'expires_at' => now()->addHours(24),
            ]));
        } catch (QueryException) {
            // Unique violation is the expected, load-bearing outcome here —
            // it means a concurrent or earlier attempt already claimed the key.
            return null;
        }
    }

    private function handleExisting(string $key, string $endpoint, string $hash, ?int $userId): Response
    {
        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('endpoint', $endpoint)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            // The row vanished between the failed insert and this read (an
            // expired-key sweep, most likely). Nothing to replay.
            throw IdempotencyConflictException::inFlight($key);
        }

        if (! hash_equals($existing->request_hash, $hash)) {
            throw IdempotencyConflictException::payloadMismatch($key);
        }

        if (! $existing->isCompleted()) {
            throw IdempotencyConflictException::inFlight($key);
        }

        return new JsonResponse(
            $existing->response_body ?? [],
            $existing->response_status ?? Response::HTTP_OK,
            [
                'Idempotency-Key' => $key,
                // Lets clients and tests tell a replay from fresh work.
                'Idempotent-Replayed' => 'true',
            ],
        );
    }

    private function complete(IdempotencyKey $record, Response $response): void
    {
        $body = null;
        $content = $response->getContent();

        if (is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);
            $body = is_array($decoded) ? $decoded : null;
        }

        $record->forceFill([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => $body,
        ])->save();
    }

    private function organizationId(): ?int
    {
        return app()->bound(Organization::class)
            ? (int) app(Organization::class)->getKey()
            : null;
    }

    private function reject(string $code, string $message, int $status, Request $request): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $request->headers->get(AssignRequestId::HEADER),
            ],
        ], $status));
    }
}
