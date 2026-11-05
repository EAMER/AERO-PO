<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('email');
            $t->string('kind')->default('supplier');
            $t->string('payment_terms')->default('net');
            $t->text('address')->nullable();
            $t->string('account_reference')->nullable();
            $t->boolean('is_approved')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'email', 'kind']);
        });
        Schema::create('requisitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_amo_request')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });
        Schema::create('requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $t->string('part_number');
            $t->string('description');
            $t->unsignedInteger('quantity');
            $t->string('condition')->nullable();
            $t->timestamps();
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->foreignId('requisition_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('currency', 3)->nullable();
            $t->unsignedBigInteger('total_minor')->default(0);
            $t->json('commercial_snapshot')->nullable();
            $t->timestamp('po_approved_at')->nullable();
            $t->string('pdf_path')->nullable();
            $t->timestamp('dispatch_queued_at')->nullable();
            $t->timestamp('dispatch_started_at')->nullable();
            $t->timestamp('issued_at')->nullable();
        });
        Schema::table('po_lines', function (Blueprint $t) {
            $t->foreignId('requisition_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('unit_price_minor')->default(0);
            $t->string('warranty')->nullable();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('route');
            $t->decimal('weight_kg', 10, 2);
            $t->boolean('contains_dg')->default(false);
            $t->foreignId('carrier_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $t->string('status')->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('freight_minor')->nullable();
            $t->unsignedBigInteger('selected_quote_id')->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('awb_number')->nullable();
            $t->boolean('customs_duty_paid')->default(false);
            $t->timestamps();
        });
        Schema::create('rfqs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shipment_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $t->string('kind')->default('parts');
            $t->unsignedInteger('revision')->default(1);
            $t->string('reference');
            $t->string('status')->default('queued');
            $t->timestamp('sent_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
            $t->unique(['purchase_order_id', 'vendor_id', 'kind', 'revision']);
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $t->foreignId('po_document_id')->constrained()->restrictOnDelete();
            $t->string('reference');
            $t->string('currency', 3);
            $t->string('payment_terms');
            $t->unsignedBigInteger('total_minor');
            $t->date('valid_until')->nullable();
            $t->timestamps();
        });
        Schema::create('quote_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignId('po_line_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('unit_price_minor');
            $t->string('condition');
            $t->string('warranty');
            $t->timestamps();
            $t->unique(['quote_id', 'po_line_id']);
        });
        Schema::create('quote_evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('selected_quote_id')->constrained('quotes')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->text('justification');
            $t->json('comparison_snapshot');
            $t->string('status')->default('draft');
            $t->timestamps();
            $t->unique(['purchase_order_id', 'revision']);
        });
        Schema::create('freight_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $t->foreignId('po_document_id')->constrained()->restrictOnDelete();
            $t->string('reference');
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamps();
        });
        Schema::create('shipment_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('po_document_id')->unique()->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('po_document_id')->constrained()->restrictOnDelete();
            $t->string('reference');
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('payment_terms');
            $t->string('status')->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_reference')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });
        Schema::table('approvals', function (Blueprint $t) {
            $t->unsignedInteger('round')->default(1);
            $t->boolean('is_active')->default(true);
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->json('snapshot')->nullable();
            $t->index(['tenant_id', 'is_active', 'decision']);
        });
        Schema::create('payment_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('approval_id')->unique()->constrained()->restrictOnDelete();
            $t->string('role');
            $t->unsignedSmallInteger('step_order');
            $t->unsignedInteger('round');
            $t->string('decision')->default('pending');
            $t->timestamps();
        });
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->string('grn_number');
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('received_at');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'grn_number']);
        });
        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('po_line_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->timestamps();
            $t->unique(['goods_receipt_id', 'po_line_id']);
        });
        Schema::create('digimaint_handovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goods_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('po_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('activity');
            $t->foreignId('supersedes_id')->nullable()->constrained('digimaint_handovers')->restrictOnDelete();
            $t->string('transaction_reference');
            $t->string('dedupe_key', 64)->nullable();
            $t->foreignId('po_document_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('performed_at');
            $t->string('status')->default('recorded');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'dedupe_key'], 'digimaint_transaction_line_unique');
            $t->index(['goods_receipt_id', 'po_line_id']);
        });
        foreach (['amount_changes', 'activity_logs'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('subject_type');
                $t->unsignedBigInteger('subject_id');
                $t->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
                $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('action');
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->text('reason')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'subject_type', 'subject_id']);
            });
        }
        Schema::create('kpi_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('metric');
            $t->decimal('target', 12, 2);
            $t->string('direction');
            $t->foreignId('defined_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'metric']);
        });
    }

    public function down(): void
    {
        foreach (['kpi_definitions', 'activity_logs', 'amount_changes', 'digimaint_handovers', 'goods_receipt_lines', 'goods_receipts', 'payment_steps'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('approvals', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'is_active', 'decision']);
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropColumn(['round', 'is_active', 'due_at', 'opened_at', 'snapshot']);
        });
        foreach (['invoices', 'shipment_documents', 'freight_quotes', 'quote_evaluations', 'quote_lines', 'quotes', 'rfqs', 'shipments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('po_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requisition_line_id');
            $t->dropColumn(['unit_price_minor', 'warranty']);
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requisition_id');
            $t->dropConstrainedForeignId('vendor_id');
            $t->dropColumn(['revision', 'currency', 'total_minor', 'commercial_snapshot', 'po_approved_at', 'pdf_path', 'dispatch_queued_at', 'dispatch_started_at', 'issued_at']);
        });
        foreach (['requisition_lines', 'requisitions', 'vendors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
