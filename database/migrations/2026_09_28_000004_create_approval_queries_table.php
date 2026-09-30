<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_queries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asked_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('directed_to')->constrained('users')->cascadeOnDelete();
            $t->text('question');
            $t->text('answer')->nullable();
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'directed_to', 'answered_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('approval_queries'); }
};
