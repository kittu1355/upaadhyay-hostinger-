<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stores the admin's reason when an institution is rejected or blocked, so the dashboard can show it (not only the notification). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'verification_note')) {
                $t->string('verification_note', 500)->nullable()->after('verification_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (Schema::hasColumn('companies', 'verification_note')) { $t->dropColumn('verification_note'); }
        });
    }
};
