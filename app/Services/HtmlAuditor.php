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

    private const ISO_639_1 = 'aa ab ae af ak am an ar as av ay az ba be bg bh bi bm bn bo br bs ca ce ch co cr cs cu cv cy da de dv dz ee el en eo es et eu fa ff fi fj fo fr fy ga gd gl gn gu gv ha he hi ho hr ht hu hy hz ia id ie ig ii ik io is it iu ja jv ka kg ki kj kk kl km kn ko kr ks ku kv kw ky la lb lg li ln lo lt lu lv mg mh mi mk ml mn mr ms mt my na nb nd ne ng nl nn no nr nv ny oc oj om or os pa pi pl ps pt qu rm rn ro ru rw sa sc sd se sg si sk sl sm sn so sq sr ss st su sv sw ta te tg th ti tk tl tn to tr ts tt tw ty ug uk ur uz ve vi vo wa wo xh yi yo za zh zu';
    private const ISO_3166_1_ALPHA2 = 'ad ae af ag ai al am ao aq ar as at au aw ax az ba bb bd be bf bg bh bi bj bl bm bn bo bq br bs bt bv bw by bz ca cc cd cf cg ch ci ck cl cm cn co cr cu cv cw cx cy cz de dj dk dm do dz ec ee eg eh er es et fi fj fk fm fo fr ga gb gd ge gf gg gh gi gl gm gn gp gq gr gs gt gu gw gy hk hm hn hr ht hu id ie il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw ky kz la lb lc li lk lr ls lt lu lv ly ma mc md me mf mg mh mk ml mm mn mo mp mq mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz om pa pe pf pg ph pk pl pm pn pr ps pt pw py qa re ro rs ru rw sa sb sc sd se sg sh si sj sk sl sm sn so sr ss st sv sx sy sz tc td tf tg th tj tk tl tm tn to tr tt tv tw tz ua ug um us uy uz va vc ve vg vi vn vu wf ws ye yt za zm zw';

    private const SKIP_EXTENSION = '/\.(?:jpg|jpeg|png|gif|webp|svg|avif|ico|pdf|zip|rar|7z|gz|mp4|mp3|wav|mov|avi|webm|css|js|mjs|xml|json|txt|woff2?|ttf|eot)(?:$|\?)/i';

    public function __construct(
        private readonly UrlGuard $urls,
        private readonly LanguageDetector $languageDetector,
    ) {
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

        $h1Nodes = $xpath->query('//h1');
        $h1s = [];
        if ($h1Nodes instanceof DOMNodeList) {
            foreach ($h1Nodes as $node) {
                $text = $this->nodeText($node);
                if ($text !== '') {
                    $h1s[] = $text;
                }
            }
        }

        $h2Nodes = $xpath->query('//h2');
        $imageNodes = $xpath->query('//img');
        $images = ['total' => 0, 'missingAlt' => 0, 'emptyAlt' => 0];

        if ($imageNodes instanceof DOMNodeList) {
            $images['total'] = $imageNodes->length;

            foreach ($imageNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                if (! $node->hasAttribute('alt')) {
                    $images['missingAlt']++;
                } elseif ($this->clean($node->getAttribute('alt')) === '') {
                    $images['emptyAlt']++;
                }
            }
        }

        $visibleText = $this->visibleText($xpath);
        $wordCount = $this->countWords($visibleText);
        $languageDetection = $this->languageDetector->detect($visibleText);
        $fingerprintText = $this->fingerprintText($xpath);
        $contentHash = $this->countWords($fingerprintText) >= 80
            ? hash('sha256', $this->normalizeForHash($fingerprintText))
            : null;
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
            'xRobotsTag' => null,
            'hreflangs' => $hreflangs,
            'headings' => [
                'h1Count' => $h1Nodes instanceof DOMNodeList ? $h1Nodes->length : 0,
                'h1' => $h1s,
                'h2Count' => $h2Nodes instanceof DOMNodeList ? $h2Nodes->length : 0,
            ],
            'images' => $images,
            'wordCount' => $wordCount,
            'detectedLang' => $languageDetection['lang'],
            'languageConfidence' => $languageDetection['confidence'],
            'contentHash' => $contentHash,
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
            $this->issue($page, 'title_short', 'info', "Title is {$titleLength} characters (display heuristic, not a ranking rule).");
        } elseif ($titleLength > 60) {
            $this->issue($page, 'title_long', 'info', "Title is {$titleLength} characters (display heuristic, not a ranking rule).");
        }

        $descriptionLength = mb_strlen($page['description']);

        if ($descriptionLength === 0) {
            $this->issue($page, 'description_missing', 'warning', 'Missing meta description.');
        } elseif ($descriptionLength < 70) {
            $this->issue($page, 'description_short', 'info', "Meta description is {$descriptionLength} characters (display heuristic).");
        } elseif ($descriptionLength > 160) {
            $this->issue($page, 'description_long', 'info', "Meta description is {$descriptionLength} characters (display heuristic).");
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
        } elseif (
            $page['detectedLang'] !== null
            && $this->languageBase($page['lang']) !== $page['detectedLang']
        ) {
            $this->issue(
                $page,
                'html_lang_content_mismatch',
                'warning',
                'HTML lang="'.$page['lang'].'" but the visible main content looks like '.$page['detectedLang'].' (confidence '.round($page['languageConfidence'] * 100).'%).',
            );
        }

        if ($this->directiveContains($page['robots'], 'noindex')) {
            $this->issue($page, 'robots_noindex', 'info', 'Meta robots contains noindex; this page is intended not to appear in search results.');
        }

        if ($page['headings']['h1Count'] === 0) {
            $this->issue($page, 'h1_missing', 'info', 'No H1 heading found.');
        } elseif ($page['headings']['h1Count'] > 1) {
            $count = $page['headings']['h1Count'];
            $this->issue($page, 'h1_multiple', 'info', "Found {$count} H1 headings; review structure if this was not intentional.");
        }

        if ($page['images']['missingAlt'] > 0) {
            $count = $page['images']['missingAlt'];
            $this->issue($page, 'image_alt_missing', 'warning', "{$count} image(s) are missing an alt attribute.");
        }

        if ($page['wordCount'] > 0 && $page['wordCount'] < 80) {
            $this->issue($page, 'content_thin', 'info', 'Page has about '.$page['wordCount'].' visible words; review whether the amount of content is intentional.');
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
            $selfLang = null;
            $seen = [];

            foreach ($page['hreflangs'] as $entry) {
                if ($this->urls->normalize($entry['href']) === $self) {
                    $selfFound = true;
                    if ($entry['lang'] !== 'x-default') {
                        $selfLang = $entry['lang'];
                    }
                }

                if (isset($seen[$entry['lang']])) {
                    $this->issue($page, 'hreflang_duplicate_code', 'warning', 'Duplicate hreflang code: '.$entry['lang'].'.');
                }

                $seen[$entry['lang']] = true;

                if (! $this->validHreflangCode($entry['lang'])) {
                    $this->issue($page, 'hreflang_code_suspicious', 'warning', 'Unsupported or suspicious hreflang code: '.$entry['lang'].'.');
                }
            }

            if (! $selfFound) {
                $this->issue($page, 'hreflang_self_missing', 'warning', 'Hreflang set does not include a self-reference.');
            }

            if ($selfLang !== null && $page['lang'] !== null && $this->languageBase($selfLang) !== $this->languageBase($page['lang'])) {
                $this->issue(
                    $page,
                    'html_lang_hreflang_mismatch',
                    'info',
                    'HTML lang ('.$page['lang'].') differs from the self-referencing hreflang ('.$selfLang.').',
                );
            }
        }
    }

    private function validHreflangCode(string $code): bool
    {
        $code = strtolower($code);

        if ($code === 'x-default') {
            return true;
        }

        $parts = explode('-', $code);
        $language = $parts[0] ?? '';

        if (! $this->listedCode(self::ISO_639_1, $language)) {
            return false;
        }

        if (count($parts) === 1) {
            return true;
        }

        if (count($parts) === 2) {
            if ($language === 'zh' && in_array($parts[1], ['hans', 'hant'], true)) {
                return true;
            }

            return $this->listedCode(self::ISO_3166_1_ALPHA2, $parts[1]);
        }

        return count($parts) === 3
            && $language === 'zh'
            && in_array($parts[1], ['hans', 'hant'], true)
            && $this->listedCode(self::ISO_3166_1_ALPHA2, $parts[2]);
    }

    private function listedCode(string $list, string $code): bool
    {
        return str_contains(' '.$list.' ', ' '.strtolower($code).' ');
    }

    private function languageBase(string $code): string
    {
        return strtolower(explode('-', $code, 2)[0]);
    }

    private function directiveContains(?string $value, string $needle): bool
    {
        if ($value === null) {
            return false;
        }

        $tokens = preg_split('/[\s,;]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(strtolower($needle), $tokens, true);
    }

    private function visibleText(DOMXPath $xpath): string
    {
        $root = $this->first($xpath, '//main[1]')
            ?? $this->first($xpath, '//article[1]')
            ?? $this->first($xpath, '//body[1]');

        if ($root === null) {
            return '';
        }

        $nodes = $xpath->query(
            './/text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::svg) and not(ancestor::nav) and not(ancestor::footer)]',
            $root,
        );

        if (! $nodes instanceof DOMNodeList) {
            return '';
        }

        $parts = [];
        foreach ($nodes as $node) {
            $text = $this->clean($node->textContent ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode(' ', $parts));
    }

    private function fingerprintText(DOMXPath $xpath): string
    {
        $root = $this->first($xpath, '//main[1]')
            ?? $this->first($xpath, '//article[1]')
            ?? $this->first($xpath, '//body[1]');

        if ($root === null) {
            return '';
        }

        $nodes = $xpath->query(
            './/text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::svg) and not(ancestor::nav) and not(ancestor::footer) and not(ancestor::a) and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3) and not(ancestor::h4) and not(ancestor::h5) and not(ancestor::h6)]',
            $root,
        );

        if (! $nodes instanceof DOMNodeList) {
            return '';
        }

        $parts = [];
        foreach ($nodes as $node) {
            $text = $this->clean($node->textContent ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode(' ', $parts));
    }

    private function countWords(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function normalizeForHash(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
