<?php

namespace Tests\Feature;

use App\Mail\CheckoutConfirmationMail;
use App\Models\ApiSetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Slot;
use App\Models\User;
use App\Services\AdminSmtpMailer;
use App\Services\OrderConfirmationEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CheckoutConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_generates_password_for_new_user_and_stores_encrypted_order_copy(): void
    {
        Role::query()->create(['name' => Role::USER]);
        Setting::query()->updateOrCreate(['key' => 'address'], ['value' => '17 Flora Road, Coventry, CV6 4AU, United Kingdom']);
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
                        'distance' => ['value' => 5000],
                    ]],
                ]],
            ]),
        ]);
        $slot = Slot::query()->create([
            'day' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_bookings' => 1,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/public/checkout', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
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
            'tyre_unit_price' => 99.50,
        ]);

        $response->assertCreated();

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $order = Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertTrue($order->is_new_user);
        $this->assertNotNull($order->new_user_password_encrypted);

        $plainPassword = Crypt::decryptString((string) $order->new_user_password_encrypted);

        $this->assertGreaterThanOrEqual(12, strlen($plainPassword));
        $this->assertTrue(Hash::check($plainPassword, (string) $user->password));
    }

    public function test_confirmation_email_sends_once_and_clears_stored_new_user_password(): void
    {
        $role = Role::query()->create(['name' => Role::USER]);
        $user = User::query()->create([
            'name' => 'John Existing',
            'email' => 'john@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $slot = Slot::query()->create([
            'day' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_bookings' => 1,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'slot_id' => $slot->id,
            'fitting_date' => Carbon::now()->toDateString(),
            'service_type' => 'Tyre Fitting',
            'tyre_brand' => 'Brand',
            'tyre_model' => 'Model',
            'tyre_size' => '205/55R16',
            'tyre_quantity' => 4,
            'amount' => 120,
            'payment_provider' => 'stripe',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'is_new_user' => true,
            'new_user_password_encrypted' => Crypt::encryptString('TempPass123!'),
        ]);

        $smtpMailer = Mockery::mock(AdminSmtpMailer::class);
        $smtpMailer->shouldReceive('resolveSmtpConfig')
            ->once()
            ->andReturn([
                'mailer' => [
                    'transport' => 'smtp',
                ],
                'from_email' => 'admin@example.com',
                'from_name' => 'Matrix Admin',
            ]);

        $smtpMailer->shouldReceive('sendTo')
            ->once()
            ->withArgs(function (string $recipientEmail, CheckoutConfirmationMail $mailable): bool {
                return $recipientEmail === 'john@example.com'
                    && $mailable->plainPassword === 'TempPass123!'
                    && count($mailable->attachments()) === 1;
            })
            ->andReturn(true);

        $service = new OrderConfirmationEmailService($smtpMailer);
        $service->sendOnceForPaidOrder($order->id);
        $service->sendOnceForPaidOrder($order->id);

        $order->refresh();

        $this->assertNotNull($order->confirmation_email_sent_at);
        $this->assertNull($order->new_user_password_encrypted);
    }

    public function test_existing_user_confirmation_email_includes_generated_temporary_password(): void
    {
        $role = Role::query()->create(['name' => Role::USER]);
        $user = User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $slot = Slot::query()->create([
            'day' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_bookings' => 1,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'slot_id' => $slot->id,
            'fitting_date' => Carbon::now()->toDateString(),
            'service_type' => 'Tyre Fitting',
            'tyre_brand' => 'Brand',
            'tyre_model' => 'Model',
            'tyre_size' => '205/55R16',
            'tyre_quantity' => 4,
            'amount' => 120,
            'payment_provider' => 'stripe',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'is_new_user' => false,
        ]);

        $smtpMailer = Mockery::mock(AdminSmtpMailer::class);
        $smtpMailer->shouldReceive('resolveSmtpConfig')
            ->once()
            ->andReturn([
                'mailer' => [
                    'transport' => 'smtp',
                ],
                'from_email' => 'admin@example.com',
                'from_name' => 'Matrix Admin',
            ]);

        $temporaryPassword = null;
        $smtpMailer->shouldReceive('sendTo')
            ->once()
            ->withArgs(function (string $recipientEmail, CheckoutConfirmationMail $mailable) use (&$temporaryPassword): bool {
                $temporaryPassword = $mailable->plainPassword;

                return $recipientEmail === 'existing@example.com'
                    && is_string($temporaryPassword)
                    && strlen($temporaryPassword) >= 12
                    && count($mailable->attachments()) === 1;
            })
            ->andReturn(true);

        $service = new OrderConfirmationEmailService($smtpMailer);
        $service->sendOnceForPaidOrder($order->id);

        $order->refresh();
        $user->refresh();

        $this->assertNotNull($order->confirmation_email_sent_at);
        $this->assertIsString($temporaryPassword);
        $this->assertTrue(Hash::check((string) $temporaryPassword, (string) $user->password));
    }
}
