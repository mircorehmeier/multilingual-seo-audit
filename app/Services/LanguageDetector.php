<?php

namespace App\Services;

class LanguageDetector
{
    private const STOPWORDS = [
        'en' => ['the','and','to','of','in','is','for','on','with','that','this','you','your','from','as','are','be','or','our','we','can','more','about'],
        'de' => ['der','die','das','und','ist','zu','den','von','mit','für','auf','ein','eine','im','sich','des','dem','auch','als','wir','sie','mehr','über'],
        'es' => ['el','la','los','las','y','de','que','en','para','con','una','un','por','es','del','se','su','como','más','al','nos','sobre'],
        'pt' => ['o','a','os','as','e','de','que','em','para','com','uma','um','por','é','do','da','se','sua','como','mais','ao','nos','sobre'],
        'fr' => ['le','la','les','et','de','des','du','que','en','pour','avec','une','un','par','est','dans','ce','cette','vous','plus','sur','nous'],
        'it' => ['il','lo','la','i','gli','le','e','di','che','in','per','con','una','un','è','del','della','da','si','come','più','su','noi'],
        'nl' => ['de','het','een','en','van','in','is','voor','met','op','dat','die','te','als','om','aan','uw','je','meer','ook','we','over'],
    ];

    public function detect(string $text): array
    {
        preg_match_all('/[\p{L}]+/u', mb_strtolower($text), $matches);
        $words = $matches[0] ?? [];

        if (count($words) < 60) {
            return ['lang' => null, 'confidence' => 0.0, 'scores' => []];
        }

        $frequencies = array_count_values($words);
        $scores = [];

        foreach (self::STOPWORDS as $lang => $stopwords) {
            $score = 0;

            foreach ($stopwords as $word) {
                $score += min(5, (int) ($frequencies[$word] ?? 0));
            }

            $scores[$lang] = $score;
        }

        arsort($scores);
        $langs = array_keys($scores);
        $topLang = $langs[0] ?? null;
        $top = $topLang !== null ? $scores[$topLang] : 0;
        $second = isset($langs[1]) ? $scores[$langs[1]] : 0;
        $total = array_sum($scores);
        $confidence = $total > 0 ? $top / $total : 0.0;

        if ($top < 8 || ($second > 0 && $top < $second * 1.6) || $confidence < 0.39) {
            return ['lang' => null, 'confidence' => round($confidence, 3), 'scores' => $scores];
        }

        return [
            'lang' => $topLang,
            'confidence' => round($confidence, 3),
            'scores' => $scores,
        ];
    }
}
