<?php

return [

    /*
    |--------------------------------------------------------------------------
    | bunny.net API
    |--------------------------------------------------------------------------
    |
    | The account API key (bunny.net dashboard → Account settings → API) and
    | the ID of the pull zone in front of this site. Several zones (e.g. one
    | per domain) can be given as a comma-separated list. Without both
    | values nothing is purged; pages are still marked cacheable.
    |
    */

    'api_key' => env('BUNNY_API_KEY'),

    'pull_zone_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BUNNY_PULL_ZONE_ID', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Edge cache lifetime
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) bunny.net keeps a cached page. Statamic purges
    | pages whenever content changes, so this can be long. Browsers always
    | revalidate (max-age=0), so a purge reaches every visitor at once.
    |
    */

    'edge_ttl' => (int) env('BUNNY_CACHE_EDGE_TTL', 60 * 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Cache tag
    |--------------------------------------------------------------------------
    |
    | Every cached page is sent with this CDN-Tag header. Clearing the whole
    | static cache purges this tag only, so images, CSS and JS served
    | through the same pull zone stay cached.
    |
    */

    'tag' => env('BUNNY_CACHE_TAG', 'statamic-page'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('BUNNY_CACHE_TIMEOUT', 10),

];
