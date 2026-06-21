<?php

namespace App\Http\Controllers\Customer;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerOrdersController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'payment' => ['nullable', 'string', 'in:all,paid,unpaid'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        /** @var User $user */
        $user = $request->user();
        $filter = $validator->validated()['payment'] ?? 'all';

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['slot'])
            ->orderByDesc('created_at');

        if ($filter === 'paid') {
            $query->consideredPaid();
        } elseif ($filter === 'unpaid') {
            $query->consideredUnpaid();
        }

        $orders = $query->get()->map(fn (Order $order) => $this->orderPayload($order));

        return $this->jsonSuccess(['orders' => $orders]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $order->user_id !== (int) $user->id) {
            return $this->jsonError('Order not found.', null, 404);
        }

        $order->loadMissing(['slot']);

        return $this->jsonSuccess(['order' => $this->orderPayload($order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        /** @var Slot|null $slot */
        $slot = $order->slot;

        return [
            'id' => $order->id,
            'status' => $order->status,
            'amount' => $order->amount,
            'payment_provider' => $order->payment_provider,
            'payment_status' => $order->payment_status,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'stripe_mode' => $order->stripe_mode,
            'fitting_date' => $order->fitting_date?->format('Y-m-d'),
            'vehicle_registration' => $order->vehicle_registration,
            'vehicle_make' => $order->vehicle_make,
            'vehicle_model' => $order->vehicle_model,
            'service_type' => $order->service_type,
            'tyre_brand' => $order->tyre_brand,
            'tyre_model' => $order->tyre_model,
            'tyre_size' => $order->tyre_size,
            'tyre_quantity' => $order->tyre_quantity,
            'customer_comment' => $order->customer_comment,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'slot' => $slot ? [
                'id' => $slot->id,
                'day' => $slot->day,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
            ] : null,
        ];
    }
}
