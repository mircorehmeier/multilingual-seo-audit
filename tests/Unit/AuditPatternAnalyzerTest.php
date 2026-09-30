<?php

namespace Tests\Unit;

use App\Services\AuditPatternAnalyzer;
use PHPUnit\Framework\TestCase;

class AuditPatternAnalyzerTest extends TestCase
{
    public function test_repeated_metadata_issue_is_grouped_as_likely_template_problem(): void
    {
        $pages = [];

        for ($i = 1; $i <= 4; $i++) {
            $pages[] = [
                'url' => 'https://example.com/page-'.$i.'/',
                'issues' => [[
                    'code' => 'description_missing',
                    'severity' => 'warning',
                    'message' => 'Missing meta description.',
                ]],
            ];
        }

        $patterns = (new AuditPatternAnalyzer())->analyze($pages);

        $this->assertCount(1, $patterns);
        $this->assertSame('description_missing', $patterns[0]['code']);
        $this->assertSame('likely-template', $patterns[0]['scope']);
        $this->assertSame(4, $patterns[0]['count']);
        $this->assertStringContainsString('Likely template-level issue', $patterns[0]['likelyRootCause']);
    }

    public function test_single_page_issue_is_not_misrepresented_as_a_pattern(): void
    {
        $patterns = (new AuditPatternAnalyzer())->analyze([
            [
                'url' => 'https://example.com/',
                'issues' => [[
                    'code' => 'canonical_missing',
                    'severity' => 'warning',
                    'message' => 'Missing canonical.',
                ]],
            ],
        ]);

        $this->assertSame([], $patterns);
    }
}
