<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->decimal('amount', 16, 4);
            $table->string('frequency');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('payee')->nullable();
            $table->unsignedTinyInteger('amount_tolerance')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->json('dismissed_transactions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrings');
    }
};
