<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('email', 255);
            $table->string('subject', 200);
            $table->text('inquiry');
            $table->enum('status', ['new', 'read', 'replied'])->default('new');
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('status', 'contact_messages_status_idx');
            $table->index('email', 'contact_messages_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
