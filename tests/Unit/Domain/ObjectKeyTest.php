<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use Carbon\CarbonImmutable;

it('builds the key from server data only', function () {
    $k = ObjectKey::generate('users', 42, '01JB0000000000000000000000', MimeType::fromString('image/jpeg'), CarbonImmutable::parse('2026-03-09'));
    expect($k->value())->toBe('users/42/2026/03/01JB0000000000000000000000.jpg');
});

it('round-trips from string', fn () => expect(ObjectKey::fromString('users/1/2026/01/x.png')->value())->toBe('users/1/2026/01/x.png'));
