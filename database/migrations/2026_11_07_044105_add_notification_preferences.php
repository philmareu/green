<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('negative_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('negative_notified_at');
        });
    }
};
