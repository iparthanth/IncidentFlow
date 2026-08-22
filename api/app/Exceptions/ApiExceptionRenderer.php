<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One error shape for the entire API.
 *
 *   { "error": { "code", "message", "details", "request_id" } }
 *
 * Central error handling is not about tidiness. It is about two properties
 * that are impossible to maintain if each controller formats its own failures:
 *
 *  - the frontend has exactly one parser, and switches on a stable `code`
 *    rather than on prose that changes when someone rewords a message;
 *  - server faults never leak internals. In production a 500 says
 *    "unexpected error" and carries a request id; the class name, the file and
 *    the stack trace go to the log where the on-call engineer can find them by
 *    that same id.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        /**
         * `HttpResponseException` already carries the exact response its
         * thrower wanted (middleware use it to short-circuit with a 401 or a
         * 409). Rewriting it here would turn every one of those into a 500 —
         * so it is handed back to Laravel untouched.
         */
        if ($e instanceof HttpResponseException) {
            return null;
        }

        // Non-API routes (the Horizon dashboard) keep Laravel's own rendering.
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $code, $message, $details] = $this->classify($e, $request);

        $payload = [
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $request->headers->get(AssignRequestId::HEADER),
            ], static fn (mixed $value): bool => $value !== null),
        ];

        // Debug builds attach the trace; production never does.
        if (config('app.debug') && $status >= 500) {
            $payload['error']['debug'] = [
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => collect($e->getTrace())->take(10)->map(
                    static fn (array $frame): string => ($frame['file'] ?? '?').':'.($frame['line'] ?? '?')
                )->all(),
            ];
        }

        return new JsonResponse($payload, $status);
    }

    /** @return array{int, string, string, array<string, mixed>|null} */
    private function classify(Throwable $e, Request $request): array
    {
        return match (true) {
            $e instanceof DomainException => [
                $e->status,
                $e->errorCode,
                $e->getMessage(),
                $e->details === [] ? null : $e->details,
            ],

            $e instanceof ValidationException => [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validation_failed',
                'The submitted data failed validation.',
                ['fields' => $e->errors()],
            ],

            $e instanceof AuthenticationException => [
                Response::HTTP_UNAUTHORIZED,
                'unauthenticated',
                'Authentication is required to access this resource.',
                null,
            ],

            $e instanceof InvalidTokenException => [
                Response::HTTP_UNAUTHORIZED,
                'token_'.$e->reason,
                $e->publicMessage(),
                null,
            ],

            $e instanceof AuthorizationException => [
                Response::HTTP_FORBIDDEN,
                'forbidden',
                $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
                    ? $e->getMessage()
                    : 'You do not have permission to perform this action.',
                null,
            ],

            // Route-model binding failures must not confirm that an id exists
            // in another tenant, so they are indistinguishable from a bad id.
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [
                Response::HTTP_NOT_FOUND,
                'not_found',
                'The requested resource does not exist.',
                null,
            ],

            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $this->codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : $this->messageForStatus($e->getStatusCode()),
                null,
            ],

            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'internal_error',
                config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred. Quote the request id when reporting this.',
                null,
            ],
        };
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'service_unavailable',
            default => 'http_error',
        };
    }

    private function messageForStatus(int $status): string
    {
        return match ($status) {
            405 => 'That HTTP method is not supported on this endpoint.',
            429 => 'Too many requests. Slow down and retry after the period in the Retry-After header.',
            503 => 'The service is temporarily unavailable.',
            default => 'The request could not be completed.',
        };
    }
}
