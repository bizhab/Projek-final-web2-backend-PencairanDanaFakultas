<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // admin yang berkomentar

            // type: 'sk' = komentar untuk revisi SK, 'lpj' = komentar untuk revisi LPJ
            $table->enum('type', ['sk', 'lpj']);
            $table->text('body'); // isi komentar/catatan revisi yang rinci

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
