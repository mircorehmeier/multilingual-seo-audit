<?php

namespace Tests\Unit;

use App\Services\UrlGuard;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public function test_tracking_parameters_and_fragments_are_removed(): void
    {
        $guard = new UrlGuard();

        $url = $guard->normalize('https://Example.com/page?utm_source=test&id=7#section');

        $this->assertSame('https://example.com/page?id=7', $url);
    }

    public function test_relative_urls_are_resolved(): void
    {
        $guard = new UrlGuard();

        $url = $guard->resolve('https://example.com/en/page/', '../other/');

        $this->assertSame('https://example.com/en/other/', $url);
    }
}
