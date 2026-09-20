<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecentAuthRequiredException extends Exception
{
    public function __construct(string $message = 'Recent authentication is required for this sensitive action. Please confirm your password.')
    {
        parent::__construct($message, Response::HTTP_FORBIDDEN);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'RECENT_AUTH_REQUIRED',
                'message' => $this->getMessage(),
            ],
        ], Response::HTTP_FORBIDDEN);
    }
}
