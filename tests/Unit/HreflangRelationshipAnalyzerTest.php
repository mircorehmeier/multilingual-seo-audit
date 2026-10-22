<?php

namespace Tests\Unit;

use App\Services\HreflangRelationshipAnalyzer;
use PHPUnit\Framework\TestCase;

class HreflangRelationshipAnalyzerTest extends TestCase
{
    private function page(string $url, array $hreflangs, ?string $canonical = null): array
    {
        return [
            'url' => $url,
            'status' => 200,
            'robots' => '',
            'hreflangs' => $hreflangs,
            'canonical' => $canonical ?? $url,
            'issues' => [],
        ];
    }

    public function test_reciprocal_languages_pass_and_uncrawled_targets_are_not_errors(): void
    {
        $pages = [
            $this->page('https://example.com/en/', [
                ['lang' => 'en', 'href' => 'https://example.com/en/'],
                ['lang' => 'es', 'href' => 'https://example.com/es/'],
                ['lang' => 'de', 'href' => 'https://example.com/de/'],
            ]),
            $this->page('https://example.com/es/', [
                ['lang' => 'es', 'href' => 'https://example.com/es/'],
                ['lang' => 'en', 'href' => 'https://example.com/en/'],
            ]),
        ];

        $counts = (new HreflangRelationshipAnalyzer())->analyze($pages);
        $this->assertSame(0, $counts['missingReciprocal']);
        $this->assertSame(1, $counts['unverifiedTargets']);
        $this->assertSame([], $pages[0]['issues']);
    }

    public function test_missing_return_canonical_conflicts_and_nonindex_are_reported(): void
    {
        $pages = [
            $this->page('https://example.com/en/', [['lang' => 'es', 'href' => 'https://example.com/es/']]),
            $this->page('https://example.com/es/', [], 'https://example.com/en/'),
        ];
        $pages[1]['robots'] = 'noindex';

        $counts = (new HreflangRelationshipAnalyzer())->analyze($pages);
        $this->assertSame(1, $counts['missingReciprocal']);
        $this->assertSame(1, $counts['nonIndexableTargets']);
        $this->assertSame(1, $counts['canonicalConflicts']);
        $codes = array_column($pages[0]['issues'], 'code');
        $this->assertContains('hreflang_missing_reciprocal', $codes);
        $this->assertContains('hreflang_nonindexable_target', $codes);
        $this->assertContains('hreflang_target_canonical_conflict', $codes);
    }

    public function test_two_distinct_targets_for_same_language_are_flagged(): void
    {
        $pages = [$this->page('https://example.com/en/', [
            ['lang' => 'es', 'href' => 'https://example.com/es/a/'],
            ['lang' => 'es', 'href' => 'https://example.com/es/b/'],
        ])];
        $counts = (new HreflangRelationshipAnalyzer())->analyze($pages);
        $this->assertSame(1, $counts['conflictingDeclarations']);
        $this->assertSame(2, $counts['unverifiedTargets']);
    }
}
