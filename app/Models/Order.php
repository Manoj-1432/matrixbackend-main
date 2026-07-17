<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    /** @var list<string> */
    public const PAID_PAYMENT_STATUSES = ['paid', 'succeeded', 'completed', 'captured', 'no_payment_required'];

    protected $fillable = [
        'user_id',
        'slot_id',
        'fitting_date',
        'vehicle_registration',
        'vehicle_make',
        'vehicle_model',
        'vehicle_year',
        'service_type',
        'tyre_brand',
        'tyre_model',
        'tyre_size',
        'tyre_quantity',
        'amount',
        'delivery_charge',
        'delivery_distance_miles',
        'payment_provider',
        'payment_status',
        'stripe_mode',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
        'status',
        'notes',
        'customer_comment',
        'is_new_user',
        'new_user_password_encrypted',
        'confirmation_email_sent_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'delivery_distance_miles' => 'decimal:2',
        'paid_at' => 'datetime',
        'fitting_date' => 'date',
        'vehicle_year' => 'integer',
        'is_new_user' => 'boolean',
        'confirmation_email_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * Matches CheckoutController::isOrderPaid semantics for list filtering.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeConsideredPaid(Builder $query): Builder
    {
        $statuses = self::PAID_PAYMENT_STATUSES;
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return $query->where(function (Builder $q) use ($statuses, $placeholders): void {
            $q->whereNotNull('paid_at')
                ->orWhereRaw('LOWER(COALESCE(payment_status, "")) IN ('.$placeholders.')', $statuses)
                ->orWhere('status', 'completed');
        });
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeConsideredUnpaid(Builder $query): Builder
    {
        $statuses = self::PAID_PAYMENT_STATUSES;
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return $query->where(function (Builder $q) use ($statuses, $placeholders): void {
            $q->whereNull('paid_at')
                ->where(function (Builder $q2) use ($statuses, $placeholders): void {
                    $q2->whereNull('payment_status')
                        ->orWhereRaw('LOWER(TRIM(payment_status)) NOT IN ('.$placeholders.')', $statuses);
                })
                ->where('status', '!=', 'completed');
        });
    }

    /**
     * Paid orders that reserve a fitting slot on the public calendar.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeBlocksFittingSlot(Builder $query): Builder
    {
        return $query
            ->whereNotNull('slot_id')
            ->whereNotNull('fitting_date')
            ->consideredPaid();
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeForFittingSlotOnDate(Builder $query, int $slotId, string $fittingDate): Builder
    {
        return $query
            ->where('slot_id', $slotId)
            ->whereDate('fitting_date', $fittingDate);
    }

    public static function fittingSlotTakenByAnotherPaidOrder(int $slotId, string $fittingDate, ?int $ignoreOrderId = null): bool
    {
        $query = static::query()
            ->forFittingSlotOnDate($slotId, $fittingDate)
            ->blocksFittingSlot();

        if ($ignoreOrderId !== null) {
            $query->where('id', '!=', $ignoreOrderId);
        }

        return $query->exists();
    }
}
