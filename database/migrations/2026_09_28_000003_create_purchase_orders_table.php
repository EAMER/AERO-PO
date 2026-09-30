<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference');
            $t->string('status')->default('requisition_raised')->index();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_amo_request')->default(false);   // adds the HAMO approval step
            // handoff fields for shipping (Person 3)
            $t->string('payment_terms')->nullable();          // net | cia
            $t->decimal('total_weight_kg', 10, 2)->nullable();
            $t->boolean('contains_dg')->default(false);
            $t->timestamps();

            $t->unique(['tenant_id', 'reference']);
        });

        Schema::create('po_status_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->string('from_status');
            $t->string('to_status');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'purchase_order_id']);
        });

        Schema::create('approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->morphs('approvable');
            $t->string('stage');                       // evaluation | cfo | invoice ...
            $t->unsignedSmallInteger('step_order');
            $t->string('role');
            $t->string('decision')->default('pending');
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('po_status_logs');
        Schema::dropIfExists('purchase_orders');
    }
};
