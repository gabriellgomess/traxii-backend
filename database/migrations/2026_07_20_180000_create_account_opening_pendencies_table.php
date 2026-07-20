<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_opening_pendencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_opening_id')->constrained()->cascadeOnDelete();
            // Itens a reenviar: document_front | document_back | address_proof | selfie
            $table->json('requested_items');
            $table->text('message');
            $table->string('token_hash', 64)->index();
            $table->string('status', 12)->default('open'); // open | resolved
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_opening_pendencies');
    }
};
