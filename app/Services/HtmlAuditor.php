<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

class HtmlAuditor
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const SKIP_EXTENSION = '/\.(?:jpg|jpeg|png|gif|webp|svg|avif|ico|pdf|zip|rar|7z|gz|mp4|mp3|wav|mov|avi|webm|css|js|mjs|xml|json|txt|woff2?|ttf|eot)(?:$|\?)/i';

    public function __construct(private readonly UrlGuard $urls)
    {
    }

    public function parse(string $url, int $status, string $contentType, string $html): array
    {
        $previous = libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $title = $this->nodeText($this->first($xpath, '//title[1]'));
        $description = $this->meta($xpath, 'name', 'description') ?? '';
        $robots = $this->meta($xpath, 'name', 'robots');

        $htmlNode = $this->first($xpath, '//html[1]');
        $lang = $htmlNode instanceof DOMElement ? $this->clean($htmlNode->getAttribute('lang')) : '';
        $lang = $lang !== '' ? $lang : null;

        $canonicalNodes = $xpath->query(
            "//link[contains(concat(' ', normalize-space(translate(@rel, '".self::UPPER."', '".self::LOWER."')), ' '), ' canonical ')]"
        );

        $canonicalRaw = null;
        if ($canonicalNodes instanceof DOMNodeList && $canonicalNodes->length > 0) {
            $node = $canonicalNodes->item(0);
            if ($node instanceof DOMElement) {
                $canonicalRaw = $node->getAttribute('href');
            }
        }

        $canonical = $canonicalRaw ? $this->urls->resolve($url, $canonicalRaw) : null;

        $hreflangs = [];
        $hreflangNodes = $xpath->query(
            "//link[contains(concat(' ', normalize-space(translate(@rel, '".self::UPPER."', '".self::LOWER."')), ' '), ' alternate ') and @hreflang]"
        );

        if ($hreflangNodes instanceof DOMNodeList) {
            foreach ($hreflangNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $code = strtolower($this->clean($node->getAttribute('hreflang')));
                $href = $this->urls->resolve($url, $node->getAttribute('href'));

                if ($code !== '' && $href !== null) {
                    $hreflangs[] = ['lang' => $code, 'href' => $href];
                }
            }
        }

        $links = [];
        $linkNodes = $xpath->query('//a[@href]');

        if ($linkNodes instanceof DOMNodeList) {
            foreach ($linkNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $resolved = $this->urls->resolve($url, $node->getAttribute('href'));

                if ($resolved !== null && ! preg_match(self::SKIP_EXTENSION, $resolved)) {
                    $links[$resolved] = true;
                }
            }
        }

        $structuredData = $this->structuredData($xpath);

        $page = [
            'url' => $url,
            'status' => $status,
            'contentType' => $contentType,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'lang' => $lang,
            'robots' => $robots,
            'hreflangs' => $hreflangs,
            'openGraph' => [
                'title' => $this->meta($xpath, 'property', 'og:title'),
                'description' => $this->meta($xpath, 'property', 'og:description'),
                'image' => $this->resolveNullable($url, $this->meta($xpath, 'property', 'og:image')),
                'url' => $this->resolveNullable($url, $this->meta($xpath, 'property', 'og:url')),
            ],
            'twitter' => [
                'card' => $this->meta($xpath, 'name', 'twitter:card'),
                'title' => $this->meta($xpath, 'name', 'twitter:title'),
                'description' => $this->meta($xpath, 'name', 'twitter:description'),
                'image' => $this->resolveNullable($url, $this->meta($xpath, 'name', 'twitter:image')),
            ],
            'structuredData' => $structuredData,
            'issues' => [],
            'links' => array_keys($links),
        ];

        $this->addPageIssues($page, $canonicalNodes instanceof DOMNodeList ? $canonicalNodes->length : 0, $canonicalRaw);

        return $page;
    }

    private function addPageIssues(array &$page, int $canonicalCount, ?string $canonicalRaw): void
    {
        $titleLength = mb_strlen($page['title']);

        if ($titleLength === 0) {
            $this->issue($page, 'title_missing', 'error', 'Missing <title>.');
        } elseif ($titleLength < 30) {
            $this->issue($page, 'title_short', 'info', "Title is {$titleLength} characters (short display heuristic).");
        } elseif ($titleLength > 60) {
            $this->issue($page, 'title_long', 'warning', "Title is {$titleLength} characters (long display heuristic).");
        }

        $descriptionLength = mb_strlen($page['description']);

        if ($descriptionLength === 0) {
            $this->issue($page, 'description_missing', 'warning', 'Missing meta description.');
        } elseif ($descriptionLength < 70) {
            $this->issue($page, 'description_short', 'info', "Meta description is {$descriptionLength} characters (short display heuristic).");
        } elseif ($descriptionLength > 160) {
            $this->issue($page, 'description_long', 'warning', "Meta description is {$descriptionLength} characters (long display heuristic).");
        }

        if ($canonicalCount === 0) {
            $this->issue($page, 'canonical_missing', 'warning', 'Missing rel="canonical".');
        }

        if ($canonicalCount > 1) {
            $this->issue($page, 'canonical_multiple', 'error', "Found {$canonicalCount} canonical tags.");
        }

        if ($canonicalCount > 0 && $canonicalRaw !== null && $canonicalRaw !== '' && $page['canonical'] === null) {
            $this->issue($page, 'canonical_invalid', 'error', 'Canonical URL is invalid.');
        }

        if ($page['canonical'] !== null && $this->urls->normalize($page['canonical']) !== $this->urls->normalize($page['url'])) {
            $this->issue($page, 'canonical_other', 'info', 'Canonical points to '.$page['canonical'].'.');
        }

        if ($page['lang'] === null) {
            $this->issue($page, 'html_lang_missing', 'warning', 'Missing html lang attribute.');
        }

        if ($page['openGraph']['title'] === null) {
            $this->issue($page, 'og_title_missing', 'info', 'Missing og:title.');
        }

        if ($page['openGraph']['description'] === null) {
            $this->issue($page, 'og_description_missing', 'info', 'Missing og:description.');
        }

        if ($page['openGraph']['image'] === null) {
            $this->issue($page, 'og_image_missing', 'info', 'Missing or invalid og:image.');
        }

        if ($page['twitter']['card'] === null) {
            $this->issue($page, 'twitter_card_missing', 'info', 'Missing twitter:card.');
        }

        if ($page['structuredData']['invalid'] > 0) {
            $count = $page['structuredData']['invalid'];
            $this->issue($page, 'jsonld_invalid', 'error', "{$count} JSON-LD block(s) contain invalid JSON.");
        }

        if ($page['hreflangs'] !== []) {
            $self = $this->urls->normalize($page['url']);
            $selfFound = false;
            $seen = [];

            foreach ($page['hreflangs'] as $entry) {
                if ($this->urls->normalize($entry['href']) === $self) {
                    $selfFound = true;
                }

                if (isset($seen[$entry['lang']])) {
                    $this->issue($page, 'hreflang_duplicate_code', 'warning', 'Duplicate hreflang code: '.$entry['lang'].'.');
                }

                $seen[$entry['lang']] = true;

                if (
                    $entry['lang'] !== 'x-default'
                    && ! preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/i', $entry['lang'])
                ) {
                    $this->issue($page, 'hreflang_code_suspicious', 'warning', 'Suspicious hreflang code: '.$entry['lang'].'.');
                }
            }

            if (! $selfFound) {
                $this->issue($page, 'hreflang_self_missing', 'warning', 'Hreflang set does not include a self-reference.');
            }
        }
    }

    private function structuredData(DOMXPath $xpath): array
    {
        $nodes = $xpath->query(
            "//script[translate(@type, '".self::UPPER."', '".self::LOWER."')='application/ld+json']"
        );

        $scripts = $nodes instanceof DOMNodeList ? $nodes->length : 0;
        $valid = 0;
        $invalid = 0;
        $types = [];

        if ($nodes instanceof DOMNodeList) {
            foreach ($nodes as $node) {
                $raw = trim($node->textContent ?? '');

                if ($raw === '') {
                    $invalid++;
                    continue;
                }

                try {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $valid++;
                    $this->collectTypes($decoded, $types);
                } catch (\JsonException) {
                    $invalid++;
                }
            }
        }

        $types = array_values(array_unique($types));
        sort($types);

        return [
            'scripts' => $scripts,
            'valid' => $valid,
            'invalid' => $invalid,
            'types' => $types,
        ];
    }

    private function collectTypes(mixed $node, array &$types): void
    {
        if (! is_array($node)) {
            return;
        }

        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectTypes($value, $types);
            }
        }
    }

    private function meta(DOMXPath $xpath, string $attribute, string $value): ?string
    {
        $query = "//meta[translate(@{$attribute}, '".self::UPPER."', '".self::LOWER."')='".strtolower($value)."'][1]";
        $node = $this->first($xpath, $query);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $content = $this->clean($node->getAttribute('content'));

        return $content !== '' ? $content : null;
    }

    private function resolveNullable(string $base, ?string $value): ?string
    {
        return $value !== null ? $this->urls->resolve($base, $value) : null;
    }

    private function first(DOMXPath $xpath, string $query): ?DOMNode
    {
        $nodes = $xpath->query($query);

        return $nodes instanceof DOMNodeList && $nodes->length > 0
            ? $nodes->item(0)
            : null;
    }

    private function nodeText(?DOMNode $node): string
    {
        return $node ? $this->clean($node->textContent ?? '') : '';
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function issue(array &$page, string $code, string $severity, string $message): void
    {
        foreach ($page['issues'] as $issue) {
            if ($issue['code'] === $code && $issue['message'] === $message) {
                return;
            }
        }

        $page['issues'][] = compact('code', 'severity', 'message');
    }
}
