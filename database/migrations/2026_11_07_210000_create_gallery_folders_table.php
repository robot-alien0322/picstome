<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery folders (Eugene fork): organize the admin gallery list into
 * folders like Concerts / Events / Weddings. Admin-only; clients never see them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'position']);
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignId('gallery_folder_id')->nullable()->constrained('gallery_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gallery_folder_id');
        });

        Schema::dropIfExists('gallery_folders');
    }
};
