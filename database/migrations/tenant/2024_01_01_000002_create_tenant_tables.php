<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('path');
            $table->string('mime_type');
            $table->string('status')->default('queued');
            $table->integer('tokens')->default(0);
            $table->timestamps();
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
            $table->text('content');
            $table->integer('tokens')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(3072)');

        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->onDelete('cascade');
            $table->string('role');
            $table->text('content');
            $table->integer('tokens')->default(0);
            $table->jsonb('sources')->default('[]');
            $table->timestamps();
        });

        Schema::create('faq_cache', function (Blueprint $table) {
            $table->id();
            $table->string('question_normalized')->unique();
            $table->text('answer');
            $table->integer('hits')->default(0);
            $table->integer('tokens_saved')->default(0);
            $table->timestamps();
        });

        Schema::create('settings_ai', function (Blueprint $table) {
            $table->id();
            $table->string('tone')->default('direto');
            $table->string('language')->default('pt-BR');
            $table->string('detail_level')->default('medio');
            $table->jsonb('security_rules')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_ai');
        Schema::dropIfExists('faq_cache');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
    }
};
