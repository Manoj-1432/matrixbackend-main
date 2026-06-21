<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderConfirmationEmailService;
use App\Services\StripeSettings;
use Illuminate\Console\Command;

class SyncStripePaymentsCommand extends Command
{
    protected $signature = 'orders:sync-stripe-payments {--limit=500 : Max unpaid orders to scan} {--dry-run : Show what would update without writing DB}';

    protected $description = 'Sync unpaid orders with Stripe checkout sessions and mark paid when confirmed.';

    public function handle(StripeSettings $settings, OrderConfirmationEmailService $orderConfirmationEmailService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $paidStatuses = ['paid', 'succeeded', 'completed', 'captured', 'no_payment_required'];
        $orders = Order::query()
            ->whereNotNull('stripe_checkout_session_id')
            ->where(function ($q) use ($paidStatuses) {
                $q->whereNull('paid_at')
                  ->where(function ($q2) use ($paidStatuses) {
                      $q2->whereNull('payment_status')
                         ->orWhereNotIn('payment_status', $paidStatuses);
                  });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No unpaid Stripe orders found to reconcile.');
            return self::SUCCESS;
        }

        $scanned = 0;
        $updated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $scanned++;
            $preferredMode = in_array((string) $order->stripe_mode, ['test', 'live'], true)
                ? (string) $order->stripe_mode
                : null;

            try {
                [$session, $mode] = $settings->retrieveCheckoutSession(
                    (string) $order->stripe_checkout_session_id,
                    $preferredMode,
                );
                $sessionPaymentStatus = (string) ($session->payment_status ?? '');

                if (! in_array($sessionPaymentStatus, ['paid', 'no_payment_required'], true)) {
                    continue;
                }

                if (! $dryRun) {
                    $order->update([
                        'payment_provider' => 'stripe',
                        'payment_status' => 'paid',
                        'status' => $order->status === 'pending' ? 'processing' : $order->status,
                        'stripe_mode' => $mode,
                        'stripe_checkout_session_id' => $session->id,
                        'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : $order->stripe_payment_intent_id,
                        'paid_at' => $order->paid_at ?: now(),
                    ]);
                    $orderConfirmationEmailService->sendOnceForPaidOrder($order->id);
                }
                $updated++;
                $this->line("Order #{$order->id}: marked paid (session {$session->id}, {$mode}).");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Order #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("Scanned: {$scanned}, Updated: {$updated}, Failed: {$failed}.");
        if ($dryRun) {
            $this->comment('Dry run mode: no database rows were changed.');
        }

        return self::SUCCESS;
    }
}
