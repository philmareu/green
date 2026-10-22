<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plaid_item_id')->unique();
            $table->text('access_token');
            $table->string('institution_id')->nullable();
            $table->string('institution_name')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->string('transactions_update_status')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status')->default('active');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_items');
    }
};
