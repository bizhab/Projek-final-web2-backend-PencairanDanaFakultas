<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-5.1: Tabel rincian nota belanja untuk LPJ.
     * Setiap item mewakili satu nota pembelian.
     */
    public function up(): void
    {
        Schema::create('expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_request_id')->constrained()->onDelete('cascade');

            $table->string('item_name');              // Nama barang/jasa
            $table->string('category')->nullable();    // Kategori (ATK, Konsumsi, Transport, dll)
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_price', 15, 2);     // quantity * unit_price
            $table->string('receipt_file')->nullable(); // Scan nota individual (opsional)
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
