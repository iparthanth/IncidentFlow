<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::ensureSigningKeys();

        parent::setUp();
    }

    /**
     * Generates a throwaway RS256 pair the first time the suite runs.
     *
     * Doing this in code rather than as a documented setup step means CI needs
     * no extra job, a fresh clone runs `composer test` with no preamble, and
     * nobody is ever tempted to commit a private key "just for tests" — which
     * is how test keys end up quietly signing something real.
     */
    private static function ensureSigningKeys(): void
    {
        // Runs before parent::setUp() creates the application, so base_path()
        // is not available yet — the project root is derived from this file.
        $root = dirname(__DIR__);
        $private = $root.'/'.(getenv('JWT_PRIVATE_KEY_PATH') ?: 'storage/keys/testing-private.pem');
        $public = $root.'/'.(getenv('JWT_PUBLIC_KEY_PATH') ?: 'storage/keys/testing-public.pem');

        if (is_readable($private) && is_readable($public)) {
            return;
        }

        $directory = dirname($private);
        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $args = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        // Windows PHP ships openssl.cnf without pointing OPENSSL_CONF at it.
        $config = null;
        $key = @openssl_pkey_new($args);

        if ($key === false) {
            foreach (self::opensslConfigCandidates() as $candidate) {
                if (! is_readable($candidate)) {
                    continue;
                }
                $key = @openssl_pkey_new([...$args, 'config' => $candidate]);
                if ($key !== false) {
                    $config = $candidate;
                    break;
                }
            }
        }

        if ($key === false) {
            self::fail('Could not generate a test RSA key pair; is the OpenSSL extension configured?');
        }

        $pem = '';
        openssl_pkey_export($key, $pem, null, $config !== null ? ['config' => $config] : []);
        $details = openssl_pkey_get_details($key);

        file_put_contents($private, $pem);
        file_put_contents($public, (string) ($details['key'] ?? ''));
    }

    /** @return list<string> */
    private static function opensslConfigCandidates(): array
    {
        $phpDirectory = dirname(PHP_BINARY);

        return array_values(array_filter([
            getenv('OPENSSL_CONF') ?: null,
            $phpDirectory.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
        ]));
    }

    // ----------------------------------------------------------- test helpers

    /**
     * Creates an organization plus a member in the given role.
     *
     * Almost every test needs "somebody who may do X in tenant Y"; without a
     * membership a user can do nothing at all, so bundling the two keeps the
     * arrange step honest and short.
     *
     * @return array{Organization, User}
     */
    protected function tenantWithMember(
        OrganizationRole $role = OrganizationRole::Responder,
        array $userAttributes = [],
    ): array {
        $organization = Organization::factory()->create();
        $user = User::factory()->memberOf($organization, $role)->create($userAttributes);

        return [$organization, $user];
    }

    /**
     * Authenticates as a user through the real token pipeline.
     *
     * Deliberately not `actingAs()`. Minting and sending an actual bearer token
     * means every request under test also exercises the JWT guard, the
     * denylist lookup and the organization middleware — the layers most likely
     * to be where an authorization bug actually lives.
     */
    protected function actingAsMember(User $user, ?Organization $organization = null): static
    {
        /**
         * A test shares one container across every request it makes, and
         * AuthManager caches its guards — so without this a second request
         * sent as a *different* user would silently reuse the first user's
         * already-resolved guard, and an authorization test would pass or fail
         * for entirely the wrong reason. Production boots a fresh container
         * per request, so this is a harness concern only.
         */
        Auth::forgetGuards();

        $token = app(TokenService::class)->issueAccessToken($user);

        $headers = ['Authorization' => 'Bearer '.$token->token];

        if ($organization !== null) {
            $headers['X-Organization'] = $organization->slug;
        }

        return $this->withHeaders($headers);
    }

    /** Asserts the standard error envelope, so shape drift fails loudly. */
    protected function assertApiError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $response->assertJsonPath('error.code', $code);
        $this->assertIsString($response->json('error.message'));
    }
}
