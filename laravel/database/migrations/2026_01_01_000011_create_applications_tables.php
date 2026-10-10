<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
            $t->foreignId('candidate_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $t->text('cover_letter')->nullable();
            $t->enum('status', ['applied', 'under_review', 'shortlisted', 'interview', 'selected', 'rejected', 'withdrawn'])->default('applied')->index();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->unique(['job_id', 'candidate_id']);
        });
        Schema::create('application_status_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('application_status_history'); Schema::dropIfExists('applications'); }
};
