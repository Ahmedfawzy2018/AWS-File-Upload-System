<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\OriginalName;

it('strips path separators and control chars', fn () => expect(OriginalName::fromString("../../etc/pa\x00sswd")->value())->toBe('....etcpasswd'));
it('falls back to file when empty', fn () => expect(OriginalName::fromString(' /\\ ')->value())->toBe('file'));
it('keeps a normal name', fn () => expect(OriginalName::fromString('  report.pdf ')->value())->toBe('report.pdf'));
it('truncates to 255 bytes without splitting utf-8', function () {
    $v = OriginalName::fromString(str_repeat('é', 200))->value();
    expect(strlen($v))->toBeLessThanOrEqual(255)->and(mb_check_encoding($v, 'UTF-8'))->toBeTrue();
});
