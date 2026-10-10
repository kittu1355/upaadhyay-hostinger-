<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });
        Schema::create('user_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'skill_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('user_skills'); Schema::dropIfExists('skills'); }
};
