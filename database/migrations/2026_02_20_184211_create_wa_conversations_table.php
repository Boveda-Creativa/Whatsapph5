<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       // database/migrations/xxxx_create_wa_conversations_table.php
        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('state')->default('new');
            $table->string('mode')->default('bot'); // bot|human
            $table->json('data')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('window_open_until')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('wa_leads')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wa_conversations');
    }
};
