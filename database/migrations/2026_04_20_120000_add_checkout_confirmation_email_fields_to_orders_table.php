<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'is_new_user')) {
                $table->boolean('is_new_user')->default(false)->after('customer_comment');
            }

            if (! Schema::hasColumn('orders', 'new_user_password_encrypted')) {
                $table->text('new_user_password_encrypted')->nullable()->after('is_new_user');
            }

            if (! Schema::hasColumn('orders', 'confirmation_email_sent_at')) {
                $table->timestamp('confirmation_email_sent_at')->nullable()->after('new_user_password_encrypted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'is_new_user',
                'new_user_password_encrypted',
                'confirmation_email_sent_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
