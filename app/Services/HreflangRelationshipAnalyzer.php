<?php

namespace App\Services;

/**
 * Cross-page hreflang checks based only on pages actually fetched by the audit.
 * Uncrawled targets are unknown, never silently treated as broken.
 */
class HreflangRelationshipAnalyzer
{
    public function analyze(array &$pages): array
    {
        $byUrl = [];
        foreach ($pages as $index => $page) {
            $key = $this->key((string) ($page['url'] ?? ''));
            if ($key !== null) {
                $byUrl[$key] = $index;
            }
        }

        $counts = ['checked' => 0, 'missingReciprocal' => 0, 'nonIndexableTargets' => 0, 'canonicalConflicts' => 0, 'unverifiedTargets' => 0, 'conflictingDeclarations' => 0];
        foreach ($pages as $index => &$page) {
            $sourceKey = $this->key((string) ($page['url'] ?? ''));
            if ($sourceKey === null) {
                continue;
            }
            $alternates = $page['hreflangs'] ?? [];
            $declarations = [];
            foreach ($alternates as $alternate) {
                $lang = strtolower(trim((string) ($alternate['lang'] ?? '')));
                $targetKey = $this->key((string) ($alternate['href'] ?? ''));
                if ($lang === '' || $targetKey === null) {
                    continue;
                }
                $declarations[$lang][$targetKey] = true;
            }

            foreach ($declarations as $lang => $targets) {
                if (count($targets) > 1) {
                    $counts['conflictingDeclarations']++;
                    $this->issue($page, 'hreflang_conflicting_targets', 'warning', 'Hreflang '.$lang.' declares multiple distinct target URLs.');
                }
            }

            foreach ($alternates as $alternate) {
                $lang = strtolower(trim((string) ($alternate['lang'] ?? '')));
                $targetKey = $this->key((string) ($alternate['href'] ?? ''));
                if ($targetKey === null || $lang === '' || $targetKey === $sourceKey) {
                    continue;
                }
                if (! isset($byUrl[$targetKey])) {
                    $counts['unverifiedTargets']++;
                    continue;
                }
                $counts['checked']++;
                $target = $pages[$byUrl[$targetKey]];
                $back = false;
                foreach ($target['hreflangs'] ?? [] as $reverse) {
                    if ($this->key((string) ($reverse['href'] ?? '')) === $sourceKey) {
                        $back = true;
                        break;
                    }
                }
                if (! $back) {
                    $counts['missingReciprocal']++;
                    $this->issue($page, 'hreflang_missing_reciprocal', 'warning', 'Hreflang '.$lang.' target '.$target['url'].' does not reference this page in its own hreflang set.');
                }
                $status = (int) ($target['status'] ?? 0);
                $robots = strtolower((string) ($target['robots'] ?? ''));
                $xRobots = strtolower((string) ($target['xRobotsTag'] ?? ''));
                $blocked = (($target['robotsTxt']['allowed'] ?? true) === false);
                if ($status < 200 || $status >= 300 || str_contains($robots, 'noindex') || str_contains($xRobots, 'noindex') || $blocked) {
                    $counts['nonIndexableTargets']++;
                    $this->issue($page, 'hreflang_nonindexable_target', 'warning', 'Hreflang '.$lang.' points to an audited non-indexable or unsuccessful page: '.$target['url'].'.');
                }
                $canonical = $this->key((string) ($target['canonical'] ?? ''));
                if ($canonical !== null && $canonical !== $targetKey) {
                    $counts['canonicalConflicts']++;
                    $this->issue($page, 'hreflang_target_canonical_conflict', 'warning', 'Hreflang '.$lang.' target has a different canonical: '.$target['url'].'.');
                }
            }
        }
        unset($page);
        return $counts;
    }

    private function key(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($path === '' ? '/' : $path).(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function issue(array &$page, string $code, string $severity, string $message): void
    {
        $page['issues'] ??= [];
        foreach ($page['issues'] as $existing) {
            if (($existing['code'] ?? '') === $code && ($existing['message'] ?? '') === $message) {
                return;
            }
        }
        $page['issues'][] = compact('code', 'severity', 'message');
    }
}
