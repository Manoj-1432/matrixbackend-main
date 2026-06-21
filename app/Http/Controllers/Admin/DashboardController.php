<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Return aggregated dashboard stats + recent orders + last-7-days chart data.
     */
    public function index(): JsonResponse
    {
        // ── Summary Stats ──────────────────────────────────────────────────────

        // Only count customers (role = 'user'), excluding admins / super_admins
        $totalUsers    = User::whereHas('role', fn ($q) => $q->where('name', Role::USER))->count();
        $totalOrders   = Order::count();
        $totalVehicles = Vehicle::count();

        // Revenue = sum of all completed order amounts
        $totalRevenue = Order::where('status', 'completed')
            ->sum('amount');

        // ── Order Status Breakdown ─────────────────────────────────────────────

        $orderStats = [
            'pending'    => Order::where('status', 'pending')->count(),
            'processing' => Order::where('status', 'processing')->count(),
            'completed'  => Order::where('status', 'completed')->count(),
            'cancelled'  => Order::where('status', 'cancelled')->count(),
        ];

        // ── Last 7 Days — Orders Per Day (for bar chart) ───────────────────────

        $days = collect(range(6, 0))->map(function ($daysAgo) {
            return now()->subDays($daysAgo)->toDateString();
        });

        $rawCounts = Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('created_at', [now()->subDays(6)->startOfDay(), now()->endOfDay()])
            ->groupBy('date')
            ->pluck('count', 'date');

        $ordersPerDay = $days->map(function ($date) use ($rawCounts) {
            return [
                'date'  => $date,
                'label' => \Carbon\Carbon::parse($date)->format('D'), // Mon, Tue …
                'count' => (int) ($rawCounts[$date] ?? 0),
            ];
        })->values();

        // ── Recent Orders (latest 5) ───────────────────────────────────────────

        $recentOrders = Order::with('user:id,name,email,phone')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                return [
                    'id'                   => $order->id,
                    'order_ref'            => '#ORD-' . str_pad($order->id, 3, '0', STR_PAD_LEFT),
                    'customer'             => $order->user?->name ?? 'Guest',
                    'vehicle'              => trim(($order->vehicle_make ?? '') . ' ' . ($order->vehicle_model ?? '')) ?: ($order->vehicle_registration ?? '—'),
                    'vehicle_registration' => $order->vehicle_registration,
                    'status'               => $order->status,
                    'amount'               => $order->amount,
                    'created_at'           => $order->created_at,
                ];
            });

        return response()->json([
            'data' => [
                'stats' => [
                    'total_users'    => $totalUsers,
                    'total_orders'   => $totalOrders,
                    'total_vehicles' => $totalVehicles,
                    'total_revenue'  => (float) $totalRevenue,
                ],
                'order_stats'    => $orderStats,
                'orders_per_day' => $ordersPerDay,
                'recent_orders'  => $recentOrders,
            ],
        ]);
    }
}
