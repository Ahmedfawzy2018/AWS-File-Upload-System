<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows any user to view Horizon in local', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(Gate::forUser(User::factory()->make())->allows('viewHorizon'))->toBeTrue();
});

it('allows only listed emails outside local', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['horizon.allowed_emails' => 'ops@example.com, Admin@Example.com']);

    expect(Gate::forUser(User::factory()->make(['email' => 'admin@example.com']))->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->make(['email' => 'eve@example.com']))->allows('viewHorizon'))->toBeFalse();
});

it('denies everyone outside local when the allow-list is empty', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['horizon.allowed_emails' => '']);

    expect(Gate::forUser(User::factory()->make(['email' => 'a@example.com']))->allows('viewHorizon'))->toBeFalse();
});

it('configures the three supervisors', function () {
    $d = config('horizon.defaults');

    expect($d['uploads'])->toMatchArray(['queue' => ['uploads'], 'minProcesses' => 2, 'maxProcesses' => 10, 'balance' => 'auto'])
        ->and($d['maintenance'])->toMatchArray(['queue' => ['maintenance'], 'minProcesses' => 1, 'maxProcesses' => 2])
        ->and($d['default'])->toMatchArray(['queue' => ['default'], 'minProcesses' => 1, 'maxProcesses' => 3]);
});

it('keeps every Horizon supervisor timeout below the redis retry_after', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    foreach (config('horizon.defaults') as $name => $supervisor) {
        expect($supervisor['timeout'])->toBeLessThan($retryAfter, "supervisor {$name}");
    }
});
