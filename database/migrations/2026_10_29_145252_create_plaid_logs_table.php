<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Entries outlive a removed connection; the bank's name stays on them.
            $table->foreignId('plaid_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('institution_name')->nullable();
            $table->string('kind');
            $table->string('triggered_by');
            $table->string('result');
            $table->timestamp('started_at')->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            $table->unsignedInteger('added')->nullable();
            $table->unsignedInteger('modified')->nullable();
            $table->unsignedInteger('removed')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('requests')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_logs');
    }
};
