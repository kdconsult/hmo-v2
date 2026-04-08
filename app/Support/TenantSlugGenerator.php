<?php

declare(strict_types=1);

namespace App\Support;

class TenantSlugGenerator
{
    private static array $adjectives = [
        'amber', 'azure', 'bold', 'bright', 'calm', 'clear', 'cool', 'crisp',
        'deep', 'fair', 'fast', 'firm', 'fresh', 'gold', 'grand', 'green',
        'keen', 'kind', 'light', 'lush', 'mild', 'neat', 'nova', 'peak',
        'pure', 'quick', 'rich', 'safe', 'sharp', 'slim', 'smart', 'solar',
        'solid', 'still', 'strong', 'swift', 'true', 'warm', 'wide', 'wise',
    ];

    private static array $nouns = [
        'arc', 'base', 'bay', 'bridge', 'cloud', 'coast', 'crest', 'delta',
        'dome', 'drift', 'dune', 'edge', 'field', 'flow', 'forge', 'gate',
        'grove', 'harbor', 'haven', 'helm', 'hill', 'hub', 'isle', 'lake',
        'lane', 'ledge', 'marsh', 'mesa', 'mist', 'peak', 'pier', 'pine',
        'plain', 'port', 'ridge', 'river', 'rock', 'shore', 'slope', 'spring',
        'stone', 'stream', 'tide', 'trail', 'vale', 'view', 'wave', 'wood',
    ];

    public static function generate(): string
    {
        $adjective = self::$adjectives[array_rand(self::$adjectives)];
        $noun = self::$nouns[array_rand(self::$nouns)];

        return "{$adjective}-{$noun}";
    }
}
