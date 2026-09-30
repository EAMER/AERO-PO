<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('po_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->string('part_number');
            $t->string('description');
            $t->unsignedInteger('quantity')->default(1);
            $t->string('condition')->nullable();       // new, serviceable, overhauled...
            $t->boolean('stock_available')->nullable(); // null = not yet checked
            $t->timestamp('stock_checked_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'purchase_order_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('po_lines'); }
};
