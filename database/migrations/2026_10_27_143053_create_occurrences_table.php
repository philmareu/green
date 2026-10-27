<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('matched_by')->nullable();
            $table->string('resolution')->nullable();
            $table->timestamps();

            $table->unique(['recurring_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrences');
    }
};
