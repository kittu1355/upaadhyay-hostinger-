<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('profile_photo', 500)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('gender', 20)->nullable();
            $t->string('location')->nullable();
            $t->string('city', 100)->nullable()->index();
            $t->string('state', 100)->nullable();
            $t->text('bio')->nullable();
            $t->string('current_job_title')->nullable();
            $t->decimal('total_experience', 4, 1)->nullable();
            $t->json('profile_data')->nullable();   // the full faculty profile form (research, patents, employment history ...)
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('candidate_profiles'); }
};
