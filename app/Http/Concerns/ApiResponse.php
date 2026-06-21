<?php

namespace App\Http\Concerns;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function jsonSuccess(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    protected function jsonError(string $message, mixed $data = null, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = [
            'status' => false,
            'data' => $data,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
