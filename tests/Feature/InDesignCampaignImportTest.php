<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CampaignTemplate;
use App\Models\Media;
use App\Services\CampaignBlockRenderer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Importing an InDesign HTML export as a reusable campaign template.
 *
 * The marketing team designs in InDesign and exports HTML5. That export is an
 * iframed, absolutely-positioned, per-word-<span> page on a fixed print canvas —
 * unrenderable in Outlook and most of Gmail. These tests hold the importer to
 * the thing that actually matters: that what comes out the other side is real
 * email, with the copy, the pictures and the reading order intact.
 *
 * Minimal-schema sqlite harness, same pattern as CampaignDraftTest and
 * BulkEmailCampaignTest, so this runs in CI rather than behind the MySQL gate.
 */
class InDesignCampaignImportTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Schema::disableForeignKeyConstraints();

        foreach (['campaign_templates', 'media', 'personal_access_tokens', 'admin_users'] as $table) {
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

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('original_name')->nullable();
            $table->string('path');
            $table->string('url');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('collection')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->text('blocks');
            $table->text('theme')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/indesign-import'));

        parent::tearDown();
    }

    // ── harness ───────────────────────────────────────────────────────────

    private function admin(string $role = 'admin'): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => 'Marketer ' . $this->seq,
            'email'                   => "marketer{$this->seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function headers(?AdminUser $admin = null): array
    {
        $admin ??= $this->admin();

        // auth:sanctum memoises the resolved user on the guard instance, and
        // that instance survives between requests inside one test method.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    /** A real PNG of the given size — the importer measures pixels, not names. */
    private function png(int $width, int $height, array $rgb = [40, 90, 110]): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    /**
     * Builds an InDesign-shaped export: the iframe shell, the generated
     * stylesheet with `#_idContainer` translations and `span.CharOverride` type
     * sizes, and a page whose every word is its own positioned span.
     *
     * Deliberately reconstructed rather than checked in as a 2MB fixture — and
     * deliberately faithful to the real thing, right down to the 0.05 scale
     * wrapper that makes 420px type render at 21px.
     *
     * @param  array<string, string>  $extraFiles
     */
    private function export(array $extraFiles = []): UploadedFile
    {
        $css = <<<'CSS'
        #_idContainer000 { transform:translate(0.000px,10.000px) rotate(0.000deg) scale(1.000,1.000); position:absolute; }
        #_idContainer001 { transform:translate(20.000px,240.000px) rotate(0.000deg) scale(1.000,1.000); position:absolute; }
        #_idContainer002 { transform:translate(20.000px,300.000px) rotate(0.000deg) scale(1.000,1.000); position:absolute; }
        #_idContainer003 { transform:translate(20.000px,360.000px) rotate(0.000deg) scale(1.000,1.000); position:absolute; }
        #_idContainer004 { transform:translate(20.000px,420.000px) rotate(0.000deg) scale(1.000,1.000); position:absolute; }
        span.CharOverride-1 { color:#000000; font-family:Montserrat, sans-serif; font-size:420px; font-weight:bold; }
        span.CharOverride-2 { color:#000000; font-family:Montserrat, sans-serif; font-size:220px; font-weight:normal; }
        span.CharOverride-3 { color:#C4B07C; font-family:Montserrat, sans-serif; font-size:280px; font-weight:bold; text-transform:uppercase; }
        CSS;

        // One <p>, several `top` values: InDesign's visual line breaks inside a
        // single paragraph. One `top`, several `left` values: the words.
        $line = function (array $words, string $class, float $top): string {
            $out  = '';
            $left = 0.0;

            foreach ($words as $word) {
                $out .= '<span class="' . $class . '" style="position:absolute;top:' . $top . 'px;left:' . $left . 'px;">'
                    . $word . '</span>';
                $left += 500.0;
            }

            return $out;
        };

        $frame = function (string $id, string $inner): string {
            return '<div id="' . $id . '" class="Basic-Text-Frame">'
                . '<div style="width:0px;height:0px;position:absolute;top:0px;left:0px;'
                . 'transform: translate(0px,1.00px) rotate(0deg) scale(0.05);">'
                . $inner . '</div></div>';
        };

        $page = '<!DOCTYPE html><html><head><meta charset="utf-8" />'
            . '<link href="../css/idGeneratedStyles.css" rel="stylesheet" type="text/css" /></head>'
            . '<body id="publication" style="width:595px;height:1089px;background-color:white;">'
            . '<div style="position:absolute;overflow:hidden;left:0px;top:0px;width:595.28px;height:1089.00px;background-color:white">'

            // A photograph, then the display heading, then body copy, then a run
            // of short lines that were bullets in the original.
            . '<div id="_idContainer000" class="_idGenObjectStyle-Disabled">'
            . '<img src="../image/hero.png" alt="Hero" /></div>'

            . $frame('_idContainer001', '<p class="Basic-Paragraph">'
                . $line(['The ', 'Future ', 'of ', 'Fuel ', 'Savings'], 'CharOverride-1', 40.0)
                . '</p>')

            . $frame('_idContainer002', '<p class="Basic-Paragraph">'
                . $line(['Fuel ', 'costs ', 'are ', 'a ', 'major ', 'part ', 'of ', 'everyday ', 'operating ', 'expenses ', 'and ', 'this ', 'sentence ', 'is ', 'long ', 'enough ', 'to ', 'be ', 'a ', 'real ', 'paragraph ', 'rather ', 'than ', 'a ', 'bullet ', 'point ', 'in ', 'anyone\'s ', 'reading.'], 'CharOverride-2', 30.0)
                . $line(['It ', 'continues ', 'onto ', 'a ', 'second ', 'line.'], 'CharOverride-2', 250.0)
                . '</p>')

            // The gold hairline InDesign draws under a heading, as a PNG.
            . '<div id="_idContainer003" class="_idGenObjectStyle-Disabled">'
            . '<img src="../image/rule.png" alt="" /></div>'

            . $frame('_idContainer004',
                '<p class="Basic-Paragraph">' . $line(['Designed ', 'for'], 'CharOverride-3', 30.0) . '</p>'
                . '<p class="Basic-Paragraph">' . $line(['Passenger ', 'Vehicles'], 'CharOverride-2', 300.0) . '</p>'
                . '<p class="Basic-Paragraph">' . $line(['Commercial ', 'Vehicles'], 'CharOverride-2', 600.0) . '</p>'
                . '<p class="Basic-Paragraph">' . $line(['Public ', 'Transport'], 'CharOverride-2', 900.0) . '</p>')

            . '</div></body></html>';

        $shell = '<!DOCTYPE html><html><head></head><body>'
            . '<iframe id="contentIFrame" src="publication-web-resources/html/publication.html"></iframe>'
            . '<div class="prev">&#10094;</div><div class="next">&#10095;</div></body></html>';

        return $this->zip([
            'index.html'                                    => $shell,
            'publication-web-resources/html/publication.html' => $page,
            'publication-web-resources/css/idGeneratedStyles.css' => $css,
            // A photograph, and a hairline. 900x50 mirrors the real export,
            // whose gold rules come out around 47px tall — thin enough to be a
            // line, tall enough not to be filtered as a stray sliver.
            'publication-web-resources/image/hero.png'      => $this->png(800, 400),
            'publication-web-resources/image/rule.png'      => $this->png(900, 50, [196, 176, 124]),
            'font/Montserrat-Bold.ttf'                      => 'not really a font',
        ] + $extraFiles);
    }

    /** @param array<string, string> $files */
    private function zip(array $files, string $name = 'export.zip'): UploadedFile
    {
        $path    = tempnam(sys_get_temp_dir(), 'idexport') . '.zip';
        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $entry => $contents) {
            $archive->addFromString($entry, $contents);
        }

        $archive->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    // ── the ask: an export becomes a reusable template, with no backend work ──

    public function test_an_indesign_export_becomes_a_saved_reusable_template(): void
    {
        $response = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
            'name' => 'Fuel Eco Tech launch',
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');

        $this->assertTrue($data['saved']);
        $this->assertSame('Fuel Eco Tech launch', $data['name']);

        $template = CampaignTemplate::find($data['template_id']);

        $this->assertNotNull($template);
        $this->assertSame($data['blocks'], $template->blocks);

        // The point of saving it: the next campaign starts from this design
        // without anyone exporting or importing anything again.
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/admin/campaign-templates/' . $template->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Fuel Eco Tech launch');
    }

    public function test_the_copy_survives_being_scattered_across_positioned_spans(): void
    {
        $blocks = $this->importBlocks();

        $text = collect($blocks)->flatMap(fn ($b) => match ($b['type']) {
            'heading', 'text' => [$b['text']],
            'list'            => $b['items'],
            default           => [],
        })->implode("\n");

        // Words reassembled across `left`, lines rejoined across `top`.
        $this->assertStringContainsString('The Future of Fuel Savings', $text);
        $this->assertStringContainsString('Fuel costs are a major part of everyday operating expenses', $text);

        // A line break inside a paragraph is InDesign justifying the column, not
        // the author's intent — it must not become a break in the email.
        $this->assertStringContainsString('reading. It continues onto a second line.', $text);
    }

    public function test_reading_order_comes_from_the_page_not_the_markup(): void
    {
        $types = array_column($this->importBlocks(), 'type');

        // The hero photograph sits at the top of the page and must open the
        // email, whatever order InDesign happened to emit its containers in.
        $this->assertSame('image', $types[0]);
        $this->assertSame('heading', $types[1]);
    }

    public function test_display_type_becomes_a_heading_and_body_copy_does_not(): void
    {
        $blocks = collect($this->importBlocks());

        $heading = $blocks->firstWhere('text', 'The Future of Fuel Savings');

        $this->assertNotNull($heading);
        $this->assertSame('heading', $heading['type']);
        // Set largest in the document, so it is the top of the hierarchy.
        $this->assertSame('large', $heading['level']);

        $body = $blocks->first(fn ($b) => str_starts_with($b['text'] ?? '', 'Fuel costs are'));

        $this->assertSame('text', $body['type']);
    }

    public function test_a_run_of_short_lines_is_recovered_as_one_bullet_list(): void
    {
        $list = collect($this->importBlocks())->firstWhere('type', 'list');

        $this->assertNotNull($list, 'The bulleted run should come back as a list block.');
        $this->assertSame(
            ['Passenger Vehicles', 'Commercial Vehicles', 'Public Transport'],
            $list['items']
        );
    }

    public function test_a_hairline_becomes_a_divider_and_never_an_image(): void
    {
        $blocks = $this->importBlocks();

        $this->assertContains('divider', array_column($blocks, 'type'));

        // Rendered as an image it would be a full-width bar across the email,
        // and it would sit in the Media Library forever as a reusable asset.
        foreach ($blocks as $block) {
            if ($block['type'] === 'image') {
                $this->assertStringNotContainsString('rule', $block['url']);
            }
        }

        $this->assertSame(0, Media::where('original_name', 'rule.png')->count());
    }

    public function test_photographs_land_in_the_media_library_for_reuse(): void
    {
        $response = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
            'name' => 'Launch',
        ])->assertStatus(201);

        $media = $response->json('data.media');

        $this->assertCount(1, $media);
        $this->assertNotNull(Media::find($media[0]['media_id']));

        // Absolute URL, per the project rule — a relative path in an email
        // resolves against the recipient's mail client and shows nothing.
        $this->assertStringStartsWith('http', $media[0]['url']);

        $block = collect($response->json('data.blocks'))->firstWhere('type', 'image');

        $this->assertSame($media[0]['url'], $block['url']);
    }

    public function test_the_result_is_email_html_not_the_indesign_layout(): void
    {
        $html = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
            'name' => 'Launch',
        ])->assertStatus(201)->json('data.preview_html');

        // The three things that make the export unsendable, all gone.
        $this->assertStringNotContainsString('position:absolute', $html);
        $this->assertStringNotContainsString('transform:', $html);
        $this->assertStringNotContainsString('<iframe', $html);

        // And the three the send job depends on, all present.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('[[UNSUBSCRIBE_URL]]', $html);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $this->assertTrue($document->loadHTML($html));
        libxml_clear_errors();
    }

    public function test_imported_blocks_pass_the_same_validation_a_hand_built_campaign_does(): void
    {
        $blocks = $this->importBlocks();

        $this->assertSame([], app(CampaignBlockRenderer::class)->validateBlocks($blocks));
    }

    // ── colour: the failure that would ship a blank email ──────────────────

    public function test_type_that_only_worked_on_artwork_falls_back_to_the_house_theme(): void
    {
        // White type on a white page. InDesign gets away with it because the
        // words sit on a full-bleed photograph; email has no such background,
        // so taking these colours at face value sends an invisible message.
        $css = 'span.CharOverride-1 { color:#FFFFFF; font-size:220px; font-weight:normal; }'
            . '#_idContainer000 { transform:translate(0px,10px); position:absolute; }';

        $page = '<html><head><link href="../css/idGeneratedStyles.css" rel="stylesheet" type="text/css" /></head>'
            . '<body style="background-color:#FFFFFF;">'
            . '<div id="_idContainer000" class="Basic-Text-Frame">'
            . '<div style="transform: translate(0px,1px) scale(0.05);">'
            . '<p class="Basic-Paragraph"><span class="CharOverride-1" style="position:absolute;top:10px;left:0px;">'
            . 'Invisible on a white page unless someone checks</span></p></div></div></body></html>';

        $response = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->zip([
                'publication-web-resources/html/publication.html'      => $page,
                'publication-web-resources/css/idGeneratedStyles.css'  => $css,
            ]),
            'name' => 'White on white',
        ])->assertStatus(201);

        $theme = $response->json('data.theme');

        $this->assertSame(CampaignBlockRenderer::DEFAULT_THEME, $theme['preset']);
        $this->assertArrayNotHasKey('text_color', $theme);

        // And the marketer is told, rather than left to wonder why the colours
        // changed.
        $this->assertNotEmpty(array_filter(
            $response->json('data.warnings'),
            fn ($w) => str_contains($w, 'unreadable')
        ));
    }

    // ── safety: an admin upload is still an untrusted archive ──────────────

    public function test_an_archive_that_writes_outside_its_own_folder_is_refused(): void
    {
        $canary = storage_path('app/indesign-slip-canary.txt');
        @unlink($canary);

        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->zip([
                '../../../../indesign-slip-canary.txt'            => 'escaped',
                'publication-web-resources/html/publication.html' => '<html><body></body></html>',
            ]),
            'name' => 'Zip slip',
        ])->assertStatus(422)->assertJsonPath('code', 'import_failed');

        $this->assertFileDoesNotExist($canary);
    }

    public function test_a_file_that_is_not_a_zip_is_rejected_with_a_readable_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notazip') . '.zip';
        file_put_contents($path, 'this is a PDF, or a Word file, or anything else');

        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => new UploadedFile($path, 'design.zip', 'application/zip', null, true),
            'name' => 'Not a zip',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'import_failed');

        $this->assertSame(0, CampaignTemplate::count());
    }

    public function test_an_archive_with_no_indesign_page_says_so_instead_of_failing_obscurely(): void
    {
        $response = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->zip(['notes.css' => 'body { color: red; }']),
            'name' => 'Empty',
        ])->assertStatus(422);

        $this->assertStringContainsString('Publish Online', $response->json('message'));
    }

    public function test_scripts_and_fonts_in_the_archive_are_never_written_to_disk(): void
    {
        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export([
                'publication-web-resources/script/main.js' => 'alert("hi")',
            ]),
            'name' => 'With script',
        ])->assertStatus(201);

        // The workspace is deleted either way; this asserts the extraction
        // filter itself, by proving nothing of that shape ever reached the
        // Media Library or the blocks.
        $this->assertSame(0, Media::where('original_name', 'like', '%.js')->count());
        $this->assertSame(0, Media::where('original_name', 'like', '%.ttf')->count());
    }

    public function test_the_temporary_workspace_is_cleaned_up(): void
    {
        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
            'name' => 'Cleanup',
        ])->assertStatus(201);

        $workspace = storage_path('app/indesign-import');

        $this->assertTrue(
            ! is_dir($workspace) || File::directories($workspace) === [],
            'Every import must clean up after itself, or the disk fills with unpacked exports.'
        );
    }

    // ── dry run, permissions ──────────────────────────────────────────────

    public function test_a_dry_run_shows_the_result_without_saving_a_template(): void
    {
        $response = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file'    => $this->export(),
            'dry_run' => true,
        ])->assertOk();

        $this->assertFalse($response->json('data.saved'));
        $this->assertNotEmpty($response->json('data.blocks'));
        $this->assertSame(0, CampaignTemplate::count());
    }

    public function test_reviewing_the_same_export_repeatedly_does_not_duplicate_its_images(): void
    {
        // Reported by frontend: reviewing before saving is what a dry run is
        // for, so the feature working as intended was filling the Media Library
        // with copies of the same photographs.
        $this->importBlocks();
        $this->importBlocks();

        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
            'name' => 'After three reviews',
        ])->assertStatus(201);

        $this->assertSame(1, Media::count(), 'Three reviews and a save is still one photograph.');
    }

    public function test_a_genuinely_different_export_is_converted_again(): void
    {
        $this->importBlocks();

        // Same design, edited — different bytes, so it must not be served the
        // previous conversion.
        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file'    => $this->export(['publication-web-resources/image/second.png' => $this->png(700, 350, [10, 20, 30])]),
            'dry_run' => true,
        ])->assertOk();

        $this->assertSame(2, Media::count());
    }

    public function test_a_reused_conversion_is_dropped_when_its_media_has_been_deleted(): void
    {
        $first = $this->importBlocks();

        Media::query()->delete();

        $second = $this->importBlocks();

        // Otherwise the blocks would point at a URL nothing serves any more.
        $this->assertSame(1, Media::count());
        $this->assertNotSame(
            collect($first)->firstWhere('type', 'image')['url'],
            collect($second)->firstWhere('type', 'image')['url']
        );
    }

    /**
     * Reported from production: "The dry run field must be true or false."
     *
     * This endpoint is multipart/form-data and multipart carries every field as
     * a STRING, so the browser's FormData sends "true" — which Laravel's
     * `boolean` rule rejects, accepting only 1/0/"1"/"0". Every test here had
     * passed a real PHP bool, which never crosses the wire the way a browser
     * does, so the suite was green against a request shape no client sends.
     */
    #[DataProvider('truthyStrings')]
    public function test_dry_run_is_accepted_in_the_spelling_a_browser_actually_sends(string $sent): void
    {
        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file'    => $this->export(),
            'dry_run' => $sent,
        ])
            ->assertOk()
            ->assertJsonPath('data.saved', false);

        $this->assertSame(0, CampaignTemplate::count());
    }

    public static function truthyStrings(): array
    {
        return [['true'], ['1'], ['on']];
    }

    public function test_a_string_false_still_saves_rather_than_previewing(): void
    {
        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file'    => $this->export(),
            'dry_run' => 'false',
            'name'    => 'Sent as a string',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.saved', true);
    }

    public function test_an_unrecognisable_dry_run_is_refused_not_read_as_save(): void
    {
        // false means "write a template". A value nobody can interpret must not
        // be quietly read as the option that creates a row.
        $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file'    => $this->export(),
            'dry_run' => 'banana',
            'name'    => 'Garbage flag',
        ])->assertStatus(422)->assertJsonValidationErrors('dry_run');

        $this->assertSame(0, CampaignTemplate::count());
    }

    public function test_a_name_is_required_unless_it_is_a_dry_run(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_importing_requires_the_marketing_permission(): void
    {
        $this->withHeaders($this->headers($this->admin('editor')))
            ->post('/api/v1/admin/campaign-templates/import', [
                'file' => $this->export(),
                'name' => 'Nope',
            ])
            ->assertStatus(403);

        $this->assertSame(0, CampaignTemplate::count());
    }

    public function test_the_import_route_is_not_swallowed_by_the_template_id_route(): void
    {
        // `campaign-templates/{id}` has no numeric constraint, so registration
        // order is the only thing keeping `import` reachable.
        $this->withHeaders($this->headers())
            ->post('/api/v1/admin/campaign-templates/import', ['file' => $this->export(), 'name' => 'Routing'])
            ->assertStatus(201);
    }

    /**
     * The whole chain the editor actually walks, in one test.
     *
     * Reported from production: an imported design saved fine and the editor
     * listed its 20 blocks, but the preview pane showed its empty state. That
     * can only be one of two things — the blocks not surviving the round trip
     * through the template, or the preview endpoint refusing them — so this
     * walks both rather than reasoning about which.
     */
    public function test_a_saved_import_reloads_and_previews_through_the_campaign_endpoints(): void
    {
        $imported = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => $this->export(),
            'name' => 'Round trip',
        ])->assertStatus(201);

        $id = $imported->json('data.template_id');

        // 1. Reopening the template hands back the blocks, not a count of them.
        $reloaded = $this->withHeaders($this->headers())
            ->getJson('/api/v1/admin/campaign-templates/' . $id)
            ->assertOk();

        $blocks = $reloaded->json('data.blocks');
        $theme  = $reloaded->json('data.theme');

        $this->assertIsArray($blocks);
        $this->assertNotEmpty($blocks, 'The saved template must reload with its blocks.');
        $this->assertSame($imported->json('data.blocks'), $blocks);

        // Sequential keys, not an object — a JSON object here would arrive in
        // JavaScript as {"0":…} and every .length / .map in the editor would
        // read it as empty.
        $this->assertSame(range(0, count($blocks) - 1), array_keys($blocks));

        // 2. And the preview endpoint renders exactly what came back.
        $preview = $this->withHeaders($this->headers())->postJson('/api/v1/admin/bulk-emails/preview', [
            'blocks' => $blocks,
            'theme'  => $theme,
        ])->assertOk();

        $this->assertStringContainsString('The Future of Fuel Savings', $preview->json('data.html'));
        $this->assertNotEmpty($preview->json('data.text'));
    }

    // ── the real export the marketing team actually produced ───────────────

    public function test_the_real_marketing_export_converts_end_to_end(): void
    {
        $folder = base_path('Email Marketing');

        if (! is_dir($folder)) {
            $this->markTestSkipped('The marketers\' export is not checked into the repo — this runs where the folder is present.');
        }

        $path    = tempnam(sys_get_temp_dir(), 'realexport') . '.zip';
        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach (File::allFiles($folder) as $file) {
            $archive->addFile($file->getPathname(), $file->getRelativePathname());
        }

        $archive->close();

        $response = $this->withHeaders($this->headers())->post('/api/v1/admin/campaign-templates/import', [
            'file' => new UploadedFile($path, 'export.zip', 'application/zip', null, true),
            'name' => 'Fuel Eco Tech',
        ])->assertStatus(201);

        $blocks = $response->json('data.blocks');
        $text   = json_encode($blocks);

        $this->assertStringContainsString('The Future of Fuel Savings', $text);
        $this->assertStringContainsString('Passenger Vehicles', $text);
        $this->assertContains('list', array_column($blocks, 'type'));
        $this->assertNotEmpty($response->json('data.media'));
        $this->assertSame([], app(CampaignBlockRenderer::class)->validateBlocks($blocks));

        @unlink($path);
    }

    // ── helper ────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function importBlocks(): array
    {
        return $this->withHeaders($this->headers())
            ->post('/api/v1/admin/campaign-templates/import', [
                'file'    => $this->export(),
                'dry_run' => true,
            ])
            ->assertOk()
            ->json('data.blocks');
    }
}
