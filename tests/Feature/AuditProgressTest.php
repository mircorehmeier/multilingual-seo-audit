<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class AuditProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('audit.admin_password', 'test-secret');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
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
    }

    private function insertRun(string $id, string $url, string $environment, string $date): void
    {
        DB::table('audit_runs')->insert([
            'id' => $id, 'url' => $url, 'environment' => $environment,
            'max_pages' => 25, 'pages_done' => 25, 'status' => 'completed',
            'expires_at' => now()->addDays(7), 'created_at' => $date, 'updated_at' => $date,
        ]);
    }

    public function test_only_latest_audit_compares_to_immediately_previous_on_same_site_and_environment(): void
    {
        $first = '00000000-0000-4000-8000-000000000001';
        $previous = '00000000-0000-4000-8000-000000000002';
        $latest = '00000000-0000-4000-8000-000000000003';
        $staging = '00000000-0000-4000-8000-000000000004';
        $this->insertRun($first, 'https://example.com/', 'live', '2026-10-20 10:00:00');
        $this->insertRun($previous, 'https://www.example.com/sub/', 'live', '2026-10-21 10:00:00');
        $this->insertRun($latest, 'https://example.com/', 'live', '2026-10-22 10:00:00');
        $this->insertRun($staging, 'https://stage.example.com/', 'staging', '2026-10-22 11:00:00');

        $auth = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test-secret'];
        $this->withServerVariables($auth)
            ->get('/admin/audits/'.$latest.'/progress')
            ->assertRedirect('/?audit='.$latest.'&previous='.$previous);
        $this->withServerVariables($auth)
            ->get('/admin/audits/'.$previous.'/progress')
            ->assertRedirect(route('audit.history'));
        $this->withServerVariables($auth)
            ->get('/admin/audits/'.$staging.'/progress')
            ->assertRedirect(route('audit.history'));
    }

    public function test_progress_history_requires_password(): void
    {
        $this->get('/admin/audits')->assertStatus(401);
    }
}
