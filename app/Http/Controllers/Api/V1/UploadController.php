<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\Actions\InitiateUpload;
use App\Domain\Files\DTOs\InitiateUploadData;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateUploadRequest;
use App\Http\Resources\InitiatedUploadResource;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function store(InitiateUploadRequest $request, InitiateUpload $action): JsonResponse
    {
        $initiated = $action->handle(InitiateUploadData::fromRequest($request));

        return (new InitiatedUploadResource($initiated))->response()->setStatusCode(201);
    }
}
