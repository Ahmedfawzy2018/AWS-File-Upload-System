<?php

declare(strict_types=1);

namespace App\Domain\Files\Enums;

enum UploadType: string
{
    case Single = 'single';
    case Multipart = 'multipart';
}
