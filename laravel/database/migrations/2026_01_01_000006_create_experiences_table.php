<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('experiences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('company_name');
            $t->string('job_title');
            $t->text('description')->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->boolean('is_current')->default(false);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('experiences'); }
};
