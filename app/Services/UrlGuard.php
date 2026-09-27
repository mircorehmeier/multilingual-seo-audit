<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class UrlGuard
{
    private const TRACKING_PARAMS = [
        'fbclid',
        'gclid',
        'dclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
    ];

    public function normalize(string $input, ?string $base = null): string
    {
        $input = trim($input);

        if ($base !== null) {
            $input = (string) UriResolver::resolve(new Uri($base), new Uri($input));
        }

        $uri = new Uri($input);

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http:// and https:// URLs are supported.');
        }

        $host = strtolower($uri->getHost());
        if ($host === '') {
            throw new InvalidArgumentException('The URL must contain a hostname.');
        }

        $query = [];
        parse_str($uri->getQuery(), $query);

        foreach (array_keys($query) as $key) {
            $lower = strtolower((string) $key);

            if (str_starts_with($lower, 'utm_') || in_array($lower, self::TRACKING_PARAMS, true)) {
                unset($query[$key]);
            }
        }

        $port = $uri->getPort();
        if (($uri->getScheme() === 'https' && $port === 443) || ($uri->getScheme() === 'http' && $port === 80)) {
            $port = null;
        }

        return (string) $uri
            ->withHost($host)
            ->withPort($port)
            ->withFragment('')
            ->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function assertPublic(string $input): string
    {
        $url = $this->normalize($input);
        $uri = new Uri($url);

        if ($uri->getUserInfo() !== '') {
            throw new InvalidArgumentException('URLs containing credentials are not allowed.');
        }

        $host = strtolower($uri->getHost());

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa')
        ) {
            throw new InvalidArgumentException('Local or private network targets are not allowed.');
        }

        $addresses = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (! empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        $addresses = array_values(array_unique($addresses));

        if ($addresses === []) {
            throw new InvalidArgumentException('The hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            $public = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($public === false) {
                throw new InvalidArgumentException('The hostname resolves to a private or reserved network address.');
            }
        }

        return $url;
    }

    public function resolve(string $base, string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || preg_match('/^(?:mailto:|tel:|javascript:|data:)/i', $href)) {
            return null;
        }

        try {
            return $this->normalize($href, $base);
        } catch (\Throwable) {
            return null;
        }
    }

    public function origin(string $url): string
    {
        $uri = new Uri($url);
        $port = $uri->getPort();

        return strtolower($uri->getScheme().'://'.$uri->getHost().($port ? ':'.$port : ''));
    }

    public function sameOrigin(string $a, string $b): bool
    {
        return $this->origin($a) === $this->origin($b);
    }
}
