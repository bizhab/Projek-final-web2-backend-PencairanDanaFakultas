<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NFR-4.2: Tabel audit trail untuk mencatat setiap aksi krusial.
     * Log otomatis setiap aksi ACC, Revisi, Konfirmasi Dana, dll.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('action');                 // approve_sk, revise_sk, reject_sk, disburse, approve_lpj, etc.
            $table->string('auditable_type');          // Polymorphic: App\Models\FundRequest, etc.
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();    // Nilai sebelumnya
            $table->json('new_values')->nullable();    // Nilai setelahnya
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
