<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('coupons')) {
            return $this->jsonError('You do not have permission to view coupons.', null, 403);
        }

        $coupons = Coupon::query()->orderBy('created_at', 'desc')->get()->map(fn (Coupon $coupon) => $this->resource($coupon))->values();

        return $this->jsonSuccess(['coupons' => $coupons]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('coupons')) {
            return $this->jsonError('You do not have permission to create coupons.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code' => ['required', 'string', 'max:255', 'unique:coupons,code'],
            'discount_type' => ['required', 'string', Rule::in(['amount', 'percentage'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $coupon = Coupon::query()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'code' => $data['code'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'status' => (bool) $data['status'],
        ]);

        return $this->jsonSuccess($this->resource($coupon), 'Coupon created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('coupons')) {
            return $this->jsonError('You do not have permission to update coupons.', null, 403);
        }

        $coupon = Coupon::query()->find($id);
        if (! $coupon) {
            return $this->jsonError('Coupon not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code' => ['required', 'string', 'max:255', Rule::unique('coupons', 'code')->ignore($coupon->id)],
            'discount_type' => ['required', 'string', Rule::in(['amount', 'percentage'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $coupon->title = $data['title'];
        $coupon->description = $data['description'] ?? null;
        $coupon->code = $data['code'];
        $coupon->discount_type = $data['discount_type'];
        $coupon->discount_value = $data['discount_value'];
        $coupon->status = (bool) $data['status'];
        $coupon->save();

        return $this->jsonSuccess($this->resource($coupon), 'Coupon updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('coupons')) {
            return $this->jsonError('You do not have permission to delete coupons.', null, 403);
        }

        $coupon = Coupon::query()->find($id);
        if (! $coupon) {
            return $this->jsonError('Coupon not found.', null, 404);
        }

        $coupon->delete();

        return $this->jsonSuccess(null, 'Coupon deleted successfully.');
    }

    private function resource(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'title' => $coupon->title,
            'description' => $coupon->description,
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'status' => (bool) $coupon->status,
            'created_at' => $coupon->created_at?->toIso8601String(),
            'updated_at' => $coupon->updated_at?->toIso8601String(),
        ];
    }
}
