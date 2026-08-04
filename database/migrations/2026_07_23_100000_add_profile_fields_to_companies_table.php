<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Dados cadastrais da instituição
            $table->string('legal_name', 150)->nullable()->after('name'); // razão social
            $table->string('document', 14)->nullable()->after('legal_name'); // CNPJ
            $table->string('email')->nullable()->after('domain');
            $table->string('phone', 11)->nullable()->after('email');

            // Endereço da sede + geolocalização (mapa)
            $table->char('zip_code', 8)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('number', 10)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('neighborhood', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'document', 'email', 'phone', 'zip_code', 'street',
                'number', 'complement', 'neighborhood', 'city', 'state',
                'latitude', 'longitude',
            ]);
        });
    }
};
