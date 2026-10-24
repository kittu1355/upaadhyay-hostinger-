<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $t) {
            $t->id();
            $t->string('visitor_id', 64)->index();   // random id kept in the visitor's browser
            $t->string('session_id', 64)->index();   // one per browser tab session
            $t->string('path', 255);
            $t->string('referrer', 120)->nullable(); // "Google", "WhatsApp", "example.com"; null = direct/internal
            $t->string('device', 10);                // mobile | tablet | desktop
            $t->string('browser', 20)->nullable();
            $t->string('os', 20)->nullable();
            $t->char('country', 2)->nullable();      // only when a CDN header provides it
            $t->boolean('is_new')->default(false);           // visitor's first ever page view
            $t->boolean('first_in_session')->default(false); // first page of a session (traffic sources)
            $t->timestamp('created_at')->nullable()->index();
            $t->timestamp('last_seen_at')->nullable()->index(); // bumped by the 30 s heartbeat -> "online now"
            $t->index(['created_at', 'visitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
