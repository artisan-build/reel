<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Console;

use ArtisanBuild\ReelClient\Correlation;
use ArtisanBuild\ReelClient\EnvironmentFile;
use ArtisanBuild\ReelClient\KeyMaterial;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'reel:install
        {--url= : Base URL of the Reel deployment}
        {--application= : Public Reel application identifier}
        {--enrollment-code= : Short-lived enrollment code}
        {--context-export= : off, session_id, or session_id_and_url}';

    protected $description = 'Generate a local signing key and enroll this Laravel application with Reel';

    public function handle(Factory $http, EnvironmentFile $environment): int
    {
        $interactiveInstall = ! $this->option('url') || ! $this->option('application') || ! $this->option('enrollment-code');
        $url = rtrim((string) ($this->option('url') ?: $this->ask('Reel URL')), '/');
        $applicationId = (string) ($this->option('application') ?: $this->ask('Application ID'));
        $enrollmentCode = (string) ($this->option('enrollment-code') ?: $this->secret('Enrollment code'));
        $contextExport = (string) ($this->option('context-export') ?: 'off');

        if ($interactiveInstall && ! $this->option('context-export')) {
            $this->components->warn('Reel Context is sent to your configured Nightwatch transport: SaaS, Hone, or another endpoint.');
            $contextExport = (string) $this->choice('Reel Context export', Correlation::EXPORT_MODES, 0);
        }

        if ($url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || $applicationId === ''
            || $enrollmentCode === ''
            || ! in_array($contextExport, Correlation::EXPORT_MODES, true)) {
            $this->components->error('A valid Reel URL, application id, enrollment code, and Context export mode are required.');

            return self::FAILURE;
        }

        try {
            $key = KeyMaterial::generate();
            $response = $http->asJson()
                ->acceptJson()
                ->timeout(15)
                ->post($url.'/api/applications/'.rawurlencode($applicationId).'/enrollment', [
                    'enrollment_code' => $enrollmentCode,
                    'algorithm' => 'RS256',
                    'public_key' => $key['public'],
                ]);

            if (! $response->created()
                || $response->json('application_id') !== $applicationId
                || $response->json('algorithm') !== 'RS256') {
                throw new RuntimeException('Reel rejected the enrollment request.');
            }

            $environment->write($this->laravel->environmentFilePath(), [
                'REEL_URL' => $url,
                'REEL_APPLICATION_ID' => $applicationId,
                'REEL_PRIVATE_KEY' => KeyMaterial::encodePrivateKey($key['private']),
                'REEL_CONTEXT_EXPORT' => $contextExport,
            ]);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Reel enrolled. The signing key remains local to this application.');

        return self::SUCCESS;
    }
}
