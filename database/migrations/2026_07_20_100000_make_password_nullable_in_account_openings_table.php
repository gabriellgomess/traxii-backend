<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A senha não é mais coletada no wizard: o correntista recebe uma senha
 * provisória por e-mail somente se/quando o cadastro for aprovado
 * (fluxo a implementar na Fase 4). Até lá, a coluna fica nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_openings', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('account_openings', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
