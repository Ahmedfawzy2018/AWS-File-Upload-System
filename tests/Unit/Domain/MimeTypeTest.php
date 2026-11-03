<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\MimeType;

it('normalises case and parameters', fn () => expect(MimeType::fromString(' Image/PNG; charset=x ')->value())->toBe('image/png'));

it('maps mime types to extensions', function (string $mime, string $ext) {
    expect(MimeType::fromString($mime)->extension())->toBe($ext);
})->with([
    ['image/jpeg', 'jpg'], ['image/png', 'png'], ['image/webp', 'webp'], ['image/gif', 'gif'],
    ['application/pdf', 'pdf'], ['text/plain', 'txt'], ['text/csv', 'csv'], ['application/zip', 'zip'],
    ['video/mp4', 'mp4'],
    ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
    ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
    ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx'],
    ['application/x-unknown', 'bin'],
]);
