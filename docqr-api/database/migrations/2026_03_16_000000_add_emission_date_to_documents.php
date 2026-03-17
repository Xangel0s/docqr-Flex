<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecutar las migraciones.
     */
    public function up(): void
    {
        if (Schema::hasTable('qr_files') && !Schema::hasColumn('qr_files', 'emission_date')) {
            Schema::table('qr_files', function (Blueprint $table) {
                $table->date('emission_date')->nullable()->after('folder_name');
                $table->index('emission_date');
            });
        }

        if (Schema::hasTable('document') && !Schema::hasColumn('document', 'emission_date')) {
            Schema::table('document', function (Blueprint $table) {
                $table->date('emission_date')->nullable()->after('folder_name');
            });
        }
    }

    /**
     * Revertir las migraciones.
     */
    public function down(): void
    {
        if (Schema::hasTable('qr_files') && Schema::hasColumn('qr_files', 'emission_date')) {
            Schema::table('qr_files', function (Blueprint $table) {
                $table->dropIndex(['emission_date']);
                $table->dropColumn('emission_date');
            });
        }

        if (Schema::hasTable('document') && Schema::hasColumn('document', 'emission_date')) {
            Schema::table('document', function (Blueprint $table) {
                $table->dropColumn('emission_date');
            });
        }
    }
};
