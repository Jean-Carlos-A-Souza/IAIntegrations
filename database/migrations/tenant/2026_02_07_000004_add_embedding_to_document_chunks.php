<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::table('document_chunks', function (Blueprint $table) {
            if (!Schema::hasColumn('document_chunks', 'embedding')) {
                DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(3072)');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('document_chunks', function (Blueprint $table) {
            if (Schema::hasColumn('document_chunks', 'embedding')) {
                DB::statement('ALTER TABLE document_chunks DROP COLUMN embedding');
            }
        });
    }
};
