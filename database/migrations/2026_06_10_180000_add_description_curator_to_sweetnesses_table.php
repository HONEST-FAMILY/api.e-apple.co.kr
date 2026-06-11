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
        Schema::table('sweetnesses', function (Blueprint $table) {
            $table->text('description')->nullable()->after('standard_sweetness')->comment('측정 설명');
            $table->string('curator')->nullable()->after('description')->comment('측정 담당자');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sweetnesses', function (Blueprint $table) {
            $table->dropColumn(['description', 'curator']);
        });
    }
};
