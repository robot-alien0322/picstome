<?php

// Eugene fork: AGPL-3.0 section 13 - offer the running source to network users.
return [
    'source_url' => env('PICSTOME_SOURCE_URL', 'https://github.com/robot-alien0322/picstome/tree/eugene/sets'),

    // Exact brand accent (hex) for client pages; overrides the preset color. Empty = use the preset.
    'brand_hex' => env('PICSTOME_BRAND_HEX'),

    // Client pages ignore the visitor's dark mode and always render light.
    'force_light' => (bool) env('PICSTOME_FORCE_LIGHT', false),

    // Client galleries look and behave like eugenebellau.com: justified rows with a margin,
    // and a framed photo viewer on near-black.
    'website_style' => (bool) env('PICSTOME_WEBSITE_STYLE', false),

    // Client page theme: system (follow the visitor), light, or dark. force_light=true still means light.
    'client_theme' => env('PICSTOME_CLIENT_THEME', 'system'),
    // Text shown under the gallery title on client pages (replaces the accent line).
    gallery_byline => env(PICSTOME_GALLERY_BYLINE),
];
