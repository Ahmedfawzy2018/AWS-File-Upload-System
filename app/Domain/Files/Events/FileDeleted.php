<?php

declare(strict_types=1);

namespace App\Domain\Files\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class FileDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $fileId) {}
}
