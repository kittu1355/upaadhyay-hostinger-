<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('educations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('institution');
            $t->string('degree');
            $t->string('field')->nullable();
            $t->unsignedSmallInteger('start_year')->nullable();
            $t->unsignedSmallInteger('end_year')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('educations'); }
};
