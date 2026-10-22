<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('plaid_transaction_id')->unique();
            $table->string('pending_plaid_transaction_id')->nullable()->index();
            // Stored exactly as Plaid sends it: positive = money out, negative = money in.
            $table->decimal('amount', 16, 4);
            $table->string('currency', 3)->default('USD');
            $table->date('date');
            $table->date('authorized_date')->nullable();
            $table->string('name');
            $table->string('merchant_name')->nullable();
            $table->boolean('pending')->default(false)->index();
            $table->string('category_primary')->nullable();
            $table->string('category_detailed')->nullable();
            $table->string('payment_channel')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
