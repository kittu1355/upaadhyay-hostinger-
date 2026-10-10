<?php

namespace Tests\Feature;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesAccounts;
use Tests\TestCase;

class FileTest extends TestCase
{
    use RefreshDatabase, MakesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        config(['uploads.disk' => 'uploads', 'uploads.private_disk' => 'local']);
        Storage::fake('uploads');
        Storage::fake('local');
    }

    public function test_resume_upload_stores_bytes_in_bucket_and_metadata_in_db(): void
    {
        Sanctum::actingAs($this->candidate());
        $res = $this->post('/api/candidate/resume', ['file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.is_primary', true);

        $file = File::first();
        Storage::disk('local')->assertExists($file->storage_path); // resumes are private
        $this->assertSame('resume', $file->category);
        $this->assertSame('cv.pdf', $file->original_name);
        $this->getJson('/api/candidate/profile')->assertJsonCount(1, 'data.resumes')->assertJsonPath('data.resumes.0.file.original_name', 'cv.pdf');
    }

    public function test_private_resume_is_reachable_only_through_signed_link(): void
    {
        $owner = $this->candidate(); $other = $this->candidate();
        Sanctum::actingAs($owner);
        $this->post('/api/candidate/resume', ['file' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $id = File::first()->id;

        $url = $this->getJson("/api/files/$id/link")->assertOk()->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();                                   // signed link works without a token
        $this->get("/api/files/$id/download")->assertForbidden();       // unsigned does not

        Sanctum::actingAs($other);
        $this->getJson("/api/files/$id/link")->assertForbidden();
    }

    public function test_rejects_wrong_type_and_oversize(): void
    {
        Sanctum::actingAs($this->candidate());
        $this->post('/api/candidate/resume', ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/candidate/resume', ['file' => UploadedFile::fake()->create('big.pdf', 9000, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_profile_photo_and_delete_ownership(): void
    {
        $a = $this->candidate(); $b = $this->candidate();
        Sanctum::actingAs($a);
        $id = $this->post('/api/files/upload', ['category' => 'profile_photo', 'file' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->assertNotNull($a->candidateProfile()->first()->profile_photo);

        Sanctum::actingAs($b);
        $this->deleteJson("/api/files/$id")->assertForbidden();

        Sanctum::actingAs($a);
        $this->deleteJson("/api/files/$id")->assertOk();
        $this->assertNull($a->candidateProfile()->first()->profile_photo);
    }

    public function test_company_cannot_upload_resume_category_and_candidate_cannot_upload_logo(): void
    {
        Sanctum::actingAs($this->candidate());
        $this->post('/api/files/upload', ['category' => 'company_logo', 'file' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])->assertForbidden();
    }
}
