<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_requests', function (Blueprint $table) {
            // FR-4.1: Tracking ID unik untuk validasi keuangan Lt.2
            $table->string('tracking_id')->unique()->nullable()->after('id');

            // FR-2.1: Jenis dana (BHP, BLU, dll)
            $table->string('fund_type')->nullable()->after('activity_type');

            // FR-2.4: Tambah status 'draft' dan 'dana_cair' pada pipeline SK
            // Kita ubah dari enum ke string agar lebih fleksibel
            $table->string('status_sk_new')->default('draft')->after('status_sk');

            // FR-4.2, FR-4.3: Pencairan dana
            $table->timestamp('disbursed_at')->nullable()->after('sk_reviewed_at');
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->after('disbursed_at');

            // FR-5.3: Total realisasi belanja
            $table->decimal('total_realization', 15, 2)->nullable()->after('total_budget');

            // FR-3.3: Path QR code / digital signature
            $table->string('digital_signature_path')->nullable()->after('signed_sk_file');
            $table->string('signature_hash')->nullable()->after('digital_signature_path');
        });

        // Migrasi data status lama ke kolom baru
        \DB::table('fund_requests')->update([
            'status_sk_new' => \DB::raw('status_sk'),
        ]);

        // Drop kolom enum lama, rename yang baru
        Schema::table('fund_requests', function (Blueprint $table) {
            $table->dropColumn('status_sk');
        });

        Schema::table('fund_requests', function (Blueprint $table) {
            $table->renameColumn('status_sk_new', 'status_sk');
        });

        // Ubah juga status_lpj dari enum ke string agar fleksibel
        Schema::table('fund_requests', function (Blueprint $table) {
            $table->string('status_lpj_new')->default('none')->after('status_lpj');
        });

        \DB::table('fund_requests')->update([
            'status_lpj_new' => \DB::raw('status_lpj'),
        ]);

        Schema::table('fund_requests', function (Blueprint $table) {
            $table->dropColumn('status_lpj');
        });

        Schema::table('fund_requests', function (Blueprint $table) {
            $table->renameColumn('status_lpj_new', 'status_lpj');
        });
    }

    public function down(): void
    {
        Schema::table('fund_requests', function (Blueprint $table) {
            $table->dropColumn([
                'tracking_id',
                'fund_type',
                'disbursed_at',
                'disbursed_by',
                'total_realization',
                'digital_signature_path',
                'signature_hash',
            ]);
        });
    }
};
