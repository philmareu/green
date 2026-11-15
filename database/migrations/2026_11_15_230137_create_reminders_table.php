<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before');
            $table->boolean('repeats')->default(false);
            $table->string('note')->nullable();
            $table->date('reminded_through')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
