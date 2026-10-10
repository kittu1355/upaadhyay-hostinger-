<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $t->string('title', 190);
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });
        Schema::create('certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('name', 190);
            $t->string('issuer', 190)->nullable();
            $t->date('issued_date')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('certificates'); Schema::dropIfExists('resumes'); }
};
