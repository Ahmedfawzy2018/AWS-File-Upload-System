<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\Services\QuotaLedger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function show(Request $request, QuotaLedger $ledger): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $usage = $ledger->usage($user->id);

        return response()->json([
            'used_bytes' => $usage->usedBytes,
            'reserved_bytes' => $usage->reservedBytes,
            'quota_bytes' => $usage->quotaBytes,
        ]);
    }
}
