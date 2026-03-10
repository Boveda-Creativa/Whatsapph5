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
        Schema::create('wa_leads', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->string('name')->nullable();
            $table->string('event_type')->nullable();
            $table->date('event_date')->nullable();
            $table->integer('people_count')->nullable();
            $table->string('budget_range')->nullable(); // 30-50, 50-100, etc
            $table->string('package_type')->nullable(); // local|paquete_con_banquete|paquete_sin
            $table->date('alt_date')->nullable();
            $table->string('customer_type')->nullable(); // empresa|persona_fisica
            $table->string('source')->nullable(); // como se enteró
            $table->string('status')->default('capturing'); // capturing|qualified|handoff|closed
            $table->integer('score')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wa_leads');
    }
};
