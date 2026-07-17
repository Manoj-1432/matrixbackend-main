<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('brands')) {
            $brands = [
                'Michelin', 'Continental', 'Pirelli', 'Bridgestone', 'Goodyear',
                'Dunlop', 'Hankook', 'Yokohama', 'Falken', 'Kumho',
                'Toyo', 'Nexen', 'Cooper', 'Firestone', 'BFGoodrich',
            ];

            foreach ($brands as $name) {
                DB::table('brands')->upsert(
                    [['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]],
                    ['name'],
                    ['is_active', 'updated_at']
                );
            }
        }

        if (Schema::hasTable('sizes')) {
            // Common UK tyre sizes: width/profile/rim
            $sizes = [
                [165, 70, 14], [175, 65, 14], [185, 60, 14], [185, 65, 14],
                [175, 65, 15], [185, 55, 15], [185, 60, 15], [185, 65, 15],
                [195, 50, 15], [195, 55, 15], [195, 60, 15], [195, 65, 15],
                [205, 50, 15], [205, 55, 15], [205, 60, 15], [205, 65, 15],
                [195, 45, 16], [195, 50, 16], [195, 55, 16],
                [205, 45, 16], [205, 50, 16], [205, 55, 16], [205, 60, 16],
                [215, 45, 16], [215, 55, 16], [215, 60, 16], [215, 65, 16],
                [225, 45, 16], [225, 55, 16], [225, 60, 16],
                [205, 40, 17], [205, 45, 17], [205, 50, 17],
                [215, 40, 17], [215, 45, 17], [215, 50, 17], [215, 55, 17],
                [225, 40, 17], [225, 45, 17], [225, 50, 17], [225, 55, 17],
                [235, 45, 17], [235, 50, 17], [235, 55, 17], [235, 60, 17],
                [245, 40, 17], [245, 45, 17], [245, 65, 17],
                [255, 40, 17], [255, 45, 17], [255, 65, 17],
                [205, 40, 18], [215, 35, 18], [215, 40, 18], [215, 45, 18],
                [225, 35, 18], [225, 40, 18], [225, 45, 18], [225, 50, 18],
                [235, 40, 18], [235, 45, 18], [235, 50, 18],
                [245, 35, 18], [245, 40, 18], [245, 45, 18],
                [255, 35, 18], [255, 40, 18], [255, 45, 18],
                [265, 35, 18], [265, 40, 18],
                [225, 35, 19], [225, 40, 19], [235, 35, 19], [245, 35, 19],
                [255, 30, 19], [255, 35, 19], [265, 30, 19],
                [245, 30, 20], [255, 30, 20], [275, 30, 20], [285, 30, 20],
            ];

            foreach ($sizes as [$width, $profile, $rim]) {
                $label = "{$width}/{$profile} R{$rim}";
                DB::table('sizes')->upsert(
                    [['width' => $width, 'profile' => $profile, 'rim' => $rim, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                    ['width', 'profile', 'rim'],
                    ['label', 'updated_at']
                );
            }
        }
    }

    public function down(): void {}
};
