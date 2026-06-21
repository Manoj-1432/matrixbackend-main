<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\DvlaTyreLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DvlaTestController extends Controller
{
    use ApiResponse;

    public function lookup(Request $request, DvlaTyreLookupService $service): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('test_dvla')) {
            return $this->jsonError('You do not have permission to test DVLA.', null, 403);
        }

        $raw = $request->input('vrm');
        $vrm = strtoupper(preg_replace('/\s+/', '', is_string($raw) ? trim($raw) : ''));

        $validator = Validator::make(
            ['vrm' => $vrm],
            [
                'vrm' => ['required', 'string', 'min:2', 'max:16'],
            ]
        );

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $result = $service->lookupByVrm($vrm);

        if ($result['dvla_error'] !== null && ! $result['dvla_success'] && $result['dvla_http_code'] === 0) {
            return $this->jsonError($result['dvla_error'], $result, 400);
        }

        return $this->jsonSuccess($result);
    }
}
