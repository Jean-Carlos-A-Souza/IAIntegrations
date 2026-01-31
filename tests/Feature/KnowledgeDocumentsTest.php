<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('knowledge.disk', 'local');
        config()->set('knowledge.process_async', false);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_upload_document_successfully(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant->id);

        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Hello world!'),
            'title' => 'Notas',
            'tags' => ['intro'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['document_id', 'status']);

        $document = Document::query()->firstOrFail();

        $this->assertSame($user->id, $document->owner_user_id);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_upload_invalid_type_fails(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant->id);

        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/knowledge/documents', [
            'file' => UploadedFile::fake()->create('manual.pdf', 10, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_listing_is_isolated_by_user(): void
    {
        $tenant = $this->createTenant();
        $userA = $this->createUser($tenant->id, 'a@example.com');
        $userB = $this->createUser($tenant->id, 'b@example.com');

        TenantContext::setTenant($tenant);

        $docA = Document::query()->create([
            'owner_user_id' => $userA->id,
            'tenant_id' => $tenant->id,
            'title' => 'Doc A',
            'original_name' => 'a.txt',
            'path' => 'knowledge/test/a.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'status' => 'processed',
            'tokens' => 0,
        ]);

        Document::query()->create([
            'owner_user_id' => $userB->id,
            'tenant_id' => $tenant->id,
            'title' => 'Doc B',
            'original_name' => 'b.txt',
            'path' => 'knowledge/test/b.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'status' => 'processed',
            'tokens' => 0,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/knowledge/documents');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $docA->id);
    }

    public function test_delete_other_user_document_is_forbidden(): void
    {
        $tenant = $this->createTenant();
        $userA = $this->createUser($tenant->id, 'a2@example.com');
        $userB = $this->createUser($tenant->id, 'b2@example.com');

        TenantContext::setTenant($tenant);

        $document = Document::query()->create([
            'owner_user_id' => $userB->id,
            'tenant_id' => $tenant->id,
            'title' => 'Doc B',
            'original_name' => 'b.txt',
            'path' => 'knowledge/test/b.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'status' => 'processed',
            'tokens' => 0,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson('/api/knowledge/documents/'.$document->id);

        $response->assertStatus(403);
    }

    public function test_update_document_successfully(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant->id);

        TenantContext::setTenant($tenant);

        $document = Document::query()->create([
            'owner_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'title' => 'Original Title',
            'original_name' => 'doc.txt',
            'path' => 'knowledge/test/doc.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'status' => 'processed',
            'tokens' => 0,
            'tags' => ['old'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson('/api/knowledge/documents/'.$document->id, [
                'title' => 'New Title',
                'tags' => ['new', 'updated'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('title', 'New Title')
            ->assertJsonPath('tags', ['new', 'updated']);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'title' => 'New Title',
        ]);
    }

    public function test_update_other_user_document_is_forbidden(): void
    {
        $tenant = $this->createTenant();
        $userA = $this->createUser($tenant->id, 'a3@example.com');
        $userB = $this->createUser($tenant->id, 'b3@example.com');

        TenantContext::setTenant($tenant);

        $document = Document::query()->create([
            'owner_user_id' => $userB->id,
            'tenant_id' => $tenant->id,
            'title' => 'Doc B',
            'original_name' => 'b.txt',
            'path' => 'knowledge/test/b.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'status' => 'processed',
            'tokens' => 0,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson('/api/knowledge/documents/'.$document->id, [
                'title' => 'Hacked Title',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'title' => 'Doc B', // Não mudou
        ]);
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'schema' => 'tenant_teste',
            'status' => 'active',
        ]);
    }

    private function createUser(?int $tenantId, string $email = 'user@example.com'): User
    {
        return User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'User Test',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'member',
        ]);
    }
}
