<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->string('awb_number')->nullable()->after('contains_dg');
            $t->boolean('customs_duty_paid')->default(false)->after('awb_number');
            $t->string('grn_number')->nullable()->after('customs_duty_paid');
            $t->timestamp('serviceable_label_applied_at')->nullable()->after('grn_number');
            $t->timestamp('cardex_sent_at')->nullable()->after('serviceable_label_applied_at');
            // The handover back to DigiMaint (manual 7.1.18) - this is NOT a
            // DigiMaint API call yet, just a record that the handover happened.
            $t->timestamp('digimaint_synced_at')->nullable()->after('cardex_sent_at');

            $t->unique(['tenant_id', 'grn_number']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'grn_number']);
            $t->dropColumn([
                'awb_number', 'customs_duty_paid', 'grn_number',
                'serviceable_label_applied_at', 'cardex_sent_at', 'digimaint_synced_at',
            ]);
        });
    }
};
