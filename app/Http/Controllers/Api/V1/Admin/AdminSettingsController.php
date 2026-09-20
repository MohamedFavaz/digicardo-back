<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminSettingsController extends Controller
{
    use ApiResponse;

    /** Change the authenticated admin's password. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $admin = $request->user();

        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $admin->update(['password' => Hash::make($data['password'])]);

        // Revoke all other sessions for security
        $admin->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return $this->successResponse(
            ['updated' => true],
            ['message' => 'Password changed successfully. Other sessions have been revoked.']
        );
    }
}
