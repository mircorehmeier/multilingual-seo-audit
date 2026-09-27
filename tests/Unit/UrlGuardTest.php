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

    public function test_query_parameters_are_sorted_for_stable_url_identity(): void
    {
        $guard = new UrlGuard();

        $this->assertSame(
            $guard->normalize('https://example.com/page?b=2&a=1'),
            $guard->normalize('https://example.com/page?a=1&b=2'),
        );
    }

    public function test_origin_without_path_normalizes_to_root_slash(): void
    {
        $guard = new UrlGuard();

        $this->assertSame('https://example.com/', $guard->normalize('https://example.com'));
    }

    public function test_relative_urls_are_resolved(): void
    {
        $guard = new UrlGuard();

        $url = $guard->resolve('https://example.com/en/page/', '../other/');

        $this->assertSame('https://example.com/en/other/', $url);
    }
}
