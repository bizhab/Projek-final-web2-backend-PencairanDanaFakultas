<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Informasi kegiatan
            $table->string('activity_name');
            $table->date('activity_date');
            $table->string('activity_type')->nullable(); // jenis kegiatan (seminar, workshop, dll)
            $table->text('description')->nullable();
            $table->decimal('total_budget', 15, 2)->nullable(); // RAB total

            // Dokumen SK
            $table->string('sk_file'); // path file SK (.pdf)
            $table->string('rab_file'); // path file RAB (.pdf)

            // Status & alur SK
            // pending = menunggu review, revised = dikembalikan untuk perbaikan,
            // approved = SK sudah di-ACC dan dana cair, rejected = ditolak permanen
            $table->enum('status_sk', ['pending', 'revised', 'approved', 'rejected'])->default('pending');
            $table->string('signed_sk_file')->nullable(); // SK bertanda tangan elektronik dari fakultas
            $table->timestamp('sk_submitted_at')->nullable();
            $table->timestamp('sk_reviewed_at')->nullable();

            // Proses LPJ - hanya bisa diajukan setelah SK approved
            $table->string('lpj_file')->nullable(); // 1 file PDF berisi semua LPJ + nota
            $table->enum('status_lpj', ['none', 'pending', 'revised', 'approved'])->default('none');
            $table->timestamp('lpj_submitted_at')->nullable();
            $table->timestamp('lpj_reviewed_at')->nullable();

            // Timestamps standar
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_requests');
    }
};
