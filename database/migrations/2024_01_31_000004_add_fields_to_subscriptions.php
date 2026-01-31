<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migration para public schema - adicionar campos em Subscription
        Schema::table('subscriptions', function (Blueprint $table) {
            // Verificar se colunas já existem antes de adicionar
            if (!Schema::hasColumn('subscriptions', 'next_billing_date')) {
                $table->date('next_billing_date')->nullable()->after('cancel_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'next_billing_date')) {
                $table->dropColumn('next_billing_date');
            }
        });
    }
};
