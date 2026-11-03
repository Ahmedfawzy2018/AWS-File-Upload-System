<?php

declare(strict_types=1);

use App\Models\User;

it('issues a token for valid credentials', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'cli'])
        ->assertCreated()->assertJsonStructure(['token']);
});

it('rejects bad credentials with 422 VALIDATION_FAILED on email', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'cli'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.email.0', 'The provided credentials are incorrect.');
});

it('revokes the current token', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);
    $token = $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'cli'])->json('token');

    $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();
    expect($user->tokens()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me/usage')->assertStatus(401);
});
