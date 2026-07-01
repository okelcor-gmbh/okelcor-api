<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\Media;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Media Library: direct upload/list/delete, plus inline article body-image
 * uploads landing in the shared library (collection "articles") so a
 * previously-uploaded image is browsable and reusable from the Media panel
 * instead of becoming an orphaned file only one article links to.
 * See FRONTEND_NOTE_media-library.md.
 *
 * Does NOT use RefreshDatabase: the full migration set includes a MySQL-only
 * legacy migration sqlite can't run. Creates only the tables this test
 * touches, same pattern as NewsletterSubscriptionTest / BulkEmailCampaignTest.
 */
class MediaLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        foreach (['media', 'articles', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 300)->unique();
            $table->string('image', 500)->nullable();
            $table->date('published_at')->nullable();
            $table->tinyInteger('is_published')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 300);
            $table->string('original_name', 300);
            $table->string('path', 500);
            $table->string('url', 500);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('alt_text', 300)->nullable();
            $table->string('collection', 100)->nullable()->default('general');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        foreach (['media', 'articles', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function admin(string $role = 'editor'): AdminUser
    {
        $admin = AdminUser::create([
            'name'                    => 'Test Admin',
            'email'                   => 'admin' . uniqid() . '@test.com',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);

        return $admin;
    }

    public function test_body_image_upload_creates_media_row_in_articles_collection(): void
    {
        $article = Article::create(['slug' => 'test-article-' . uniqid()]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/articles/{$article->id}/body-image", [
                'image' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['url', 'path', 'media_id']]);

        $mediaId = $response->json('data.media_id');
        $media   = Media::find($mediaId);

        $this->assertNotNull($media);
        $this->assertSame('articles', $media->collection);
        $this->assertSame($response->json('data.url'), $media->url);
    }

    public function test_body_image_then_appears_in_media_library_listing(): void
    {
        $article = Article::create(['slug' => 'test-article-' . uniqid()]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/articles/{$article->id}/body-image", [
                'image' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            ])
            ->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/media?collection=articles')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Direct Media Library endpoints
    // -------------------------------------------------------------------------

    public function test_upload_lists_and_returns_a_copyable_absolute_url(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/media', [
                'file'       => UploadedFile::fake()->image('logo.png', 400, 300),
                'collection' => 'general',
                'alt_text'   => 'Company logo',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.collection', 'general')
            ->assertJsonPath('data.alt_text', 'Company logo');

        $url = $response->json('data.url');
        $this->assertStringStartsWith('http', $url);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/media')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', $url);
    }

    public function test_role_without_permission_cannot_access_media(): void
    {
        $this->actingAs($this->admin('sales_manager'), 'sanctum')
            ->getJson('/api/v1/admin/media')
            ->assertStatus(403);
    }

    public function test_destroy_removes_the_media_row(): void
    {
        $upload = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('a.png')])
            ->json('data');

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/media/{$upload['id']}")
            ->assertOk();

        $this->assertNull(Media::find($upload['id']));
    }
}
