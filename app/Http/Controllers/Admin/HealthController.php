<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status'     => 'ok',
            'powered_by' => 'Workatmo Technologies Pvt Ltd',
        ]);
    }
}
