<?php

$revision = 'cf011c7016595833b550a88ff127f089188b25f8';
$raw = "https://raw.githubusercontent.com/0lhi/FreePD/{$revision}";
$source = "https://github.com/0lhi/FreePD/blob/{$revision}";

return [
    'default' => 'bright',
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'license_name' => 'CC0 1.0 Universal',
    'license_url' => "{$source}/LICENSE",
    'tracks' => [
        'bright' => [
            'label' => 'Bright & upbeat',
            'title' => 'City Sunshine',
            'url' => "{$raw}/Upbeat/City%20Sunshine.mp3",
            'source_url' => "{$source}/Upbeat/City%20Sunshine.mp3",
        ],
        'elegant' => [
            'label' => 'Elegant & warm',
            'title' => 'Champ de tournesol',
            'url' => "{$raw}/Romance/Champ%20de%20tournesol.mp3",
            'source_url' => "{$source}/Romance/Champ%20de%20tournesol.mp3",
        ],
        'cinematic' => [
            'label' => 'Cinematic',
            'title' => 'Dreams of Vain',
            'url' => "{$raw}/Scoring/Dreams%20of%20Vain.mp3",
            'source_url' => "{$source}/Scoring/Dreams%20of%20Vain.mp3",
        ],
        'modern' => [
            'label' => 'Modern electronic',
            'title' => 'Arpent',
            'url' => "{$raw}/Electronic/Arpent.mp3",
            'source_url' => "{$source}/Electronic/Arpent.mp3",
        ],
        'playful' => [
            'label' => 'Playful',
            'title' => 'Busybody',
            'url' => "{$raw}/Comedy/Busybody.mp3",
            'source_url' => "{$source}/Comedy/Busybody.mp3",
        ],
    ],
];
