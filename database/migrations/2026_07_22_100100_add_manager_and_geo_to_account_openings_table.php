<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_openings', function (Blueprint $table) {
            // Gerente comercial dono da indicação (via link/QR); null = sem indicação
            $table->foreignId('manager_id')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();

            // Geolocalização do endereço do cliente (mapa futuro)
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->index(['manager_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('account_openings', function (Blueprint $table) {
            $table->dropIndex(['manager_id', 'status']);
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
