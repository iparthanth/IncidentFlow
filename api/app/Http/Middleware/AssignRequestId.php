<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the correlation id for the request and pins it to every log line.
 *
 * One id follows a user action across four processes: nginx mints it, Laravel
 * logs under it, it travels inside the Redis event envelope, and the realtime
 * node echoes it back. When something goes wrong at 3am the difference between
 * `grep <uuid>` and reconstructing a story from timestamps across three log
 * streams is most of the incident.
 *
 * Client-supplied ids are accepted (so a caller can correlate on their side)
 * but sanitised: an unvalidated header lands in log files, and log files are
 * read by tools that can be attacked with control characters.
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    private const int MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        $context = app(RequestContext::class);
        $context->setRequestId($requestId);
        $context->setClient($request->ip(), $request->userAgent());

        $request->headers->set(self::HEADER, $requestId);

        // Every log entry for the rest of this request carries the id, with no
        // call site having to remember to add it.
        Log::shareContext([
            'request_id' => $requestId,
            'ip' => $request->ip(),
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $supplied = $request->headers->get(self::HEADER);

        if (is_string($supplied)) {
            $clean = preg_replace('/[^A-Za-z0-9._:-]/', '', $supplied) ?? '';
            if ($clean !== '') {
                return substr($clean, 0, self::MAX_LENGTH);
            }
        }

        return (string) Str::uuid();
    }
}
