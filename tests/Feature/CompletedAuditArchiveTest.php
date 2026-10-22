<?php

namespace Tests\Feature;

use App\Services\CompletedAuditArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompletedAuditArchiveTest extends TestCase
{
    public function test_quick_audit_is_archived_and_can_be_loaded_from_result_endpoint(): void
    {
        Storage::fake('local');
        Schema::create('audit_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('url', 2048);
            $table->string('environment', 12);
            $table->unsignedSmallInteger('max_pages');
            $table->unsignedSmallInteger('pages_done')->default(0);
            $table->string('status', 20);
            $table->string('error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        $report = [
            'startUrl' => 'https://example.com/',
            'summary' => ['pages' => 1],
            'pages' => [['url' => 'https://example.com/']],
        ];
        $id = app(CompletedAuditArchive::class)->store($report, 'https://example.com/', 25, 'staging');

        $this->assertDatabaseHas('audit_runs', [
            'id' => $id, 'status' => 'completed', 'max_pages' => 25, 'pages_done' => 1,
            'environment' => 'staging',
        ]);
        Storage::disk('local')->assertExists('seo-audits/'.$id.'.json.gz');
        $this->get('/api/audits/'.$id.'/result')->assertOk()
            ->assertJsonPath('summary.pages', 1);
    }
}
