<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationTest extends TestCase
{
    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Find multilingual SEO problems')
            ->assertSee('Running version')
            ->assertSee('0.5.0');
    }

    public function test_health_endpoint_works(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'version' => '0.5.0-laravel',
            ]);
    }

    public function test_private_network_target_is_rejected(): void
    {
        $this->postJson('/api/audit', [
            'url' => 'http://127.0.0.1/',
            'maxPages' => 10,
        ])
            ->assertStatus(400)
            ->assertJsonStructure(['error']);
    }
}
