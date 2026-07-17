<?php

namespace App\Services;

use App\Mail\CheckoutConfirmationMail;
use App\Models\Order;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderConfirmationEmailService
{
    public function __construct(
        private readonly AdminSmtpMailer $smtpMailer,
    ) {}

    public function sendOnceForPaidOrder(int $orderId): void
    {
        try {
            DB::transaction(function () use ($orderId): void {
                $order = Order::query()
                    ->with(['user', 'slot'])
                    ->lockForUpdate()
                    ->find($orderId);

                if (! $order || ! $order->user || ! $order->paid_at || $order->confirmation_email_sent_at) {
                    return;
                }

                $password = null;
                $generatedPasswordForExistingUser = false;
                if ($order->is_new_user && $order->new_user_password_encrypted) {
                    $password = Crypt::decryptString($order->new_user_password_encrypted);
                } else {
                    $password = Str::password(14);
                    $generatedPasswordForExistingUser = true;
                }

                $smtpConfig = $this->smtpMailer->resolveSmtpConfig();
                if ($smtpConfig === null) {
                    Log::warning('Skipping order confirmation email: SMTP settings are disabled or incomplete.', [
                        'order_id' => $order->id,
                    ]);

                    return;
                }

                $sent = $this->smtpMailer->sendTo(
                    $order->user->email,
                    new CheckoutConfirmationMail(
                        $order,
                        new Address($smtpConfig['from_email'], $smtpConfig['from_name']),
                        $password,
                        ...$this->buildInvoiceAttachment($order)
                    )
                );

                if (! $sent) {
                    Log::warning('Order confirmation email skipped because SMTP delivery is not available.', [
                        'order_id' => $order->id,
                    ]);

                    return;
                }

                if ($generatedPasswordForExistingUser && $password) {
                    $order->user->update([
                        'password' => $password,
                    ]);
                }

                $order->update([
                    'confirmation_email_sent_at' => now(),
                    'new_user_password_encrypted' => null,
                ]);
            }, 3);
        } catch (\Throwable $e) {
            Log::error('Failed to send order confirmation email.', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0:string,1:string}|array{}
     */
    private function buildInvoiceAttachment(Order $order): array
    {
        try {
            $settings = $this->loadInvoiceSettings();
            $breakdown = $this->parseNotesBreakdown((string) ($order->notes ?? ''));

            $currencySymbol = $this->currencySymbolFor((string) ($settings['currency'] ?? 'GBP'));
            $quantity = max(1, (int) ($order->tyre_quantity ?? 1));
            $orderAmount = (float) $order->amount;

            $tyreSubtotal = isset($breakdown['subtotal'])
                ? (float) $breakdown['subtotal']
                : $orderAmount;
            $unitPrice = $quantity > 0 ? round($tyreSubtotal / $quantity, 2) : $tyreSubtotal;

            $platformFee = isset($breakdown['platform_fee_amount']) ? (float) $breakdown['platform_fee_amount'] : 0.0;
            $tpmsCharge = isset($breakdown['tpms_charge_amount']) ? (float) $breakdown['tpms_charge_amount'] : 0.0;
            $vatAmount = isset($breakdown['vat_amount']) ? (float) $breakdown['vat_amount'] : 0.0;
            $vatPercentage = isset($breakdown['vat_percentage']) ? (float) $breakdown['vat_percentage'] : 0.0;
            $total = isset($breakdown['total']) ? (float) $breakdown['total'] : $orderAmount;

            $lines = [];
            $tyreDescription = trim(implode(' ', array_filter([
                $order->tyre_brand,
                $order->tyre_model,
                $order->tyre_size ? "({$order->tyre_size})" : null,
            ])));
            if ($tyreDescription === '') {
                $tyreDescription = $order->service_type ?: 'Tyre fitting service';
            }
            $lines[] = [
                'description' => $tyreDescription,
                'qty' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $tyreSubtotal,
            ];
            if ($platformFee > 0) {
                $lines[] = [
                    'description' => 'Platform fee',
                    'qty' => 1,
                    'unit_price' => $platformFee,
                    'subtotal' => $platformFee,
                ];
            }
            if ($tpmsCharge > 0) {
                $lines[] = [
                    'description' => 'TPMS service',
                    'qty' => 1,
                    'unit_price' => $tpmsCharge,
                    'subtotal' => $tpmsCharge,
                ];
            }
            if ($vatAmount > 0) {
                $vatLabel = $vatPercentage > 0
                    ? sprintf('VAT (%s%%)', rtrim(rtrim(number_format($vatPercentage, 2), '0'), '.'))
                    : 'VAT';
                $lines[] = [
                    'description' => $vatLabel,
                    'qty' => 1,
                    'unit_price' => $vatAmount,
                    'subtotal' => $vatAmount,
                ];
            }

            $invoiceSubtotal = round(max($total - $vatAmount, 0.0), 2);
            $logoPath = $this->resolveLogoFilesystemPath((string) ($settings['logo_url'] ?? ''));
            $invoiceNumber = $this->formatInvoiceNumber((int) $order->id);
            $invoiceDate = $order->paid_at ?? $order->created_at ?? Carbon::now();

            $pdf = Pdf::loadView('invoices.order', [
                'order' => $order,
                'customer' => $order->user,
                'lines' => $lines,
                'subtotal' => $invoiceSubtotal,
                'total' => $total,
                'currencySymbol' => $currencySymbol,
                'brandName' => (string) ($settings['brand_name'] ?? 'Matrix Mobile Tyres'),
                'companyAddress' => (string) ($settings['address'] ?? ''),
                'companyPhone' => (string) ($settings['contact_number'] ?? ''),
                'companyEmail' => (string) ($settings['contact_email'] ?? ''),
                'vatNumber' => (string) ($settings['vat_number'] ?? ''),
                'logoPath' => $logoPath,
                'invoiceNumber' => $invoiceNumber,
                'invoiceDate' => $invoiceDate instanceof Carbon ? $invoiceDate : Carbon::parse((string) $invoiceDate),
            ])->setPaper('a4');

            return [$pdf->output(), "invoice-{$invoiceNumber}.pdf"];
        } catch (\Throwable $e) {
            Log::warning('Invoice PDF attachment generation failed; sending email without attachment.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string,string>
     */
    private function loadInvoiceSettings(): array
    {
        $keys = [
            'brand_name',
            'address',
            'contact_number',
            'contact_email',
            'vat_number',
            'logo_url',
            'currency',
        ];

        $rows = Setting::query()->whereIn('key', $keys)->pluck('value', 'key')->all();

        $map = [];
        foreach ($keys as $key) {
            $map[$key] = isset($rows[$key]) ? (string) $rows[$key] : '';
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function parseNotesBreakdown(string $notes): array
    {
        if ($notes === '') {
            return [];
        }

        $aliases = [
            'subtotal' => 'subtotal',
            'platform fee amount' => 'platform_fee_amount',
            'tpms charge amount' => 'tpms_charge_amount',
            'vat percentage' => 'vat_percentage',
            'vat amount' => 'vat_amount',
            'total' => 'total',
        ];

        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $notes) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $normalisedKey = strtolower(trim($key));
            if (! isset($aliases[$normalisedKey])) {
                continue;
            }
            $result[$aliases[$normalisedKey]] = trim($value);
        }

        return $result;
    }

    private function resolveLogoFilesystemPath(string $logoUrl): ?string
    {
        if ($logoUrl === '') {
            return null;
        }

        // Remote URL (R2, CDN, etc.) — fetch and convert to base64 data URI for DomPDF
        if (str_starts_with($logoUrl, 'http://') || str_starts_with($logoUrl, 'https://')) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(5)->get($logoUrl);
                if ($response->successful()) {
                    $mime = $response->header('Content-Type') ?: 'image/png';
                    $mime = explode(';', $mime)[0];
                    return 'data:'.$mime.';base64,'.base64_encode($response->body());
                }
            } catch (\Throwable) {
                // Fall through to null
            }
            return null;
        }

        if (preg_match('~/storage/logos/([^/?#]+)$~', $logoUrl, $m) === 1) {
            $candidate = public_path('storage/logos/'.$m[1]);
            return file_exists($candidate) ? $candidate : null;
        }

        if (str_starts_with($logoUrl, '/')) {
            $candidate = public_path(ltrim($logoUrl, '/'));
            return file_exists($candidate) ? $candidate : null;
        }

        return null;
    }

    private function currencySymbolFor(string $currencyCode): string
    {
        return match (strtoupper($currencyCode)) {
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'INR' => '₹',
            'AUD', 'CAD', 'NZD' => '$',
            default => $currencyCode !== '' ? $currencyCode.' ' : '£',
        };
    }

    private function formatInvoiceNumber(int $id): string
    {
        if ($id >= 1_000_000_000) {
            return 'INV-M'.substr((string) $id, -4);
        }

        return 'INV-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
}
