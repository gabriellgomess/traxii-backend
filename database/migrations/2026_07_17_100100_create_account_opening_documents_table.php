<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_opening_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_opening_id')->constrained()->cascadeOnDelete();
            // document_front | document_back | address_proof | selfie
            $table->string('type', 20);
            $table->string('path'); // disco privado (storage/app/private)
            $table->string('original_name', 150);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size'); // bytes
            $table->timestamps();

            // Reenvio substitui o arquivo anterior do mesmo tipo
            $table->unique(['account_opening_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_opening_documents');
    }
};
