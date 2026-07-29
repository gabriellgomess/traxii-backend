<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Documento: CPF para analistas/admins; CPF ou CNPJ para gerentes
            $table->string('document', 14)->nullable()->after('email');
            $table->string('document_type', 4)->nullable()->after('document'); // cpf | cnpj
            $table->string('phone', 11)->nullable()->after('document_type');

            // Endereço (gerentes comerciais) + geolocalização para o mapa futuro
            $table->char('zip_code', 8)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('number', 10)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('neighborhood', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Código de indicação do gerente (link/QR de onboarding)
            $table->string('referral_code', 12)->nullable()->unique();

            // Senha provisória: obriga a troca no primeiro acesso
            $table->boolean('must_change_password')->default(false);
            $table->boolean('is_active')->default(true);

            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'role']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn([
                'document', 'document_type', 'phone', 'zip_code', 'street', 'number',
                'complement', 'neighborhood', 'city', 'state', 'latitude', 'longitude',
                'referral_code', 'must_change_password', 'is_active',
            ]);
        });
    }
};
