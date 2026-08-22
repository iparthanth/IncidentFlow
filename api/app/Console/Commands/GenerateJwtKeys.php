<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates the RS256 key pair the API signs with and the realtime service
 * verifies against.
 *
 * Written as an artisan command rather than an `openssl` one-liner in the
 * README for two reasons: it uses PHP's own OpenSSL binding, so it works
 * identically on Windows, macOS and Alpine with nothing extra installed; and
 * it applies 0600 permissions to the private key, which a copy-pasted shell
 * command reliably forgets.
 */
final class GenerateJwtKeys extends Command
{
    protected $signature = 'jwt:keys
        {--force : Overwrite an existing key pair}
        {--bits=2048 : RSA modulus size}
        {--path= : Directory to write the pair into (defaults to the configured paths)}';

    protected $description = 'Generate the RS256 key pair used to sign API and realtime tokens';

    public function handle(): int
    {
        $bits = (int) $this->option('bits');

        if ($bits < 2048) {
            $this->error('Refusing to generate a key smaller than 2048 bits.');

            return self::FAILURE;
        }

        [$privatePath, $publicPath] = $this->resolvePaths();

        if (! $this->option('force') && (file_exists($privatePath) || file_exists($publicPath))) {
            $this->error('A key pair already exists. Re-run with --force to replace it.');
            // Replacing keys invalidates every token in flight, so this is a
            // deliberate speed bump rather than a formality.
            $this->line('Note: replacing the pair signs out every session immediately.');

            return self::FAILURE;
        }

        [$resource, $opensslConfig] = $this->generateKey($bits);

        if ($resource === false) {
            $this->error('OpenSSL failed to generate a key: '.(openssl_error_string() ?: 'unknown error'));
            $this->line('On Windows this usually means OPENSSL_CONF is unset and no openssl.cnf could be found.');
            $this->line('Set it explicitly, e.g.  $env:OPENSSL_CONF = "C:\\php\\extras\\ssl\\openssl.cnf"');

            return self::FAILURE;
        }

        $passphrase = (string) config('jwt.passphrase');
        $privatePem = '';

        // The export needs the same config as the generate: on Windows both
        // calls reach for openssl.cnf independently.
        $exportArgs = $opensslConfig !== null ? ['config' => $opensslConfig] : [];

        if (! openssl_pkey_export($resource, $privatePem, $passphrase !== '' ? $passphrase : null, $exportArgs)) {
            $this->error('OpenSSL failed to export the private key: '.(openssl_error_string() ?: 'unknown error'));

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['key'])) {
            $this->error('OpenSSL failed to derive the public key.');

            return self::FAILURE;
        }

        foreach ([$privatePath, $publicPath] as $path) {
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                $this->error("Could not create directory [{$directory}].");

                return self::FAILURE;
            }
        }

        file_put_contents($privatePath, $privatePem);
        file_put_contents($publicPath, (string) $details['key']);

        // Owner-read-only. No-ops on Windows, load-bearing everywhere else.
        @chmod($privatePath, 0o600);
        @chmod($publicPath, 0o644);

        $this->info('RS256 key pair generated.');
        $this->line("  private: {$privatePath}");
        $this->line("  public:  {$publicPath}");
        $this->newLine();
        $this->line('Give the realtime service the <options=bold>public</> key only:');
        $this->line('  JWT_PUBLIC_KEY_PATH='.$publicPath);
        $this->newLine();
        $this->line('As a single environment variable instead:');
        $this->line('  JWT_PUBLIC_KEY='.base64_encode((string) $details['key']));

        return self::SUCCESS;
    }

    /**
     * Generates an RSA key, working around Windows' missing OPENSSL_CONF.
     *
     * PHP's OpenSSL binding needs an openssl.cnf and, unlike on Linux, the
     * Windows build ships one without pointing at it — so the first call fails
     * with an opaque "No such process". Rather than making every developer
     * discover that for themselves, the common locations are tried in turn.
     * On Linux the first attempt simply succeeds and none of this runs.
     *
     * @return array{\OpenSSLAsymmetricKey|false, string|null} the key and the
     *                                                         config path it needed, if any
     */
    private function generateKey(int $bits): array
    {
        $baseArgs = [
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        $resource = @openssl_pkey_new($baseArgs);
        if ($resource !== false) {
            return [$resource, null];
        }

        foreach ($this->candidateOpenSslConfigs() as $configPath) {
            if (! is_readable($configPath)) {
                continue;
            }

            $resource = @openssl_pkey_new([...$baseArgs, 'config' => $configPath]);

            if ($resource !== false) {
                $this->line("Used OpenSSL config at [{$configPath}].");

                return [$resource, $configPath];
            }
        }

        return [false, null];
    }

    /** @return list<string> */
    private function candidateOpenSslConfigs(): array
    {
        $candidates = [];

        $fromEnv = getenv('OPENSSL_CONF');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $candidates[] = $fromEnv;
        }

        $phpDirectory = dirname(PHP_BINARY);
        $candidates[] = $phpDirectory.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        $candidates[] = $phpDirectory.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'openssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        $candidates[] = '/etc/ssl/openssl.cnf';
        $candidates[] = '/usr/local/ssl/openssl.cnf';

        return $candidates;
    }

    /** @return array{string, string} */
    private function resolvePaths(): array
    {
        $override = $this->option('path');

        if (is_string($override) && $override !== '') {
            return [
                rtrim($override, '/\\').DIRECTORY_SEPARATOR.'jwt-private.pem',
                rtrim($override, '/\\').DIRECTORY_SEPARATOR.'jwt-public.pem',
            ];
        }

        return [
            (string) config('jwt.private_key_path'),
            (string) config('jwt.public_key_path'),
        ];
    }
}
