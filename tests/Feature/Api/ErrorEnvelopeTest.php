<?php

declare(strict_types=1);
use App\Domain\Files\Exceptions\DomainError;

it('renders validation errors in the envelope', function () {
    $this->postJson('/api/v1/auth/tokens', [])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email', 'password']]]);
});

it('renders unauthenticated as 401 envelope', function () {
    $this->getJson('/api/v1/me/usage')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('renders unknown api routes as 404 envelope even without Accept json', function () {
    $this->get('/api/v1/nope')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
});

it('echoes or generates X-Request-Id', function () {
    $this->getJson('/health', ['X-Request-Id' => 'abc-123'])->assertHeader('X-Request-Id', 'abc-123');
    expect($this->getJson('/health')->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('replaces an invalid incoming request id', function () {
    $id = $this->getJson('/health', ['X-Request-Id' => 'bad id!'])->headers->get('X-Request-Id');
    expect($id)->toMatch('/^[0-9a-f-]{36}$/');
});

it('hides server error details when not in debug', function () {
    config(['app.debug' => false]);
    Route::get('/api/boom', fn () => throw new RuntimeException('secret'));
    $this->getJson('/api/boom')->assertStatus(500)
        ->assertJsonPath('error.code', 'SERVER_ERROR')
        ->assertJsonPath('error.message', 'Server error');
});

it('renders domain errors with their own code and status', function () {
    Route::get('/api/domain', fn () => throw new class('x') extends DomainError
    {
        public function errorCode(): string
        {
            return 'QUOTA_EXCEEDED';
        }

        public function httpStatus(): int
        {
            return 409;
        }

        public function details(): array
        {
            return ['a' => 1];
        }
    });
    $this->getJson('/api/domain')->assertStatus(409)
        ->assertJsonPath('error.code', 'QUOTA_EXCEEDED')
        ->assertJsonPath('error.details.a', 1);
});

it('renders rate limiting as 429 envelope', function () {
    Route::get('/api/limited', fn () => 'ok')->middleware('throttle:1,1');
    $this->getJson('/api/limited');
    $this->getJson('/api/limited')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
