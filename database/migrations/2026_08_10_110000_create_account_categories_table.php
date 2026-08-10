<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tela experimental (categoria de contas) — se a tela for descartada,
// remova esta migration junto com o model/controller/rota correspondentes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('min_movement', 12, 2);
            $table->decimal('max_movement', 12, 2);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_categories');
    }
};
