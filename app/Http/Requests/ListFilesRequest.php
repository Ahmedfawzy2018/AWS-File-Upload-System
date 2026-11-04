<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Files\Enums\FileStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFilesRequest extends FormRequest
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $statuses = array_map(
            fn (FileStatus $s): string => $s->value,
            array_filter(FileStatus::cases(), fn (FileStatus $s): bool => $s !== FileStatus::Deleted),
        );

        return [
            'status' => ['nullable', Rule::in($statuses)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function status(): ?FileStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? FileStatus::from($status) : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
