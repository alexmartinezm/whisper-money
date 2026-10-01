<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\OpenBanking\ConnectionController;
use App\Models\BankingConnection;
use Tests\TestCase;

uses(TestCase::class);

function invokeConnectionControllerSyncStateMethod(
    ConnectionController $controller,
    string $method,
    mixed ...$arguments,
): mixed {
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

it('preserves the existing rate-limit marker for an EnableBanking cooldown', function () {
    $message = 'Rate limit exceeded. Please wait a few minutes and try again.';
    $connection = new BankingConnection([
        'provider' => BankingProvider::EnableBanking,
        'status' => BankingConnectionStatus::Active,
        'rate_limited_until' => now()->addHour(),
        'error_message' => $message,
    ]);

    expect(invokeConnectionControllerSyncStateMethod(
        new ConnectionController,
        'errorMessageToPreserve',
        $connection,
    ))->toBe($message);
});
