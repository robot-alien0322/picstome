<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery sets (Eugene fork): named groups of photos inside one gallery,
 * e.g. "Getting Ready / Ceremony / Reception". A photo is in at most one set;
 * photos with no set still show under "All".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['gallery_id', 'position']);
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('gallery_set_id')->nullable()->constrained('gallery_sets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gallery_set_id');
        });

        Schema::dropIfExists('gallery_sets');
    }
};
