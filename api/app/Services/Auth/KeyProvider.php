<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * Resolves the RS256 key pair from configuration.
 *
 * Two supply routes exist because two deployment styles exist: PaaS platforms
 * hand you environment variables (base64 PEM), container orchestrators hand you
 * mounted files. Both are read exactly once per process and memoised — key
 * parsing on every token issue would be a measurable cost on a hot login path.
 */
final class KeyProvider
{
    private ?string $privateKey = null;

    private ?string $publicKey = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function privateKey(): string
    {
        return $this->privateKey ??= $this->resolve('private_key', 'private_key_path', 'PRIVATE');
    }

    public function publicKey(): string
    {
        return $this->publicKey ??= $this->resolve('public_key', 'public_key_path', 'PUBLIC');
    }

    public function passphrase(): ?string
    {
        $passphrase = $this->config['passphrase'] ?? null;

        return is_string($passphrase) && $passphrase !== '' ? $passphrase : null;
    }

    private function resolve(string $inlineKey, string $pathKey, string $expectedMarker): string
    {
        $inline = $this->config[$inlineKey] ?? null;
        if (is_string($inline) && trim($inline) !== '') {
            return $this->normalise($inline, $expectedMarker, "config('jwt.{$inlineKey}')");
        }

        $path = $this->config[$pathKey] ?? null;
        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                "JWT key not configured: set jwt.{$inlineKey} or jwt.{$pathKey}. Run `php artisan jwt:keys` to generate a pair."
            );
        }

        $path = $this->absolute($path);

        if (! is_readable($path)) {
            throw new RuntimeException(
                "JWT key file is missing or unreadable at [{$path}]. Run `php artisan jwt:keys` to generate a pair."
            );
        }

        return $this->normalise((string) file_get_contents($path), $expectedMarker, $path);
    }

    /**
     * Anchors a relative key path to the application root.
     *
     * `.env` files naturally contain paths like `storage/keys/jwt-private.pem`,
     * but the working directory is not the project root under `artisan serve`
     * (which runs from `public/`), under php-fpm, or under a queue worker
     * started by a supervisor. Resolving here means one configured value works
     * in all of them instead of failing in whichever context nobody tested.
     */
    private function absolute(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        if ($isAbsolute) {
            return $path;
        }

        return rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    /** Accepts raw PEM, PEM with literal "\n", or base64-encoded PEM. */
    private function normalise(string $value, string $expectedMarker, string $source): string
    {
        $trimmed = trim($value);

        if (! str_contains($trimmed, '-----BEGIN')) {
            $decoded = base64_decode($trimmed, strict: true);
            if ($decoded === false || ! str_contains($decoded, '-----BEGIN')) {
                throw new RuntimeException("JWT key from [{$source}] is neither a PEM nor base64-encoded PEM.");
            }
            $trimmed = $decoded;
        }

        // Environment variables commonly carry escaped newlines.
        if (str_contains($trimmed, '\n')) {
            $trimmed = str_replace('\n', "\n", $trimmed);
        }

        if (! str_contains($trimmed, $expectedMarker)) {
            throw new RuntimeException(
                "JWT key from [{$source}] does not look like a {$expectedMarker} key — check the two paths are not swapped."
            );
        }

        return $trimmed;
    }
}
