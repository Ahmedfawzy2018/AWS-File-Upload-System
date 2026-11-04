<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Files\DTOs\InitiatedUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property InitiatedUpload $resource */
class InitiatedUploadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        $upload = $u->put !== null
            ? [
                'type' => 'single',
                'method' => $u->put->method,
                'url' => $u->put->url,
                'headers' => (object) $u->put->headers,
                'expires_at' => $u->expiresAt->toIso8601String(),
            ]
            : [
                'type' => 'multipart',
                'part_size' => $u->partSize,
                'parts' => $u->parts,
                'expires_at' => $u->expiresAt->toIso8601String(),
            ];

        return [
            'id' => $u->file->id,
            'status' => $u->file->status->value,
            'upload' => $upload,
        ];
    }
}
