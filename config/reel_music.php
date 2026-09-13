<?php

$revision = 'cf011c7016595833b550a88ff127f089188b25f8';
$raw = "https://raw.githubusercontent.com/0lhi/FreePD/{$revision}";
$source = "https://github.com/0lhi/FreePD/blob/{$revision}";
$track = static function (string $label, string $title, string $path) use ($raw, $source): array {
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

    return [
        'label' => $label,
        'title' => $title,
        'url' => "{$raw}/{$encodedPath}",
        'source_url' => "{$source}/{$encodedPath}",
    ];
};

return [
    'default' => 'bright',
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'license_name' => 'CC0 1.0 Universal',
    'license_url' => "{$source}/LICENSE",
    'tracks' => [
        'bright' => $track('Bright & upbeat', 'City Sunshine', 'Upbeat/City Sunshine.mp3'),
        'bright_advertime' => $track('Bright & upbeat', 'Advertime', 'Upbeat/Advertime.mp3'),
        'bright_funshine' => $track('Bright & upbeat', 'Funshine', 'Upbeat/Funshine.mp3'),
        'bright_inspiration' => $track('Bright & upbeat', 'Inspiration', 'Upbeat/Inspiration.mp3'),
        'bright_inventing_flight' => $track('Bright & upbeat', 'Inventing Flight', 'Upbeat/Inventing Flight.mp3'),
        'bright_motions' => $track('Bright & upbeat', 'Motions', 'Upbeat/Motions.mp3'),

        'elegant' => $track('Elegant & warm', 'Champ de tournesol', 'Romance/Champ de tournesol.mp3'),
        'elegant_horizon_flare' => $track('Elegant & warm', 'Horizon Flare', 'Romance/Horizon Flare.mp3'),
        'elegant_lovely_piano' => $track('Elegant & warm', 'Lovely Piano Song', 'Romance/Lovely Piano Song.mp3'),
        'elegant_night_venice' => $track('Elegant & warm', 'Night in Venice', 'Romance/Night in Venice.mp3'),
        'elegant_nostalgic_piano' => $track('Elegant & warm', 'Nostalgic Piano', 'Romance/Nostalgic Piano.mp3'),
        'elegant_romantic_inspiration' => $track('Elegant & warm', 'Romantic Inspiration', 'Romance/Romantic Inspiration.mp3'),

        'cinematic' => $track('Cinematic', 'Dreams of Vain', 'Scoring/Dreams of Vain.mp3'),
        'cinematic_magic_garden' => $track('Cinematic', 'Magic in the Garden', 'Scoring/Magic in the Garden.mp3'),
        'cinematic_travelers_notebook' => $track('Cinematic', 'Travelers Notebook', 'Scoring/Travelers Notebook.mp3'),
        'cinematic_novus_initium' => $track('Cinematic', 'Novus Initium', 'Scoring/Novus Initium.mp3'),
        'cinematic_slice_life' => $track('Cinematic', 'Slice of Life', 'Scoring/Slice of Life.mp3'),
        'cinematic_lagoon' => $track('Cinematic', 'The Lagoon', 'Scoring/The Lagoon.mp3'),

        'modern' => $track('Modern electronic', 'Arpent', 'Electronic/Arpent.mp3'),
        'modern_backbeat' => $track('Modern electronic', 'Backbeat', 'Electronic/Backbeat.mp3'),
        'modern_beat_one' => $track('Modern electronic', 'Beat One', 'Electronic/Beat One.mp3'),
        'modern_chronos' => $track('Modern electronic', 'Chronos', 'Electronic/Chronos.mp3'),
        'modern_fireworks' => $track('Modern electronic', 'Fireworks', 'Electronic/Fireworks.mp3'),
        'modern_meditating_beat' => $track('Modern electronic', 'Meditating Beat', 'Electronic/Meditating Beat.mp3'),

        'playful' => $track('Playful', 'Busybody', 'Comedy/Busybody.mp3'),
        'playful_fancy_family' => $track('Playful', 'Fancy Family', 'Comedy/Fancy Family.mp3'),
        'playful_going_bananas' => $track('Playful', 'Going Bananas', 'Comedy/Going Bananas.mp3'),
        'playful_hopeful' => $track('Playful', 'Hopeful', 'Comedy/Hopeful.mp3'),
        'playful_llama_pajama' => $track('Playful', 'Llama in Pajama', 'Comedy/Llama in Pajama.mp3'),
        'playful_spring_chicken' => $track('Playful', 'Spring Chicken', 'Comedy/Spring Chicken.mp3'),

        'calm_infinite_peace' => $track('Calm & ambient', 'Infinite Peace', 'Miscellaneous/Infinite Peace.mp3'),
        'calm_study_relax' => $track('Calm & ambient', 'Study and Relax', 'Miscellaneous/Study and Relax.mp3'),
        'calm_river_meditation' => $track('Calm & ambient', 'River Meditation', 'Miscellaneous/River Meditation.mp3'),
        'calm_painting_room' => $track('Calm & ambient', 'Painting Room', 'Miscellaneous/Painting Room.mp3'),
        'calm_martini_sunset' => $track('Calm & ambient', 'Martini Sunset', 'Miscellaneous/Martini Sunset.mp3'),

        'epic_adventure' => $track('Epic & powerful', 'Adventure', 'Epic/Adventure.mp3'),
        'epic_heroic_adventure' => $track('Epic & powerful', 'Heroic Adventure', 'Epic/Heroic Adventure.mp3'),
        'epic_honor_bound' => $track('Epic & powerful', 'Honor Bound', 'Epic/Honor Bound.mp3'),
        'epic_new_hero' => $track('Epic & powerful', 'New Hero in Town', 'Epic/New Hero in Town.mp3'),
        'epic_strength_titans' => $track('Epic & powerful', 'Strength of the Titans', 'Epic/Strength of the Titans.mp3'),

        'world_ambient_bongos' => $track('World & acoustic', 'Ambient Bongos', 'World/Ambient Bongos.mp3'),
        'world_be_jammin' => $track('World & acoustic', 'Be Jammin', 'World/Be Jammin.mp3'),
        'world_coy_koi' => $track('World & acoustic', 'Coy Koi', 'World/Coy Koi.mp3'),
        'world_forest_frolic' => $track('World & acoustic', 'Forest Frolic Loop', 'World/Forest Frolic Loop.mp3'),
        'world_shenzhen_nightlife' => $track('World & acoustic', 'Shenzhen Nightlife', 'World/Shenzhen Nightlife.mp3'),
    ],
];
