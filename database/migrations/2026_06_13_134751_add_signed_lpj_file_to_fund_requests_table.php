<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_requests', function (Blueprint $table) {
            $table->string('signed_lpj_file')->nullable()->after('lpj_file');
        });
    }

    public function down(): void
    {
        Schema::table('fund_requests', function (Blueprint $table) {
            $table->dropColumn('signed_lpj_file');
        });
    }
};
