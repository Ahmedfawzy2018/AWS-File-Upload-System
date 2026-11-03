<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $request->string('device_name', 'api')->toString();

        return response()->json(['token' => $user->createToken($deviceName)->plainTextToken], 201);
    }

    public function destroy(Request $request): Response
    {
        $request->user()?->currentAccessToken()->delete();

        return response()->noContent();
    }
}
