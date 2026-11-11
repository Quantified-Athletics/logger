<?php

namespace App\Sync\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhoAmIController
{
    /**
     * Tell the token's owner who they are and whether they are an admin.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'admin' => $user->hasRole('Admin'),
        ]);
    }
}
