<?php

namespace Tests\Feature;

use App\Models\ApiSetting;
use App\Models\DeliveryCharge;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->updateOrCreate(['key' => 'address'], ['value' => '17 Flora Road, Coventry, CV6 4AU, United Kingdom']);
        Setting::query()->updateOrCreate(['key' => 'currency'], ['value' => 'GBP']);

        ApiSetting::query()->updateOrCreate(
            ['key_name' => 'google_maps'],
            [
                'label' => 'Google Maps Platform',
                'description' => 'Test',
                'icon_type' => 'maps',
                'value' => 'test-google-key',
                'is_enabled' => true,
            ]
        );
    }

    public function test_delivery_quote_matches_active_tier(): void
    {
        DeliveryCharge::query()->create([
            'from_distance' => 0,
            'to_distance' => 15,
            'charge' => 15.00,
            'status' => 'active',
        ]);

        $this->fakeGoogleMapsApis(distanceMeters: 19312);

        $response = $this->postJson('/api/public/delivery-quote', [
            'address' => '10 Downing Street',
            'city' => 'London',
            'postcode' => 'SW1A 2AA',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.delivery_charge', 15)
            ->assertJsonPath('data.out_of_range', false)
            ->assertJsonPath('data.matched_tier.from', 0)
            ->assertJsonPath('data.matched_tier.to', 15);
    }

    public function test_delivery_quote_returns_zero_when_outside_all_tiers(): void
    {
        DeliveryCharge::query()->create([
            'from_distance' => 0,
            'to_distance' => 10,
            'charge' => 10.00,
            'status' => 'active',
        ]);

        $this->fakeGoogleMapsApis(distanceMeters: 32186);

        $response = $this->postJson('/api/public/delivery-quote', [
            'address' => '1 Far Away Road',
            'city' => 'Manchester',
            'postcode' => 'M1 1AA',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.delivery_charge', 0)
            ->assertJsonPath('data.out_of_range', true)
            ->assertJsonPath('data.matched_tier', null);
    }

    public function test_delivery_quote_requires_business_address(): void
    {
        Setting::query()->where('key', 'address')->update(['value' => null]);

        $response = $this->postJson('/api/public/delivery-quote', [
            'address' => '123 Main Street',
            'city' => 'London',
            'postcode' => 'SW1A 1AA',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Business address is not configured.');
    }

    public function test_checkout_includes_delivery_charge_in_total_and_order(): void
    {
        Role::query()->create(['name' => Role::USER]);

        DeliveryCharge::query()->create([
            'from_distance' => 0,
            'to_distance' => 20,
            'charge' => 12.50,
            'status' => 'active',
        ]);

        $slot = Slot::query()->create([
            'day' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_bookings' => 1,
            'status' => 'active',
        ]);

        $this->fakeGoogleMapsApis(distanceMeters: 16093);

        $response = $this->postJson('/api/public/checkout', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane-delivery@example.com',
            'phone' => '07000000000',
            'address' => '123 Main Street',
            'city' => 'London',
            'postcode' => 'SW1A 1AA',
            'slot_id' => $slot->id,
            'fitting_date' => Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d'),
            'vehicle_registration' => 'AB12CDE',
            'vehicle_make' => 'Ford',
            'vehicle_model' => 'Focus',
            'tyre_brand' => 'Michelin',
            'tyre_model' => 'Pilot',
            'tyre_size' => '225/45R17',
            'tyre_quantity' => 2,
            'tyre_unit_price' => 100.00,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.summary.delivery_charge', 12.5)
            ->assertJsonPath('data.summary.subtotal', 200)
            ->assertJsonPath('data.summary.total', 212.5);

        $user = User::query()->where('email', 'jane-delivery@example.com')->firstOrFail();
        $order = $user->orders()->latest('id')->firstOrFail();

        $this->assertSame('12.50', (string) $order->delivery_charge);
        $this->assertNotNull($order->delivery_distance_miles);
        $this->assertStringContainsString('Delivery Charge: 12.5', (string) $order->notes);
        $this->assertSame('212.50', (string) $order->amount);
    }

    private function fakeGoogleMapsApis(int $distanceMeters): void
    {
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => Http::sequence()
                ->push([
                    'status' => 'OK',
                    'results' => [[
                        'geometry' => ['location' => ['lat' => 52.4068, 'lng' => -1.5197]],
                    ]],
                ])
                ->push([
                    'status' => 'OK',
                    'results' => [[
                        'geometry' => ['location' => ['lat' => 51.5034, 'lng' => -0.1276]],
                    ]],
                ]),
            'maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => ['value' => $distanceMeters],
                    ]],
                ]],
            ]),
        ]);
    }
}
