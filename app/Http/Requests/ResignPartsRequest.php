<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResignPartsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'part_numbers' => ['required', 'array', 'min:1'],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function partNumbers(): array
    {
        /** @var list<int|string> $numbers */
        $numbers = $this->validated('part_numbers');

        return array_map(intval(...), $numbers);
    }
}
