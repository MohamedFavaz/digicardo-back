<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    /**
     * Return a standardized JSON success response.
     *
     * @param mixed $data
     * @param array $meta
     * @param int $status
     * @param array $headers
     * @return JsonResponse
     */
    protected function successResponse(
        mixed $data = null,
        array $meta = [],
        int $status = Response::HTTP_OK,
        array $headers = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status, $headers);
    }

    /**
     * Return a standardized JSON error response.
     *
     * @param string $code
     * @param string $message
     * @param mixed $details
     * @param int $status
     * @param array $headers
     * @return JsonResponse
     */
    protected function errorResponse(
        string $code,
        string $message,
        mixed $details = null,
        int $status = Response::HTTP_BAD_REQUEST,
        array $headers = []
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $status, $headers);
    }
}
