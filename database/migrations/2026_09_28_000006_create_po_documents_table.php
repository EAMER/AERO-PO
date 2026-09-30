<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('po_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('disk_path');
            $t->string('original_filename');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'purchase_order_id', 'type']);
        });
    }

    public function down(): void { Schema::dropIfExists('po_documents'); }
};
