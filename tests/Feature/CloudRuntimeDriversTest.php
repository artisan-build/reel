<?php

declare(strict_types=1);

use Aws\Sqs\SqsClient;

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
});
