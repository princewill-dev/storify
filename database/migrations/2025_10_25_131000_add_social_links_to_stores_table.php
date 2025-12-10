<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('instagram_url')->nullable()->after('address');
            $table->string('facebook_url')->nullable()->after('instagram_url');
            $table->string('twitter_url')->nullable()->after('facebook_url');
            $table->string('tiktok_url')->nullable()->after('twitter_url');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['instagram_url','facebook_url','twitter_url','tiktok_url']);
        });
    }
};
