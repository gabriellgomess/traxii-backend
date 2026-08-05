<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarquia de 2 níveis entre gerentes comerciais: um subgerente aponta
 * para o gerente "acima" dele via parent_manager_id. Subgerente continua
 * sendo role=company_manager (mesmo cadastro, mesmas permissões) — o que
 * muda é só o vínculo e a visibilidade de quem enxerga as contas de quem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('parent_manager_id')
                ->nullable()
                ->after('company_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['parent_manager_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_manager_id');
        });
    }
};
