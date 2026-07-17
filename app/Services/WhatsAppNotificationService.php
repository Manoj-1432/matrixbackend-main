<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public function notifyNewOrder(Order $order): void
    {
        $order->loadMissing(['user', 'slot']);

        $ref        = $order->order_ref ?? 'ORD-'.str_pad((string) $order->id, 3, '0', STR_PAD_LEFT);
        $customer   = $order->user?->name ?? 'Guest';
        $phone      = $order->user?->phone ?? '—';
        $tyre       = trim("{$order->tyre_brand} {$order->tyre_model} {$order->tyre_size}");
        $qty        = $order->tyre_quantity ?? 1;
        $reg        = $order->vehicle_registration ?? '—';
        $amount     = '£'.number_format((float) $order->amount, 2);
        $date       = $order->fitting_date?->format('d M Y') ?? '—';
        $slotTime   = $order->slot ? "{$order->slot->start_time}–{$order->slot->end_time}" : '';

        $body = implode("\n", array_filter([
            "🔔 *New Order — {$ref}*",
            "👤 {$customer} | 📞 {$phone}",
            "🚗 {$reg}",
            "🛞 {$tyre} × {$qty}",
            "📅 {$date}" . ($slotTime ? " {$slotTime}" : ''),
            "💷 {$amount}",
        ]));

        $this->send($body);
    }

    public function notifyPaymentConfirmed(Order $order): void
    {
        $order->loadMissing('user');

        $ref      = $order->order_ref ?? 'ORD-'.str_pad((string) $order->id, 3, '0', STR_PAD_LEFT);
        $customer = $order->user?->name ?? 'Guest';
        $amount   = '£'.number_format((float) $order->amount, 2);

        $this->send("✅ *Payment Confirmed — {$ref}*\n👤 {$customer}\n💷 {$amount}");
    }

    private function send(string $body): void
    {
        $sid   = config('services.twilio.sid')   ?: env('TWILIO_ACCOUNT_SID');
        $token = config('services.twilio.token') ?: env('TWILIO_AUTH_TOKEN');
        $from  = config('services.twilio.whatsapp_from') ?: env('TWILIO_WHATSAPP_FROM');
        $to    = config('services.twilio.whatsapp_to')   ?: env('TWILIO_WHATSAPP_TO');

        if (! $sid || ! $token || ! $from || ! $to) {
            Log::debug('WhatsApp notification skipped: Twilio credentials not configured.');
            return;
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To'   => $to,
                    'Body' => $body,
                ]);

            if (! $response->successful()) {
                Log::warning('WhatsApp notification failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp notification exception.', ['error' => $e->getMessage()]);
        }
    }
}
