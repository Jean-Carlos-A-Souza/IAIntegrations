<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->after('id');
            $table->unsignedBigInteger('tenant_id')->nullable()->after('owner_user_id');
            $table->string('original_name')->after('title');
            $table->unsignedBigInteger('size_bytes')->default(0)->after('mime_type');
            $table->string('checksum', 64)->nullable()->after('size_bytes');
            $table->text('content_text')->nullable()->after('checksum');
            $table->json('tags')->nullable()->after('content_text');
            $table->text('error_message')->nullable()->after('status');

            $table->index(['owner_user_id', 'tenant_id']);
        });

        Schema::table('document_chunks', function (Blueprint $table) {
            $table->unsignedInteger('chunk_index')->default(0)->after('document_id');
            $table->unsignedInteger('tokens_estimated')->default(0)->after('content');
            $table->string('content_hash', 64)->nullable()->after('tokens_estimated');

            $table->index(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex(['document_id', 'chunk_index']);
            $table->dropColumn(['chunk_index', 'tokens_estimated', 'content_hash']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id', 'tenant_id']);
            $table->dropColumn([
                'owner_user_id',
                'tenant_id',
                'original_name',
                'size_bytes',
                'checksum',
                'content_text',
                'tags',
                'error_message',
            ]);
        });
    }
};
