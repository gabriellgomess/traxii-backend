<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_openings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Hash SHA-256 do token de retomada entregue apenas ao proponente
            $table->string('resume_token_hash', 64);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // draft → pending → in_analysis → approved | rejected
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->string('submitted_via', 20)->default('whitelabel');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Etapa 1 — dados pessoais
            $table->string('full_name', 120);
            $table->string('email');
            $table->string('password'); // hash bcrypt; vira a senha do correntista na aprovação
            $table->string('cpf', 11);
            $table->string('document_type', 3); // rg | cnh
            $table->string('document_number', 20);
            $table->string('document_issuer', 20);
            $table->char('document_issuer_uf', 2);
            $table->date('birth_date');
            $table->string('phone', 11);

            // Etapa 2 — endereço
            $table->char('zip_code', 8)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('number', 10)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('neighborhood', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();

            // Etapa 4 — prova de vida
            $table->timestamp('liveness_completed_at')->nullable();
            $table->json('liveness_challenges')->nullable();

            // Etapa 6 — aceites (LGPD)
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->timestamp('truthfulness_accepted_at')->nullable();
            $table->string('acceptance_ip', 45)->nullable();

            // Ciclo de vida
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'cpf']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_openings');
    }
};
