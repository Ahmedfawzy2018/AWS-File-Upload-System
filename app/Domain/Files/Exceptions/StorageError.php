<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

/** Unexpected storage backend failure. Deliberately not a DomainError, so it renders as a 500. */
final class StorageError extends \RuntimeException {}
