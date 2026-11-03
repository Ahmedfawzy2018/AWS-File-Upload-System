<?php

declare(strict_types=1);

namespace App\Domain\Files\Enums;

use App\Domain\Files\Exceptions\InvalidState;

enum FileStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Ready = 'ready';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Deleted = 'deleted';

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::Pending => [self::Uploaded, self::Rejected, self::Expired],
            self::Uploaded => [self::Ready, self::Rejected],
            self::Ready, self::Rejected, self::Expired => [self::Deleted],
            self::Deleted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    public function assertCanTransitionTo(self $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw new InvalidState($this, 'move to '.$target->value);
        }
    }
}
