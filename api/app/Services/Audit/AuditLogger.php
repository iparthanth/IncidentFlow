<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes the permanent record of who changed what.
 *
 * Always called *inside* the same transaction as the change it describes. That
 * is the whole point: an audit row that can exist without its change — or a
 * change that can exist without its audit row — makes the log evidence of
 * nothing.
 */
final class AuditLogger
{
    /**
     * Values that must never reach the audit table. A log that leaks the very
     * secrets it is meant to protect the handling of is a liability, not a control.
     *
     * @var list<string>
     */
    private const array REDACTED = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'token_hash',
        'secret',
        'api_key',
        'private_key',
    ];

    public function __construct(private readonly RequestContext $context) {}

    /**
     * @param  array{before?: array<string, mixed>, after?: array<string, mixed>}  $changes
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?User $actor = null,
        ?int $organizationId = null,
        array $changes = [],
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organizationId,
            'actor_id' => $actor?->getKey(),
            // Snapshot: the log must still name a person after the account is deleted.
            'actor_email' => $actor?->email,
            'action' => $action,
            'auditable_type' => $subject !== null ? $this->morphAlias($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : $this->redact($changes),
            'ip_address' => $this->context->ipAddress(),
            'user_agent' => $this->context->userAgent(),
            'request_id' => $this->context->requestId(),
        ]);
    }

    /**
     * Records an update using Eloquent's own dirty tracking, so the log always
     * reflects what actually changed rather than what the caller thought it
     * was changing.
     */
    public function recordModelUpdate(
        string $action,
        Model $model,
        ?User $actor = null,
        ?int $organizationId = null,
    ): AuditLog {
        $after = $model->getChanges();
        $before = array_intersect_key($model->getOriginal(), $after);

        return $this->record($action, $model, $actor, $organizationId, [
            'before' => $this->stringifyValues($before),
            'after' => $this->stringifyValues($after),
        ]);
    }

    /** Short, stable type names — the class namespace is an implementation detail. */
    private function morphAlias(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    /**
     * @param  array{before?: array<string, mixed>, after?: array<string, mixed>}  $changes
     * @return array{before?: array<string, mixed>, after?: array<string, mixed>}
     */
    private function redact(array $changes): array
    {
        foreach (['before', 'after'] as $side) {
            if (! isset($changes[$side]) || ! is_array($changes[$side])) {
                continue;
            }

            foreach ($changes[$side] as $key => $value) {
                if (in_array(strtolower((string) $key), self::REDACTED, strict: true)) {
                    $changes[$side][$key] = '[redacted]';
                }
            }
        }

        return $changes;
    }

    /**
     * Casts objects (enums, Carbon) to JSON-safe scalars so that a change entry
     * never becomes an unreadable serialised blob.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringifyValues(array $values): array
    {
        return array_map(static function (mixed $value): mixed {
            return match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                is_scalar($value), is_null($value), is_array($value) => $value,
                default => (string) $value,
            };
        }, $values);
    }
}
