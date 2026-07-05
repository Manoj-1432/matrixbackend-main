<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Slot;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OrdersController extends Controller
{
    /**
     * List orders with optional filters + summary stats.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['user:id,name,email,phone,address', 'slot'])
            ->orderBy('created_at', 'desc');

        // Search by customer name / vehicle registration
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('vehicle_registration', 'like', "%{$search}%")
                  ->orWhere('vehicle_make', 'like', "%{$search}%")
                  ->orWhere('vehicle_model', 'like', "%{$search}%")
                  ->orWhere('tyre_brand', 'like', "%{$search}%")
                  ->orWhere('tyre_model', 'like', "%{$search}%")
                  ->orWhere('tyre_size', 'like', "%{$search}%")
                  ->orWhere('service_type', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by payment status
        if ($payment = $request->query('payment')) {
            $paidStatuses = ['paid', 'succeeded', 'completed', 'captured'];
            if ($payment === 'paid') {
                $query->where(function ($q) use ($paidStatuses) {
                    $q->whereNotNull('paid_at')
                      ->orWhereIn('payment_status', $paidStatuses)
                      ->orWhere('status', 'completed');
                });
            } elseif ($payment === 'not_paid') {
                $query->whereNull('paid_at')
                      ->where('status', '!=', 'completed')
                      ->where(function ($q) use ($paidStatuses) {
                          $q->whereNull('payment_status')
                            ->orWhereNotIn('payment_status', $paidStatuses);
                      });
            }
        }

        $perPage  = min((int) ($request->query('per_page', 25)), 100);
        $paginated = $query->paginate($perPage);

        // Aggregate stats (unfiltered, always DB-wide)
        $stats = [
            'total'      => Order::count(),
            'pending'    => Order::where('status', 'pending')->count(),
            'processing' => Order::where('status', 'processing')->count(),
            'completed'  => Order::where('status', 'completed')->count(),
            'cancelled'  => Order::where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'data' => [
                'orders' => $paginated->items(),
                'meta'   => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                ],
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Show a single order.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with(['user:id,name,email,phone', 'slot'])->findOrFail($id);

        return response()->json(['data' => $order]);
    }

    /**
     * Create a new order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'              => 'nullable|exists:users,id',
            'slot_id'              => 'nullable|exists:slots,id',
            'fitting_date'         => 'nullable|date',
            'vehicle_registration' => 'nullable|string|max:20',
            'vehicle_make'         => 'nullable|string|max:100',
            'vehicle_model'        => 'nullable|string|max:100',
            'service_type'         => 'required|string|max:255',
            'tyre_brand'           => 'nullable|string|max:100',
            'tyre_model'           => 'nullable|string|max:100',
            'tyre_size'            => 'nullable|string|max:100',
            'tyre_quantity'        => 'nullable|integer|min:1',
            'amount'               => 'required|numeric|min:0',
            'payment_provider'     => 'nullable|string|max:50',
            'payment_status'       => 'nullable|string|max:50',
            'paid_at'              => 'nullable|date',
            'status'               => ['required', Rule::in(['pending', 'processing', 'completed', 'cancelled'])],
            'notes'                => 'nullable|string|max:2000',
        ]);

        $slotId = isset($validated['slot_id']) ? (int) $validated['slot_id'] : null;
        $fittingDate = isset($validated['fitting_date']) ? (string) $validated['fitting_date'] : null;
        if ($slotId && $fittingDate) {
            $this->ensureSlotBookingRules($slotId, $fittingDate, null);
        }

        $order = Order::create($validated);
        $order->load(['user:id,name,email,phone', 'slot']);

        return response()->json(['data' => $order], 201);
    }

    /**
     * Update an existing order.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'user_id'              => 'nullable|exists:users,id',
            'slot_id'              => 'nullable|exists:slots,id',
            'fitting_date'         => 'nullable|date',
            'vehicle_registration' => 'nullable|string|max:20',
            'vehicle_make'         => 'nullable|string|max:100',
            'vehicle_model'        => 'nullable|string|max:100',
            'service_type'         => 'sometimes|required|string|max:255',
            'tyre_brand'           => 'nullable|string|max:100',
            'tyre_model'           => 'nullable|string|max:100',
            'tyre_size'            => 'nullable|string|max:100',
            'tyre_quantity'        => 'nullable|integer|min:1',
            'amount'               => 'sometimes|numeric|min:0',
            'payment_provider'     => 'nullable|string|max:50',
            'payment_status'       => 'nullable|string|max:50',
            'paid_at'              => 'nullable|date',
            'status'               => ['sometimes', Rule::in(['pending', 'processing', 'completed', 'cancelled'])],
            'notes'                => 'nullable|string|max:2000',
        ]);

        $effectiveSlotId = array_key_exists('slot_id', $validated)
            ? ($validated['slot_id'] !== null ? (int) $validated['slot_id'] : null)
            : ($order->slot_id !== null ? (int) $order->slot_id : null);
        $effectiveFittingDate = array_key_exists('fitting_date', $validated)
            ? ($validated['fitting_date'] !== null ? (string) $validated['fitting_date'] : null)
            : ($order->fitting_date?->format('Y-m-d'));
        if ($effectiveSlotId && $effectiveFittingDate) {
            $this->ensureSlotBookingRules($effectiveSlotId, $effectiveFittingDate, $order->id);
        }

        $order->update($validated);
        $order->load(['user:id,name,email,phone', 'slot']);

        return response()->json(['data' => $order]);
    }

    /**
     * Delete an order.
     */
    public function destroy(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $order->delete();

        return response()->json(['message' => 'Order deleted successfully.']);
    }

    /**
     * Patch only the status of an order.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'processing', 'completed', 'cancelled'])],
        ]);

        $order->update(['status' => $validated['status']]);
        $order->load(['user:id,name,email,phone', 'slot']);

        return response()->json(['data' => $order]);
    }

    /**
     * GET /admin/orders/{id}/invoice
     *
     * Render the order as a styled PDF invoice and stream it as a download.
     */
    public function invoice(int $id): SymfonyResponse
    {
        $order = Order::with([
            'user:id,name,email,phone,address,city,postcode',
            'slot',
        ])->findOrFail($id);

        $settings = $this->loadInvoiceSettings();
        $breakdown = $this->parseNotesBreakdown((string) ($order->notes ?? ''));

        $currencySymbol = $this->currencySymbolFor((string) ($settings['currency'] ?? 'GBP'));
        $quantity = max(1, (int) ($order->tyre_quantity ?? 1));
        $orderAmount = (float) $order->amount;

        // Derive tyre subtotal (line total for the tyre row).
        $tyreSubtotal = isset($breakdown['subtotal'])
            ? (float) $breakdown['subtotal']
            : $orderAmount;
        $unitPrice = $quantity > 0 ? round($tyreSubtotal / $quantity, 2) : $tyreSubtotal;

        $platformFee = isset($breakdown['platform_fee_amount']) ? (float) $breakdown['platform_fee_amount'] : 0.0;
        $tpmsCharge = isset($breakdown['tpms_charge_amount']) ? (float) $breakdown['tpms_charge_amount'] : 0.0;
        $deliveryCharge = isset($breakdown['delivery_charge'])
            ? (float) $breakdown['delivery_charge']
            : (float) ($order->delivery_charge ?? 0);
        $vatAmount = isset($breakdown['vat_amount']) ? (float) $breakdown['vat_amount'] : 0.0;
        $vatPercentage = isset($breakdown['vat_percentage']) ? (float) $breakdown['vat_percentage'] : 0.0;
        $total = isset($breakdown['total']) ? (float) $breakdown['total'] : $orderAmount;

        // Build the line items rendered in the table.
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
        if ($deliveryCharge > 0) {
            $deliveryDescription = 'Delivery charge';
            if (isset($breakdown['delivery_distance_miles']) && is_numeric($breakdown['delivery_distance_miles'])) {
                $deliveryDescription = sprintf(
                    'Delivery charge (%s miles)',
                    rtrim(rtrim(number_format((float) $breakdown['delivery_distance_miles'], 1), '0'), '.')
                );
            }
            $lines[] = [
                'description' => $deliveryDescription,
                'qty' => 1,
                'unit_price' => $deliveryCharge,
                'subtotal' => $deliveryCharge,
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

        // Subtotal for the invoice equals total minus VAT (so VAT can render as its own line).
        $invoiceSubtotal = round(max($total - $vatAmount, 0.0), 2);

        $logoPath = $this->resolveLogoFilesystemPath((string) ($settings['logo_url'] ?? ''));

        $invoiceNumber = $this->formatInvoiceNumber((int) $order->id);
        $invoiceDate = $order->paid_at ?? $order->created_at ?? Carbon::now();

        $payload = [
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
        ];

        $pdf = Pdf::loadView('invoices.order', $payload)->setPaper('a4');

        return $pdf->download("invoice-{$invoiceNumber}.pdf");
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
     * Parse the structured key/value lines that CheckoutController writes into Order.notes.
     *
     * @return array<string, string>
     */
    private function parseNotesBreakdown(string $notes): array
    {
        if ($notes === '') {
            return [];
        }

        $aliases = [
            'subtotal' => 'subtotal',
            'platform fee enabled' => 'platform_fee_enabled',
            'platform fee amount' => 'platform_fee_amount',
            'tpms charge enabled' => 'tpms_charge_enabled',
            'customer tpms add-on' => 'include_tpms',
            'tpms charge amount' => 'tpms_charge_amount',
            'delivery distance miles' => 'delivery_distance_miles',
            'delivery charge' => 'delivery_charge',
            'delivery out of range' => 'delivery_out_of_range',
            'tax base' => 'tax_base',
            'vat enabled' => 'vat_enabled',
            'vat percentage' => 'vat_percentage',
            'vat amount' => 'vat_amount',
            'currency' => 'currency',
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

        // Settings UI stores values like "/storage/logos/foo.png" or full URLs.
        if (preg_match('~/storage/logos/([^/?#]+)$~', $logoUrl, $m) === 1) {
            $candidate = public_path('storage/logos/' . $m[1]);

            return file_exists($candidate) ? $candidate : null;
        }

        if (str_starts_with($logoUrl, '/')) {
            $candidate = public_path(ltrim($logoUrl, '/'));

            return file_exists($candidate) ? $candidate : null;
        }

        // Remote URLs are not embeddable by DomPDF without enable_remote; fall back to text.
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
            default => $currencyCode !== '' ? $currencyCode . ' ' : '£',
        };
    }

    private function formatInvoiceNumber(int $id): string
    {
        if ($id >= 1_000_000_000) {
            return 'INV-M' . substr((string) $id, -4);
        }

        return 'INV-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function ensureSlotBookingRules(int $slotId, string $fittingDate, ?int $ignoreOrderId): void
    {
        $fittingDateCarbon = Carbon::parse($fittingDate)->startOfDay();
        $fittingDateString = $fittingDateCarbon->toDateString();

        $slot = Slot::query()
            ->where('id', $slotId)
            ->where('status', 'active')
            ->first();

        if (! $slot) {
            throw ValidationException::withMessages([
                'slot_id' => ['Invalid or inactive slot.'],
            ]);
        }

        $dayFromDate = strtolower($fittingDateCarbon->format('l'));
        $slotDay = strtolower((string) $slot->day);
        if ($dayFromDate !== $slotDay) {
            throw ValidationException::withMessages([
                'fitting_date' => ['The selected date does not fall on the same weekday as this slot.'],
            ]);
        }

        $bookingQuery = Order::query()
            ->forFittingSlotOnDate((int) $slot->id, $fittingDateString)
            ->blocksFittingSlot();

        if ($ignoreOrderId) {
            $bookingQuery->where('id', '!=', $ignoreOrderId);
        }

        if ($bookingQuery->exists()) {
            throw ValidationException::withMessages([
                'slot_id' => ['This slot is already booked for the selected date.'],
            ]);
        }
    }
}
