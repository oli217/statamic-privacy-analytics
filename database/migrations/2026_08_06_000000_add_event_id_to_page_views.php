<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statamic_analytics_page_views', function (Blueprint $table) {
            $table->string('event_id', 36)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('statamic_analytics_page_views', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};
