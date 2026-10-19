<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->after('email')->constrained()->cascadeOnDelete();
            $t->dropPrimary(['email']);
            $t->primary(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $t) {
            $t->dropPrimary(['tenant_id', 'email']);
            $t->dropConstrainedForeignId('tenant_id');
            $t->primary(['email']);
        });
    }
};
