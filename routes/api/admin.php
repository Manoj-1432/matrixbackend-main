<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\ApiSettingsController;
use App\Http\Controllers\Admin\AttributesController;
use App\Http\Controllers\Admin\BannersController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DvlaTestController;
use App\Http\Controllers\Admin\FuelEfficiencyController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\SeasonController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SizeController;
use App\Http\Controllers\Admin\SpeedRatingController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\TyreTypeController;
use App\Http\Controllers\Admin\TyresController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\VehiclesController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| Mounted under `/api/...` from `routes/api.php`.
*/

Route::prefix('admin')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'role:'.Role::ADMIN])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);

        Route::get('profile', [AdminProfileController::class, 'profile']);

        // Dashboard stats endpoint
        Route::get('dashboard', [DashboardController::class, 'index']);

        // WhatsApp test — GET /api/admin/test-whatsapp
        Route::get('test-whatsapp', function () {
            $sid   = config('services.twilio.sid')   ?: env('TWILIO_ACCOUNT_SID');
            $token = config('services.twilio.token') ?: env('TWILIO_AUTH_TOKEN');
            $from  = config('services.twilio.whatsapp_from') ?: env('TWILIO_WHATSAPP_FROM');
            $to    = config('services.twilio.whatsapp_to')   ?: env('TWILIO_WHATSAPP_TO');

            if (! $sid || ! $token || ! $from || ! $to) {
                return response()->json(['data' => [
                    'configured' => false,
                    'missing' => array_keys(array_filter([
                        'TWILIO_ACCOUNT_SID'   => ! $sid,
                        'TWILIO_AUTH_TOKEN'    => ! $token,
                        'TWILIO_WHATSAPP_FROM' => ! $from,
                        'TWILIO_WHATSAPP_TO'   => ! $to,
                    ])),
                ]]);
            }

            try {
                $response = \Illuminate\Support\Facades\Http::withBasicAuth($sid, $token)
                    ->asForm()
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                        'From' => $from,
                        'To'   => $to,
                        'Body' => '✅ Matrix Tyres WhatsApp test message.',
                    ]);

                return response()->json(['data' => [
                    'configured' => true,
                    'from'       => $from,
                    'to'         => $to,
                    'http_status' => $response->status(),
                    'twilio_response' => $response->json(),
                    'success' => $response->successful(),
                ]]);
            } catch (\Throwable $e) {
                return response()->json(['data' => [
                    'configured' => true,
                    'error' => $e->getMessage(),
                ]]);
            }
        });

        Route::get('users', [UsersController::class, 'index']);
        Route::post('users', [UsersController::class, 'store']);
        Route::patch('users/bulk-update', [UsersController::class, 'bulkUpdate']);
        Route::delete('users/bulk-delete', [UsersController::class, 'bulkDelete']);
        Route::get('users/template', [UsersController::class, 'template']);
        Route::get('users/export', [UsersController::class, 'export']);
        Route::post('users/import', [UsersController::class, 'import']);
        Route::put('users/{id}', [UsersController::class, 'update'])->whereNumber('id');
        Route::delete('users/{id}', [UsersController::class, 'destroy'])->whereNumber('id');

        Route::get('api-settings', [ApiSettingsController::class, 'index']);
        Route::put('api-settings/{id}', [ApiSettingsController::class, 'update'])->whereNumber('id');
        Route::patch('api-settings/{id}/toggle', [ApiSettingsController::class, 'toggle'])->whereNumber('id');
        Route::post('stripe/test', [ApiSettingsController::class, 'testStripe']);

        Route::post('dvla-test', [DvlaTestController::class, 'lookup']);

        Route::get('system-update', [SystemUpdateController::class, 'status']);
        Route::post('system-update', [SystemUpdateController::class, 'run']);

        // General & business settings (all admins)
        Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::get('settings',  [SettingsController::class, 'index']);
        Route::post('settings', [SettingsController::class, 'store']);
        Route::put('settings',  [SettingsController::class, 'update']);

        // Module stubs (admin + super admin)
        Route::get('vehicles', [VehiclesController::class, 'index']);
        Route::post('vehicles', [VehiclesController::class, 'store']);
        Route::put('vehicles/{id}', [VehiclesController::class, 'update']);
        Route::delete('vehicles/{id}', [VehiclesController::class, 'destroy']);

        Route::get('orders/notifications', [OrdersController::class, 'notifications']);
        Route::get('orders/vehicle-lookup', [OrdersController::class, 'vehicleLookup']);
        Route::get('orders', [OrdersController::class, 'index']);
        Route::post('orders', [OrdersController::class, 'store']);
        Route::get('orders/{id}', [OrdersController::class, 'show'])->whereNumber('id');
        Route::get('orders/{id}/invoice', [OrdersController::class, 'invoice'])->whereNumber('id');
        Route::put('orders/{id}', [OrdersController::class, 'update'])->whereNumber('id');
        Route::patch('orders/{id}/status', [OrdersController::class, 'updateStatus'])->whereNumber('id');
        Route::delete('orders/{id}', [OrdersController::class, 'destroy'])->whereNumber('id');

        Route::get('notifications', [NotificationsController::class, 'index']);
        Route::post('notifications', [NotificationsController::class, 'store']);
        Route::put('notifications/{id}', [NotificationsController::class, 'update'])->whereNumber('id');
        Route::delete('notifications/{id}', [NotificationsController::class, 'destroy'])->whereNumber('id');

        Route::post('banners/upload-image', [BannersController::class, 'uploadImage']);
        Route::get('banners', [BannersController::class, 'index']);
        Route::post('banners', [BannersController::class, 'store']);
        Route::put('banners/{id}', [BannersController::class, 'update'])->whereNumber('id');
        Route::delete('banners/{id}', [BannersController::class, 'destroy'])->whereNumber('id');

        Route::get('tyres', [TyresController::class, 'index']);
        Route::post('ai/generate-tyre-description', [TyresController::class, 'generateDescription']);
        Route::get('tyres/export', [TyresController::class, 'export']);
        Route::get('tyres/template', [TyresController::class, 'template']);
        Route::post('tyres/import', [TyresController::class, 'import']);
        Route::patch('tyres/bulk-status', [TyresController::class, 'bulkUpdateStatus']);
        Route::delete('tyres/bulk-delete', [TyresController::class, 'bulkDelete']);
        Route::post('tyres', [TyresController::class, 'store']);
        Route::put('tyres/{id}', [TyresController::class, 'update']);
        Route::delete('tyres/{id}', [TyresController::class, 'destroy']);

        Route::post('attributes/brand/logo', [BrandController::class, 'uploadLogo']);
        Route::post('attributes/brand/generate', [BrandController::class, 'generateWithAi']);
        Route::get('attributes/brand/export', [BrandController::class, 'export']);
        Route::get('attributes/brand/template', [BrandController::class, 'template']);
        Route::post('attributes/brand/import', [BrandController::class, 'import']);
        Route::delete('attributes/brand/bulk-delete', [BrandController::class, 'bulkDelete']);
        Route::get('attributes/brand', [BrandController::class, 'index']);
        Route::post('attributes/brand', [BrandController::class, 'store']);
        Route::put('attributes/brand/{id}', [BrandController::class, 'update'])->whereNumber('id');
        Route::delete('attributes/brand/{id}', [BrandController::class, 'destroy'])->whereNumber('id');

        Route::get('attributes/size', [SizeController::class, 'index']);
        Route::post('ai/generate-sizes', [SizeController::class, 'generateWithAi']);
        Route::get('attributes/size/export', [SizeController::class, 'export']);
        Route::get('attributes/size/template', [SizeController::class, 'template']);
        Route::post('attributes/size/import', [SizeController::class, 'import']);
        Route::patch('attributes/size/bulk-update', [SizeController::class, 'bulkUpdate']);
        Route::delete('attributes/size/bulk-delete', [SizeController::class, 'bulkDelete']);
        Route::post('attributes/size', [SizeController::class, 'store']);
        Route::put('attributes/size/{id}', [SizeController::class, 'update'])->whereNumber('id');
        Route::delete('attributes/size/{id}', [SizeController::class, 'destroy'])->whereNumber('id');

        Route::get('attributes/season', [SeasonController::class, 'index']);
        Route::get('attributes/season/export', [SeasonController::class, 'export']);
        Route::get('attributes/season/template', [SeasonController::class, 'template']);
        Route::post('attributes/season/import', [SeasonController::class, 'import']);
        Route::patch('attributes/season/bulk-update', [SeasonController::class, 'bulkUpdate']);
        Route::delete('attributes/season/bulk-delete', [SeasonController::class, 'bulkDelete']);
        Route::post('attributes/season', [SeasonController::class, 'store']);
        Route::put('attributes/season/{id}', [SeasonController::class, 'update'])->whereNumber('id');
        Route::delete('attributes/season/{id}', [SeasonController::class, 'destroy'])->whereNumber('id');

        Route::get('attributes/tyre-type', [TyreTypeController::class, 'index']);
        Route::get('attributes/tyre-type/export', [TyreTypeController::class, 'export']);
        Route::get('attributes/tyre-type/template', [TyreTypeController::class, 'template']);
        Route::post('attributes/tyre-type/import', [TyreTypeController::class, 'import']);
        Route::patch('attributes/tyre-type/bulk-update', [TyreTypeController::class, 'bulkUpdate']);
        Route::delete('attributes/tyre-type/bulk-delete', [TyreTypeController::class, 'bulkDelete']);
        Route::post('attributes/tyre-type', [TyreTypeController::class, 'store']);
        Route::put('attributes/tyre-type/{id}', [TyreTypeController::class, 'update'])->whereNumber('id');
        Route::delete('attributes/tyre-type/{id}', [TyreTypeController::class, 'destroy'])->whereNumber('id');

        Route::get('attributes/fuel-efficiency', [FuelEfficiencyController::class, 'index']);
        Route::get('attributes/fuel-efficiency/export', [FuelEfficiencyController::class, 'export']);
        Route::get('attributes/fuel-efficiency/template', [FuelEfficiencyController::class, 'template']);
        Route::post('attributes/fuel-efficiency/import', [FuelEfficiencyController::class, 'import']);
        Route::delete('attributes/fuel-efficiency/bulk-delete', [FuelEfficiencyController::class, 'bulkDelete']);
        Route::post('attributes/fuel-efficiency', [FuelEfficiencyController::class, 'store']);
        Route::put('attributes/fuel-efficiency/{id}', [FuelEfficiencyController::class, 'update'])->whereNumber('id');
        Route::delete('attributes/fuel-efficiency/{id}', [FuelEfficiencyController::class, 'destroy'])->whereNumber('id');

        Route::get('attributes/speed-rating', [SpeedRatingController::class, 'index']);
        Route::get('attributes/speed-rating/export', [SpeedRatingController::class, 'export']);
        Route::get('attributes/speed-rating/template', [SpeedRatingController::class, 'template']);
        Route::post('attributes/speed-rating/import', [SpeedRatingController::class, 'import']);
        Route::delete('attributes/speed-rating/bulk-delete', [SpeedRatingController::class, 'bulkDelete']);
        Route::post('attributes/speed-rating', [SpeedRatingController::class, 'store']);
        Route::put('attributes/speed-rating/{id}', [SpeedRatingController::class, 'update'])->whereNumber('id');
        Route::delete('attributes/speed-rating/{id}', [SpeedRatingController::class, 'destroy'])->whereNumber('id');

        Route::get('coupons', [\App\Http\Controllers\Admin\CouponController::class, 'index']);
        Route::post('coupons', [\App\Http\Controllers\Admin\CouponController::class, 'store']);
        Route::put('coupons/{id}', [\App\Http\Controllers\Admin\CouponController::class, 'update'])->whereNumber('id');
        Route::delete('coupons/{id}', [\App\Http\Controllers\Admin\CouponController::class, 'destroy'])->whereNumber('id');

        Route::get('slots', [\App\Http\Controllers\Admin\SlotController::class, 'index']);
        Route::post('slots/bulk-generate', [\App\Http\Controllers\Admin\SlotController::class, 'bulkGenerate']);
        Route::post('slots', [\App\Http\Controllers\Admin\SlotController::class, 'store']);
        Route::put('slots/{id}', [\App\Http\Controllers\Admin\SlotController::class, 'update'])->whereNumber('id');
        Route::patch('slots/{id}/toggle-status', [\App\Http\Controllers\Admin\SlotController::class, 'toggleStatus'])->whereNumber('id');
        Route::delete('slots/{id}', [\App\Http\Controllers\Admin\SlotController::class, 'destroy'])->whereNumber('id');

        Route::get('delivery-charges', [\App\Http\Controllers\Admin\DeliveryChargeController::class, 'index']);
        Route::post('delivery-charges', [\App\Http\Controllers\Admin\DeliveryChargeController::class, 'store']);
        Route::put('delivery-charges/{id}', [\App\Http\Controllers\Admin\DeliveryChargeController::class, 'update'])->whereNumber('id');
        Route::delete('delivery-charges/{id}', [\App\Http\Controllers\Admin\DeliveryChargeController::class, 'destroy'])->whereNumber('id');

        Route::get('attributes/{type}', [AttributesController::class, 'index']);
    });
});

