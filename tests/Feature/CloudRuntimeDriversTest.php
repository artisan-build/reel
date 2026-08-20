<?php

declare(strict_types=1);

use Aws\Sqs\SqsClient;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;

it('ships the managed SQS runtime driver', function (): void {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->toHaveKey('aws/aws-sdk-php')
        ->and($composer['require-dev'])->not->toHaveKey('aws/aws-sdk-php')
        ->and(class_exists(SqsClient::class))->toBeTrue()
        ->and(config('queue.connections.sqs.driver'))->toBe('sqs');

    $process = new Process(
        [
            PHP_BINARY,
            '-r',
            'require "vendor/autoload.php"; $config = require "config/queue.php"; echo json_encode($config["connections"]["sqs"], JSON_THROW_ON_ERROR);',
        ],
        base_path(),
        [
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_DEFAULT_REGION' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'SQS_PREFIX' => false,
            'SQS_QUEUE' => false,
            'SQS_SUFFIX' => false,
        ],
    );
    $process->mustRun();

    $sqsConfigWithoutEnvironment = json_decode(
        $process->getOutput(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(Arr::except($sqsConfigWithoutEnvironment, ['driver', 'after_commit']))
        ->each->toBeNull();
});
