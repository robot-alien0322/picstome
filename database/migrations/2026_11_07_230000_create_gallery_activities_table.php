<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery activity (Eugene fork): visits, unique visitors, download emails, downloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // visit | email | download
            $table->string('visitor_id', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('detail')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gallery_id', 'type', 'created_at']);
            $table->index(['gallery_id', 'visitor_id']);
            $table->index(['gallery_id', 'email']);
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('require_download_email')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('require_download_email');
        });

        Schema::dropIfExists('gallery_activities');
    }
};
