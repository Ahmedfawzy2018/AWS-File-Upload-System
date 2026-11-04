<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\Actions\AbortUpload;
use App\Domain\Files\Actions\CompleteUpload;
use App\Domain\Files\Actions\InitiateUpload;
use App\Domain\Files\Actions\ResignParts;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteUploadRequest;
use App\Http\Requests\InitiateUploadRequest;
use App\Http\Requests\ResignPartsRequest;
use App\Http\Resources\FileResource;
use App\Http\Resources\InitiatedUploadResource;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UploadController extends Controller
{
    public function store(InitiateUploadRequest $request, InitiateUpload $action): JsonResponse
    {
        $initiated = $action->handle($request->toData());

        return (new InitiatedUploadResource($initiated))->response()->setStatusCode(201);
    }

    public function complete(CompleteUploadRequest $request, File $file, CompleteUpload $action): JsonResponse
    {
        Gate::authorize('update', $file);

        return (new FileResource($action->handle($request->toData($file))))->response()->setStatusCode(202);
    }

    public function parts(ResignPartsRequest $request, File $file, ResignParts $action): JsonResponse
    {
        Gate::authorize('update', $file);

        $parts = $action->handle($file, $request->partNumbers());

        return response()->json([
            'parts' => $parts,
            'expires_at' => now()->addSeconds((int) config('uploads.ttl.multipart'))->toIso8601String(),
        ]);
    }

    public function abort(File $file, AbortUpload $action): JsonResponse
    {
        Gate::authorize('update', $file);

        return (new FileResource($action->handle($file)))->response();
    }
}
