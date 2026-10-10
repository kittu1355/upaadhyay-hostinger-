<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // NOTE: this is the job-POSTINGS table. The queue must therefore stay on QUEUE_CONNECTION=sync
        // (the default 'database' queue also wants a table called 'jobs').
        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 190)->index();
            $t->text('description');
            $t->text('requirements')->nullable();
            $t->string('location', 190);
            $t->string('city', 100)->nullable()->index();
            $t->string('state', 100)->nullable();
            $t->enum('employment_type', ['full-time', 'part-time', 'contract', 'internship', 'remote'])->default('full-time')->index();
            $t->decimal('experience_min', 4, 1)->nullable();
            $t->decimal('experience_max', 4, 1)->nullable();
            $t->unsignedBigInteger('salary_min')->nullable();
            $t->unsignedBigInteger('salary_max')->nullable();
            $t->unsignedInteger('vacancy_count')->default(1);
            $t->date('application_deadline')->nullable();
            $t->enum('status', ['draft', 'published', 'closed', 'expired'])->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->json('meta')->nullable();   // role, dept, qualification, notice period, salary period/negotiable, campus ...
            $t->timestamps();
            $t->index(['status', 'published_at']);
        });
        Schema::create('job_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
            $t->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['job_id', 'skill_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('job_skills'); Schema::dropIfExists('jobs'); }
};
