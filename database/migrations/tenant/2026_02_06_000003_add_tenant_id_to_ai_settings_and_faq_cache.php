<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings_ai', function (Blueprint $table) {
            if (!Schema::hasColumn('settings_ai', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            }
        });

        Schema::table('faq_cache', function (Blueprint $table) {
            if (!Schema::hasColumn('faq_cache', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            }
            if (Schema::hasColumn('faq_cache', 'question_normalized')) {
                $table->dropUnique('faq_cache_question_normalized_unique');
                $table->unique(['tenant_id', 'question_normalized'], 'faq_cache_tenant_question_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('faq_cache', function (Blueprint $table) {
            if (Schema::hasColumn('faq_cache', 'tenant_id')) {
                $table->dropUnique('faq_cache_tenant_question_unique');
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('settings_ai', function (Blueprint $table) {
            if (Schema::hasColumn('settings_ai', 'tenant_id')) {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
