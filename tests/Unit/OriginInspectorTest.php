<?php

namespace Tests\Unit;

use App\Services\OriginInspector;
use App\Services\UrlGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OriginInspectorTest extends TestCase
{
    public function test_http_variant_that_redirects_to_https_is_treated_as_consolidated(): void
    {
        Http::fake([
            'https://1.1.1.1/' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
            'http://1.1.1.1/' => Http::response('', 301, ['Location' => 'https://1.1.1.1/']),
        ]);

        $result = (new OriginInspector(new UrlGuard()))->inspect(
            'https://1.1.1.1/',
            'https://1.1.1.1',
        );

        $http = collect($result['variants'])->firstWhere('url', 'http://1.1.1.1/');

        $this->assertSame('redirects-to-preferred', $http['relation']);
        $this->assertSame([], $result['issues']);
    }

    public function test_nonpreferred_http_origin_returning_200_is_warned(): void
    {
        Http::fake([
            'https://1.1.1.1/' => Http::response('preferred', 200, ['Content-Type' => 'text/html']),
            'http://1.1.1.1/' => Http::response('duplicate', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = (new OriginInspector(new UrlGuard()))->inspect(
            'https://1.1.1.1/',
            'https://1.1.1.1',
        );

        $codes = array_column($result['issues'], 'code');

        $this->assertContains('origin_variant_direct_200', $codes);
    }
}
