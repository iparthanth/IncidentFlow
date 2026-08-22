<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Per-request ambient data that almost everything wants and nothing should
 * have to thread through six constructors: the correlation id, the caller's IP,
 * and the user agent.
 *
 * Bound as a singleton and populated by `AssignRequestId`. Queue jobs receive
 * an explicit copy instead of reading this — a worker process has no request,
 * and silently reading a stale id would be worse than having none.
 */
final class RequestContext
{
    private string $requestId;

    private ?string $ipAddress = null;

    private ?string $userAgent = null;

    public function __construct(?string $requestId = null)
    {
        $this->requestId = $requestId ?? (string) Str::uuid();
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setClient(?string $ipAddress, ?string $userAgent): void
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent !== null ? Str::limit($userAgent, 500, '') : null;
    }

    /** @return array<string, string|null> */
    public function toLogContext(): array
    {
        return [
            'request_id' => $this->requestId,
            'ip' => $this->ipAddress,
        ];
    }
}
