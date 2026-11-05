<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->json('answers')->nullable()->after('cover_letter');     // faculty's answers to the employer's questions
            $t->json('interview')->nullable()->after('answers');         // interview details set by the institute
        });
    }
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $t) { $t->dropColumn(['answers', 'interview']); });
    }
};
