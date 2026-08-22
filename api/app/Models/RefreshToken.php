<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RefreshTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rotating refresh token.
 *
 * Only the SHA-256 hash of the token is persisted, for the same reason
 * passwords are hashed: a database leak must not hand the attacker working
 * credentials. SHA-256 without a salt is correct *here* (unlike for passwords)
 * because the input is 256 bits of CSPRNG output — there is no dictionary to
 * attack, and lookup must be a single indexed query.
 *
 * `family_id` groups every token descended from one login. When a token that
 * has already been rotated is presented again, the only two explanations are a
 * replay attack or a stolen token, so the entire family is revoked.
 *
 * @property string $token_hash
 * @property string $family_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
class RefreshToken extends Model
{
    /** @use HasFactory<RefreshTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token_hash',
        'family_id',
        'parent_id',
        'expires_at',
        'user_agent',
        'ip_address',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function revoke(string $reason): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }

    /** @param Builder<self> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** @param Builder<self> $query */
    public function scopeFamily(Builder $query, string $familyId): void
    {
        $query->where('family_id', $familyId);
    }
}
