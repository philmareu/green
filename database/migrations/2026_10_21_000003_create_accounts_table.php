<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plaid_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plaid_account_id')->unique();
            $table->string('name');
            $table->string('official_name')->nullable();
            $table->string('mask', 8)->nullable();
            $table->string('type')->nullable();
            $table->string('subtype')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('current_balance', 16, 4)->nullable();
            $table->decimal('available_balance', 16, 4)->nullable();
            $table->decimal('credit_limit', 16, 4)->nullable();
            $table->timestamp('balances_updated_at')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
