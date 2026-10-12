<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesAccounts;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase, MakesAccounts;

    public function test_only_admin_can_see_dashboard(): void
    {
        Sanctum::actingAs($this->candidate());
        $this->getJson('/api/admin/dashboard')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonStructure(['data' => [
            'total_candidates', 'total_companies', 'total_jobs', 'active_jobs', 'total_applications',
            'pending_companies', 'selected_candidates', 'rejected_applications',
        ]]);
    }

    public function test_admin_approves_company_which_can_then_publish(): void
    {
        $co = $this->company('pending');
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/admin/companies/'.$co->company->id.'/approve')->assertOk();
        $this->assertSame('approved', Company::first()->verification_status);

        Sanctum::actingAs($co->fresh());
        $this->postJson('/api/company/jobs', $this->jobPayload())->assertCreated();
        $this->assertTrue($co->notifications()->where('type', 'company_approved')->exists());
    }

    public function test_admin_sees_full_company_profile_with_documents(): void
    {
        $co = $this->company('pending');
        $co->company->update(['profile_data' => ['inst' => ['d' => ['name' => 'Co X', 'city' => 'Hyderabad']]]]);
        $doc = \App\Models\File::create([
            'user_id' => $co->id, 'file_name' => 'a.pdf', 'original_name' => 'registration.pdf', 'file_type' => 'application/pdf',
            'file_size' => 1200, 'storage_path' => 'x/a.pdf', 'disk' => 'local', 'category' => 'other',
        ]);
        // a resume-type file must never be listed as an institution document
        \App\Models\File::create([
            'user_id' => $co->id, 'file_name' => 'r.pdf', 'original_name' => 'resume.pdf', 'file_type' => 'application/pdf',
            'file_size' => 900, 'storage_path' => 'x/r.pdf', 'disk' => 'local', 'category' => 'resume',
        ]);

        Sanctum::actingAs($this->candidate());
        $this->getJson('/api/admin/companies/'.$co->company->id)->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/companies/'.$co->company->id)->assertOk()
            ->assertJsonPath('data.company.profile_data.inst.d.city', 'Hyderabad')
            ->assertJsonPath('data.company.user.email', $co->email)
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.id', $doc->id)
            ->assertJsonMissingPath('data.files.0.storage_path')
            ->assertJsonStructure(['data' => ['company', 'files', 'branches', 'stats' => ['jobs', 'live_jobs', 'applications']]]);
        $this->getJson('/api/admin/companies/999999')->assertNotFound();
        // admins may open any institution document through the signed-link endpoint
        $this->getJson('/api/files/'.$doc->id.'/link')->assertOk();
    }

    public function test_reject_sends_reason_and_approve_unlocks(): void
    {
        $co = $this->company('pending');
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/admin/companies/'.$co->company->id.'/reject', ['reason' => 'Registration proof is unreadable'])->assertOk();
        $this->assertSame('rejected', Company::first()->verification_status);
        $n = $co->notifications()->where('type', 'company_rejected')->first();
        $this->assertStringContainsString('Registration proof is unreadable', $n->message);

        $this->putJson('/api/admin/companies/'.$co->company->id.'/reject', ['reason' => str_repeat('x', 501)])->assertStatus(422);

        $this->putJson('/api/admin/companies/'.$co->company->id.'/approve')->assertOk();
        $this->assertSame('approved', Company::first()->verification_status);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.companies_by_status.approved', 1)
            ->assertJsonPath('data.companies_by_status.pending', 0);
    }

    public function test_blocking_user_and_company_unpublishes_jobs(): void
    {
        $co = $this->company();
        Sanctum::actingAs($co);
        $this->postJson('/api/company/jobs', $this->jobPayload())->assertCreated();

        Sanctum::actingAs($this->admin());
        $this->putJson('/api/admin/companies/'.$co->company->id.'/block')->assertOk();
        $this->assertDatabaseHas('jobs', ['status' => 'closed']);

        $cand = $this->candidate();
        $this->putJson("/api/admin/users/{$cand->id}/block")->assertOk();
        $this->assertSame('blocked', User::find($cand->id)->status);
    }

    public function test_admin_manages_skills_categories_and_announcements(): void
    {
        $cand = $this->candidate();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/skills', ['name' => 'Rust'])->assertCreated();
        $this->postJson('/api/admin/skills', ['name' => 'Rust'])->assertStatus(422);
        $this->postJson('/api/admin/categories', ['name' => 'Legal Services'])->assertCreated()->assertJsonPath('data.slug', 'legal-services');
        $this->postJson('/api/admin/notifications', ['title' => 'Hello', 'role' => 'candidate'])->assertCreated()->assertJsonPath('data.recipients', 1);
        $this->assertTrue($cand->notifications()->where('type', 'system')->exists());
    }

    public function test_admin_login_accepts_only_admins(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.role', 'admin')->assertJsonStructure(['data' => ['token']]);

        $cand = $this->candidate();
        $this->postJson('/api/admin/login', ['email' => $cand->email, 'password' => 'password'])->assertStatus(422);
        $this->assertSame(0, $cand->tokens()->count());
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'wrong-pass'])->assertStatus(422);
    }

    public function test_admin_edits_and_deletes_users_and_companies(): void
    {
        $cand = $this->candidate();
        $co = $this->company();
        Sanctum::actingAs($cand);
        $this->putJson("/api/admin/users/{$cand->id}", ['name' => 'X'])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/admin/users/{$cand->id}", ['name' => 'Renamed Faculty', 'phone' => '9999900000'])->assertOk()->assertJsonPath('data.name', 'Renamed Faculty');
        $this->putJson("/api/admin/users/{$cand->id}", ['email' => $co->email])->assertStatus(422);   // email already taken
        $this->putJson('/api/admin/companies/'.$co->company->id, ['company_name' => 'Edited Institute', 'city' => 'Vijayawada'])
            ->assertOk()->assertJsonPath('data.company_name', 'Edited Institute');

        $this->deleteJson("/api/admin/users/{$cand->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $cand->id]);
        $this->deleteJson('/api/admin/companies/'.$co->company->id)->assertOk();
        $this->assertDatabaseMissing('companies', ['id' => $co->company->id]);

        $adminId = $this->admin()->id;
        $this->deleteJson("/api/admin/users/{$adminId}")->assertForbidden();   // admins are protected
    }
}
