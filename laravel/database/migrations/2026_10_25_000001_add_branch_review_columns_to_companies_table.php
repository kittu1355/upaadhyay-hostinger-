<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sub-branch code (Company model, BranchController, AdminBranchController) reads and writes
 * branch_admin_status, branch_note and branch_submitted_at, but the original companies migration
 * only created parent_id / institution_code / branch_status. Without these columns every
 * /api/admin/branch-requests call fails with "Unknown column" -> HTTP 500 "Server error".
 * Guarded with hasColumn so it is safe to run on any database state.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'branch_admin_status')) {
                $t->string('branch_admin_status', 20)->nullable()->after('branch_status');   // admin decision: pending | approved | rejected
            }
            if (! Schema::hasColumn('companies', 'branch_note')) {
                $t->string('branch_note', 500)->nullable()->after('branch_admin_status');    // rejection reason shown to the sub-branch
            }
            if (! Schema::hasColumn('companies', 'branch_submitted_at')) {
                $t->timestamp('branch_submitted_at')->nullable()->after('branch_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            foreach (['branch_submitted_at', 'branch_note', 'branch_admin_status'] as $c) {
                if (Schema::hasColumn('companies', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
