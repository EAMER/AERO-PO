<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('requester')->after('password');
            $t->dropUnique(['email']);
            $t->unique(['tenant_id', 'email']);   // same email may exist in two tenants
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'email']);
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropColumn('role');
            $t->unique('email');
        });
    }
};
