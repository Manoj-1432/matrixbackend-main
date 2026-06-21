<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill legacy paid orders that missed webhook status updates.
        DB::table('orders')
            ->where(function ($q) {
                $q->where('status', 'completed')
                  ->orWhereNotNull('stripe_payment_intent_id');
            })
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', ['paid', 'succeeded', 'completed', 'captured']);
            })
            ->update([
                'payment_status' => 'paid',
                'payment_provider' => DB::raw("COALESCE(payment_provider, 'stripe')"),
            ]);

        DB::table('orders')
            ->where(function ($q) {
                $q->where('status', 'completed')
                  ->orWhereIn('payment_status', ['paid', 'succeeded', 'completed', 'captured']);
            })
            ->whereNull('paid_at')
            ->update([
                'paid_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down(): void
    {
        // Non-destructive rollback; do not unset payment truthy fields.
    }
};
