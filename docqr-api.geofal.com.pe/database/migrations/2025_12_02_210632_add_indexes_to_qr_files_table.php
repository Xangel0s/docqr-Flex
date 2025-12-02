<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Using raw SQL to check for indexes is safer if Doctrine is not installed/configured
            // But Laravel's Schema builder handles "index exists" checks poorly without Doctrine
            // So we'll just try to add them and catch exceptions if they exist, or use a simpler approach
            
            try {
                $table->index('folder_name');
            } catch (\Exception $e) {}
            
            try {
                $table->index('status');
            } catch (\Exception $e) {}
            
            try {
                $table->index('created_at');
            } catch (\Exception $e) {}
            
            try {
                $table->index('original_filename');
            } catch (\Exception $e) {}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->dropIndex(['folder_name']);
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['original_filename']);
        });
    }
};
