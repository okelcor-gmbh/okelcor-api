<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('order_ref', 30);
            $table->string('type', 30);                       // proforma | commercial_invoice | packing_list | upload
            $table->string('number', 50)->nullable()->unique(); // PI-2026-0001, CI-2026-0001, etc.
            $table->string('pdf_path', 500)->nullable();      // generated PDFs — private disk
            $table->string('file_path', 500)->nullable();     // admin-uploaded files — private disk
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 30)->default('draft');   // draft | issued | superseded
            $table->string('notes', 500)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type']);
            $table->index(['type', 'status']);
            $table->index('order_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_documents');
    }
};
