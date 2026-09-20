<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountDeletionBlockedException extends Exception
{
    public function __construct(string $message = 'Account deletion cannot proceed while you have an active paid subscription. Please cancel your subscription from Billing settings first.')
    {
        parent::__construct($message, Response::HTTP_CONFLICT);
    }

    /**
     * Render exception into JSON response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'ACCOUNT_DELETION_BLOCKED',
                'message' => $this->getMessage(),
            ],
        ], Response::HTTP_CONFLICT);
    }
}
