<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Which disk holds the bytes. NULL = legacy row stored before B2 (resolved to 'local'/'uploads' by FileUploadService).
        Schema::table('files', function (Blueprint $t) {
            $t->string('disk', 30)->nullable()->after('storage_path');
        });
    }
    public function down(): void
    {
        Schema::table('files', fn (Blueprint $t) => $t->dropColumn('disk'));
    }
};
