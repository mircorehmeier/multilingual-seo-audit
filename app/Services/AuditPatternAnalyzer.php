<?php

namespace App\Services;

class AuditPatternAnalyzer
{
    private const SEVERITY_RANK = [
        'error' => 3,
        'warning' => 2,
        'info' => 1,
    ];

    public function analyze(array $pages): array
    {
        $groups = [];
        $pageCount = max(1, count($pages));

        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');

            foreach ($page['issues'] ?? [] as $issue) {
                $code = (string) ($issue['code'] ?? 'unknown');
                $severity = (string) ($issue['severity'] ?? 'info');
                $key = $severity.'|'.$code;

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'code' => $code,
                        'severity' => $severity,
                        'count' => 0,
                        'urls' => [],
                        'messages' => [],
                    ];
                }

                $groups[$key]['count']++;
                if ($url !== '') {
                    $groups[$key]['urls'][$url] = true;
                }

                $message = trim((string) ($issue['message'] ?? ''));
                if ($message !== '') {
                    $groups[$key]['messages'][$message] = true;
                }
            }
        }

        $patterns = [];

        foreach ($groups as $group) {
            $urls = array_keys($group['urls']);

            if (count($urls) < 2) {
                continue;
            }

            $category = $this->category($group['code']);
            $scope = $this->scope($group['code'], count($urls), $pageCount);
            $messages = array_keys($group['messages']);

            $patterns[] = [
                'code' => $group['code'],
                'title' => $this->humanize($group['code']),
                'severity' => $group['severity'],
                'category' => $category,
                'scope' => $scope,
                'count' => count($urls),
                'occurrences' => $group['count'],
                'urls' => $urls,
                'exampleMessage' => $messages[0] ?? '',
                'likelyRootCause' => $this->rootCause($group['code'], $scope),
            ];
        }

        usort($patterns, function (array $a, array $b): int {
            $severity = (self::SEVERITY_RANK[$b['severity']] ?? 0)
                <=> (self::SEVERITY_RANK[$a['severity']] ?? 0);

            if ($severity !== 0) {
                return $severity;
            }

            return $b['count'] <=> $a['count'];
        });

        return array_slice($patterns, 0, 20);
    }

    private function category(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'title_'),
            str_starts_with($code, 'description_') => 'metadata',
            str_starts_with($code, 'og_'),
            str_starts_with($code, 'twitter_'),
            str_starts_with($code, 'social_') => 'social',
            str_starts_with($code, 'hreflang_'),
            str_starts_with($code, 'html_lang_') => 'multilingual',
            str_starts_with($code, 'canonical_') => 'canonical',
            str_starts_with($code, 'jsonld_') => 'structured-data',
            str_starts_with($code, 'internal_link_'),
            str_starts_with($code, 'broken_internal_'),
            str_starts_with($code, 'mixed_scheme_') => 'internal-links',
            str_starts_with($code, 'image_') => 'images',
            str_starts_with($code, 'robots_'),
            str_starts_with($code, 'x_robots_') => 'indexability',
            default => 'technical',
        };
    }

    private function scope(string $code, int $affectedPages, int $pageCount): string
    {
        $ratio = $affectedPages / max(1, $pageCount);

        if (
            $affectedPages >= 3
            && $ratio >= 0.5
            && $this->category($code) in ['metadata', 'social', 'multilingual', 'structured-data', 'images']
        ) {
            return 'likely-template';
        }

        if ($affectedPages === $pageCount && $pageCount > 1) {
            return 'sitewide';
        }

        return 'repeated';
    }

    private function rootCause(string $code, string $scope): string
    {
        $base = match ($this->category($code)) {
            'metadata' => 'Shared metadata generation, page templates, or repeated source content.',
            'social' => 'Shared social-preview template or missing page-level overrides.',
            'multilingual' => 'Shared language-routing or hreflang generation logic.',
            'canonical' => 'Shared canonical-generation or URL-normalization logic.',
            'structured-data' => 'Shared JSON-LD template or schema-generation logic.',
            'internal-links' => 'Repeated navigation, template links, or a shared outdated destination.',
            'images' => 'Shared image component or repeated content-authoring pattern.',
            'indexability' => 'Shared robots/indexability configuration.',
            default => 'A repeated implementation pattern rather than an isolated page problem.',
        };

        return $scope === 'likely-template'
            ? 'Likely template-level issue. '.$base
            : $base;
    }

    private function humanize(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }
}
