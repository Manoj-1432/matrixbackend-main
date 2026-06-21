<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\DeliveryCharge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryChargeController extends Controller
{
    use ApiResponse;

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/delivery-charges
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('delivery_charges')) {
            return $this->jsonError('You do not have permission to view delivery charges.', null, 403);
        }

        $charges = DeliveryCharge::query()
            ->orderBy('from_distance', 'asc')
            ->get()
            ->map(fn (DeliveryCharge $c) => $this->resource($c))
            ->values();

        return $this->jsonSuccess(['delivery_charges' => $charges]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/delivery-charges
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('delivery_charges')) {
            return $this->jsonError('You do not have permission to create delivery charges.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'from_distance' => ['required', 'numeric', 'min:0'],
            'to_distance'   => ['required', 'numeric', 'min:0', 'gt:from_distance'],
            'charge'        => ['required', 'numeric', 'min:0'],
            'status'        => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        // Overlap check: an existing row's range [from, to) must NOT overlap [data.from, data.to)
        $overlap = DeliveryCharge::query()
            ->where('from_distance', '<', $data['to_distance'])
            ->where('to_distance', '>', $data['from_distance'])
            ->exists();

        if ($overlap) {
            return $this->jsonError('This range overlaps with an existing delivery charge range.', null, 422);
        }

        $charge = DeliveryCharge::query()->create([
            'from_distance' => $data['from_distance'],
            'to_distance'   => $data['to_distance'],
            'charge'        => $data['charge'],
            'status'        => $data['status'],
        ]);

        return $this->jsonSuccess($this->resource($charge), 'Delivery charge created successfully.', 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/admin/delivery-charges/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('delivery_charges')) {
            return $this->jsonError('You do not have permission to update delivery charges.', null, 403);
        }

        $charge = DeliveryCharge::query()->find($id);
        if (! $charge) {
            return $this->jsonError('Delivery charge not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'from_distance' => ['required', 'numeric', 'min:0'],
            'to_distance'   => ['required', 'numeric', 'min:0', 'gt:from_distance'],
            'charge'        => ['required', 'numeric', 'min:0'],
            'status'        => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        // Overlap check, excluding the current record
        $overlap = DeliveryCharge::query()
            ->where('id', '!=', $id)
            ->where('from_distance', '<', $data['to_distance'])
            ->where('to_distance', '>', $data['from_distance'])
            ->exists();

        if ($overlap) {
            return $this->jsonError('This range overlaps with an existing delivery charge range.', null, 422);
        }

        $charge->from_distance = $data['from_distance'];
        $charge->to_distance   = $data['to_distance'];
        $charge->charge        = $data['charge'];
        $charge->status        = $data['status'];
        $charge->save();

        return $this->jsonSuccess($this->resource($charge), 'Delivery charge updated successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/admin/delivery-charges/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('delivery_charges')) {
            return $this->jsonError('You do not have permission to delete delivery charges.', null, 403);
        }

        $charge = DeliveryCharge::query()->find($id);
        if (! $charge) {
            return $this->jsonError('Delivery charge not found.', null, 404);
        }

        $charge->delete();

        return $this->jsonSuccess(null, 'Delivery charge deleted successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private resource shaper
    // ─────────────────────────────────────────────────────────────────────────
    private function resource(DeliveryCharge $charge): array
    {
        return [
            'id'            => $charge->id,
            'from_distance' => (float) $charge->from_distance,
            'to_distance'   => (float) $charge->to_distance,
            'charge'        => (float) $charge->charge,
            'status'        => $charge->status,
            'created_at'    => $charge->created_at?->toIso8601String(),
            'updated_at'    => $charge->updated_at?->toIso8601String(),
        ];
    }
}
