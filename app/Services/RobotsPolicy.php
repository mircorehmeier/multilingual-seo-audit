<?php

namespace App\Services;

class RobotsPolicy
{
    public function parse(string $body): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $rulesStarted = false;

        $flush = static function () use (&$groups, &$agents, &$rules, &$rulesStarted): void {
            if ($agents !== []) {
                $groups[] = [
                    'agents' => array_values(array_unique(array_map('strtolower', $agents))),
                    'rules' => $rules,
                ];
            }

            $agents = [];
            $rules = [];
            $rulesStarted = false;
        };

        foreach (preg_split('/\R/', $body) ?: [] as $rawLine) {
            $line = trim((string) preg_replace('/\s*#.*$/', '', $rawLine));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($rulesStarted) {
                    $flush();
                }

                if ($value !== '') {
                    $agents[] = strtolower($value);
                }

                continue;
            }

            if (! in_array($field, ['allow', 'disallow'], true) || $agents === []) {
                continue;
            }

            $rulesStarted = true;

            if ($field === 'disallow' && $value === '') {
                continue;
            }

            $rules[] = [
                'directive' => $field,
                'pattern' => $value,
            ];
        }

        $flush();

        return $groups;
    }

    public function decision(string $url, array $groups, string $userAgent = 'Googlebot'): array
    {
        if ($groups === []) {
            return [
                'allowed' => true,
                'matchedDirective' => null,
                'matchedRule' => null,
            ];
        }

        $userAgent = strtolower($userAgent);
        $specific = [];
        $wildcard = [];

        foreach ($groups as $group) {
            $agents = $group['agents'] ?? [];
            $matchesSpecific = false;
            $matchesWildcard = false;

            foreach ($agents as $agent) {
                $agent = strtolower((string) $agent);

                if ($agent === '*') {
                    $matchesWildcard = true;
                    continue;
                }

                if ($agent !== '' && str_starts_with($userAgent, $agent)) {
                    $matchesSpecific = true;
                }
            }

            if ($matchesSpecific) {
                array_push($specific, ...($group['rules'] ?? []));
            } elseif ($matchesWildcard) {
                array_push($wildcard, ...($group['rules'] ?? []));
            }
        }

        $rules = $specific !== [] ? $specific : $wildcard;
        if ($rules === []) {
            return [
                'allowed' => true,
                'matchedDirective' => null,
                'matchedRule' => null,
            ];
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query !== '') {
            $path .= '?'.$query;
        }

        $best = null;

        foreach ($rules as $rule) {
            $pattern = (string) ($rule['pattern'] ?? '');
            if ($pattern === '' || ! $this->matches($path, $pattern)) {
                continue;
            }

            $specificity = mb_strlen(str_replace(';
            $candidate = [
                'directive' => strtolower((string) ($rule['directive'] ?? 'disallow')),
                'pattern' => $pattern,
                'specificity' => $specificity,
            ];

            if (
                $best === null
                || $candidate['specificity'] > $best['specificity']
                || (
                    $candidate['specificity'] === $best['specificity']
                    && $candidate['directive'] === 'allow'
                    && $best['directive'] !== 'allow'
                )
            ) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return [
                'allowed' => true,
                'matchedDirective' => null,
                'matchedRule' => null,
            ];
        }

        return [
            'allowed' => $best['directive'] === 'allow',
            'matchedDirective' => $best['directive'],
            'matchedRule' => $best['pattern'],
        ];
    }

    private function matches(string $path, string $pattern): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace('\\*', '.*', $quoted);
        $regex = '#^'.$quoted.($anchored ? '$' : '').'#u';

        return preg_match($regex, $path) === 1;
    }
}
, '', $pattern));
            $candidate = [
                'directive' => strtolower((string) ($rule['directive'] ?? 'disallow')),
                'pattern' => $pattern,
                'specificity' => $specificity,
            ];

            if (
                $best === null
                || $candidate['specificity'] > $best['specificity']
                || (
                    $candidate['specificity'] === $best['specificity']
                    && $candidate['directive'] === 'allow'
                    && $best['directive'] !== 'allow'
                )
            ) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return [
                'allowed' => true,
                'matchedDirective' => null,
                'matchedRule' => null,
            ];
        }

        return [
            'allowed' => $best['directive'] === 'allow',
            'matchedDirective' => $best['directive'],
            'matchedRule' => $best['pattern'],
        ];
    }

    private function matches(string $path, string $pattern): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace('\\*', '.*', $quoted);
        $regex = '#^'.$quoted.($anchored ? '$' : '').'#u';

        return preg_match($regex, $path) === 1;
    }
}
