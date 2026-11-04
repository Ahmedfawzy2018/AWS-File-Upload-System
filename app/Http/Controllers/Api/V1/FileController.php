<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\Actions\DeleteFile;
use App\Domain\Files\Actions\GetDownloadUrl;
use App\Domain\Files\Contracts\FileRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListFilesRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FileController extends Controller
{
    public function index(ListFilesRequest $request, FileRepository $files): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return FileResource::collection($files->paginateForUser($user->id, $request->status(), $request->perPage()));
    }

    public function show(File $file): FileResource
    {
        Gate::authorize('view', $file);

        return new FileResource($file);
    }

    public function download(File $file, GetDownloadUrl $action): JsonResponse
    {
        Gate::authorize('view', $file);

        $presigned = $action->handle($file);

        return response()->json([
            'url' => $presigned->url,
            'expires_at' => $presigned->expiresAt->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, File $file, DeleteFile $action): Response
    {
        Gate::authorize('delete', $file);

        $action->handle($file);

        return response()->noContent();
    }
}
