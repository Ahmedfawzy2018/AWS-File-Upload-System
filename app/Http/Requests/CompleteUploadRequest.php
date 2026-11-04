<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Files\DTOs\CompleteUploadData;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Models\File;
use Illuminate\Foundation\Http\FormRequest;

class CompleteUploadRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'parts' => ['nullable', 'array'],
            'parts.*.part_number' => ['required', 'integer'],
            'parts.*.etag' => ['present', 'nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @throws InvalidParts on a duplicate part number or an empty etag
     */
    public function toData(File $file): CompleteUploadData
    {
        /** @var list<array{part_number: int|string, etag: string|null}>|null $raw */
        $raw = $this->validated('parts');
        if ($raw === null) {
            return new CompleteUploadData($file, null);
        }

        $parts = [];
        $duplicates = [];
        foreach ($raw as $part) {
            $number = (int) $part['part_number'];
            if (isset($parts[$number])) {
                $duplicates[] = $number;
            }
            $parts[$number] = (string) $part['etag'];
        }
        if ($duplicates !== []) {
            throw new InvalidParts('Duplicate part numbers.', ['duplicate' => array_values(array_unique($duplicates))]);
        }

        return new CompleteUploadData($file, $parts);
    }
}
