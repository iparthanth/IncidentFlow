<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\KeyProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Where the JWT key pair is written, versus where it is read from.
 *
 * This exists because of a real CI failure. `.env.example` carried the
 * container's absolute path (`/var/www/html/storage/keys/...`), so a GitHub
 * runner that copied it and ran `php artisan jwt:keys` tried to `mkdir
 * /var/www` and died on permissions.
 *
 * The deeper bug underneath it was worse and silent: `KeyProvider` anchored
 * relative paths to the application root while the generator used the working
 * directory. Under `artisan` those agree, so it passed locally; under php-fpm
 * or a supervised worker they do not, and the command would report success
 * having written the keys somewhere the application never looks.
 */
final class JwtKeyPathTest extends TestCase
{
    private string $relativeDir = 'storage/keys-test';

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->relativeDir));

        parent::tearDown();
    }

    public function test_a_relative_key_path_is_anchored_to_the_application_root(): void
    {
        $resolved = KeyProvider::absolute('storage/keys/jwt-private.pem');

        $this->assertSame(
            rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR.'storage/keys/jwt-private.pem',
            $resolved,
        );
        // Anchored to the project root, not to wherever the process happens to
        // have been started from.
        $this->assertStringStartsWith(base_path(), $resolved);
    }

    public function test_an_absolute_key_path_is_left_alone(): void
    {
        // A container secret mount is already absolute and must not be rewritten.
        $this->assertSame(
            '/var/www/html/storage/keys/jwt-private.pem',
            KeyProvider::absolute('/var/www/html/storage/keys/jwt-private.pem'),
        );
        $this->assertSame(
            'C:\\keys\\jwt-private.pem',
            KeyProvider::absolute('C:\\keys\\jwt-private.pem'),
        );
    }

    public function test_generated_keys_land_exactly_where_the_application_reads_them(): void
    {
        // The configuration a fresh `cp .env.example .env` produces.
        config([
            'jwt.private_key' => null,
            'jwt.public_key' => null,
            'jwt.private_key_path' => $this->relativeDir.'/jwt-private.pem',
            'jwt.public_key_path' => $this->relativeDir.'/jwt-public.pem',
        ]);

        $this->artisan('jwt:keys', ['--force' => true, '--bits' => 2048])
            ->assertExitCode(0);

        // The generator's output and the reader's expectation must be the same
        // file. Asserting on KeyProvider's resolution rather than on a literal
        // path is the point: if the two ever diverge again, this fails.
        $private = KeyProvider::absolute($this->relativeDir.'/jwt-private.pem');
        $public = KeyProvider::absolute($this->relativeDir.'/jwt-public.pem');

        $this->assertFileExists($private, 'The private key is not where the application will look for it.');
        $this->assertFileExists($public);

        $this->assertStringContainsString('PRIVATE KEY', (string) file_get_contents($private));
        $this->assertStringContainsString('PUBLIC KEY', (string) file_get_contents($public));

        // And the provider can actually load what the command just wrote.
        $provider = new KeyProvider(config('jwt'));
        $this->assertStringContainsString('PRIVATE KEY', $provider->privateKey());
        $this->assertStringContainsString('PUBLIC KEY', $provider->publicKey());
    }

    public function test_the_committed_env_example_does_not_hardcode_compose_hostnames(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        /*
         * docker-compose.yml sets DB_HOST/REDIS_HOST/MAIL_HOST itself for every
         * api-family service, so a Compose service name here is redundant inside
         * Docker and simply wrong everywhere else. On a CI runner it resolves to
         * nothing -- "getaddrinfo for redis failed" -- and the run dies.
         */
        foreach (['DB_HOST' => 'postgres', 'REDIS_HOST' => 'redis', 'MAIL_HOST' => 'mailpit'] as $key => $service) {
            $this->assertStringNotContainsString(
                "{$key}={$service}",
                $example,
                "{$key} must not be the Compose service name; Compose supplies its own value and this file is also used outside Docker.",
            );
        }

        $this->assertStringContainsString('REDIS_HOST=127.0.0.1', $example);
    }

    public function test_the_committed_env_example_does_not_hardcode_a_container_path(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        // CI copies this file verbatim. An absolute container path here means
        // `jwt:keys` tries to create /var/www on the runner and the build dies.
        $this->assertStringNotContainsString(
            'JWT_PRIVATE_KEY_PATH=/var/www',
            $example,
            '.env.example must not hardcode the container path; use a root-relative one.',
        );
        $this->assertStringContainsString('JWT_PRIVATE_KEY_PATH=storage/keys/', $example);
    }
}
