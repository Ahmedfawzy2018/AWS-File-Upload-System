<?php

declare(strict_types=1);

use App\Models\User;

it('creates a user', function () {
    $this->artisan('users:create', ['email' => 'a@b.test', '--name' => 'A', '--password' => 'pw-123456'])->assertSuccessful();
    expect(User::whereEmail('a@b.test')->exists())->toBeTrue();
});
