<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::with('role')->get();
echo "Total in DB: " . $users->count() . "\n";
foreach($users as $u) {
    echo "ID: {$u->id}, Email: {$u->email}, Role: " . ($u->role ? $u->role->name : 'NULL') . "\n";
}
