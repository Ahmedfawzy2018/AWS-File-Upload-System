<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Exceptions\InvalidState;

it('allows only the specified transitions', function (FileStatus $from, FileStatus $to, bool $ok) {
    expect($from->canTransitionTo($to))->toBe($ok);
})->with([
    [FileStatus::Pending, FileStatus::Uploaded, true],
    [FileStatus::Pending, FileStatus::Rejected, true],
    [FileStatus::Pending, FileStatus::Expired, true],
    [FileStatus::Pending, FileStatus::Ready, false],
    [FileStatus::Uploaded, FileStatus::Ready, true],
    [FileStatus::Uploaded, FileStatus::Rejected, true],
    [FileStatus::Ready, FileStatus::Pending, false],
    [FileStatus::Ready, FileStatus::Deleted, true],
    [FileStatus::Rejected, FileStatus::Deleted, true],
    [FileStatus::Expired, FileStatus::Deleted, true],
    [FileStatus::Expired, FileStatus::Uploaded, false],
    [FileStatus::Deleted, FileStatus::Ready, false],
]);

it('throws InvalidState on illegal transition', fn () => FileStatus::Ready->assertCanTransitionTo(FileStatus::Pending))
    ->throws(InvalidState::class);

it('does not throw on a legal transition', function () {
    FileStatus::Pending->assertCanTransitionTo(FileStatus::Uploaded);
    expect(true)->toBeTrue();
});

it('exposes invalid state error details', function () {
    $e = new InvalidState(FileStatus::Ready, 'complete');
    expect($e->errorCode())->toBe('INVALID_STATE')
        ->and($e->httpStatus())->toBe(409)
        ->and($e->getMessage())->toBe('Cannot complete a file in state ready.');
});
