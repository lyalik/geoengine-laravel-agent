<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GEO Engine connection
    |--------------------------------------------------------------------------
    |
    | api_url     — GEO Engine platform base URL (default https://geoengine.pro)
    | project_key — project API key from GEO Engine → Settings → API Keys
    |               (starts with "ge_"). Used once at install to register the
    |               agent; afterwards the issued agent_token is used instead.
    | site_url    — public URL of this site (e.g. https://solaris.rf).
    |               Must match the domain of the project in GEO Engine.
    |
    */

    'api_url' => env('GEO_ENGINE_API_URL', 'https://geoengine.pro'),
    'project_key' => env('GEO_ENGINE_PROJECT_KEY', ''),
    'site_url' => env('GEO_ENGINE_SITE_URL', env('APP_URL', '')),

    /*
    |--------------------------------------------------------------------------
    | Agent state (written by geoengine:install / first register)
    |--------------------------------------------------------------------------
    */

    'agent_id' => env('GEO_ENGINE_AGENT_ID'),
    'agent_token' => env('GEO_ENGINE_AGENT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    |
    | The agent pulls pending tasks every poll_interval seconds when the
    | schedule entry `geoengine:poll` runs (default: every 5 minutes via
    | Laravel scheduler). Webhook push lowers latency when the site is
    | publicly reachable.
    |
    */

    'poll_interval' => (int) env('GEO_ENGINE_POLL_INTERVAL', 300),
    'poll_batch_size' => (int) env('GEO_ENGINE_POLL_BATCH', 50),
    'request_timeout' => (int) env('GEO_ENGINE_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    |
    | inject_middleware — auto-push InjectManagedMeta into the "web"
    |   middleware group so managed <title>/description/canonical/OG/
    |   JSON-LD land in HTML responses without touching Blade templates.
    |   API-only apps can disable it and read /api/geo-engine/page-meta.
    |
    | public_path_override — directory used for upload_file writes
    |   (llms.txt, robots.txt, .well-known/*). Defaults to public_path().
    |
    */

    'inject_middleware' => (bool) env('GEO_ENGINE_INJECT_MIDDLEWARE', true),
    'public_path' => env('GEO_ENGINE_PUBLIC_PATH'),

    // Extra public paths included in scan_site_structure submissions
    // (landing pages outside Laravel routes, campaign URLs, etc.).
    'extra_paths' => array_filter(array_map('trim', explode(',', (string) env('GEO_ENGINE_EXTRA_PATHS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Package self-location (self_update)
    |--------------------------------------------------------------------------
    |
    | package_path — directory holding this package's src/ files. Normally
    | resolved automatically; override only for unusual layouts.
    |
    */

    'package_path' => env('GEO_ENGINE_PACKAGE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Catalog source (optional)
    |--------------------------------------------------------------------------
    |
    | catalog_model — FQCN of an Eloquent model acting as the product
    |   source for sync_catalog. When set, the agent maps rows onto the
    |   catalog contract (id/name/description/price/url/images) using the
    |   column names below. When empty, sync_catalog serves the managed
    |   product mirror filled via POST /api/geo-engine/products.
    |
    */

    'catalog_model' => env('GEO_ENGINE_CATALOG_MODEL', ''),
    'catalog_map' => [
        'external_id' => 'id',
        'name' => 'name',
        'sku' => 'sku',
        'short_description' => 'short_description',
        'description' => 'description',
        'price' => 'price',
        'url' => 'url',
        'image' => 'image',
        'images' => 'images',
        'category' => 'category',
        'attributes' => 'attributes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blog/content source (optional)
    |--------------------------------------------------------------------------
    |
    | posts_model — FQCN of an Eloquent model for publish_content_plan /
    |   update_article to write into (e.g. App\\Models\\Post). Column names
    |   are mapped via posts_map; slug/title/content are required keys.
    |   When empty, articles are stored in geoengine_pages and served via
    |   the bridge's /api/geo-engine/pages endpoints.
    |
    */

    // URL prefixes used when a model row only stores a slug — the scan
    // builds /product/{slug} and /blog/{slug} paths from them.
    'catalog_url_prefix' => env('GEO_ENGINE_CATALOG_URL_PREFIX', '/product/'),
    'posts_url_prefix' => env('GEO_ENGINE_POSTS_URL_PREFIX', '/blog/'),

    'posts_model' => env('GEO_ENGINE_POSTS_MODEL', ''),
    'posts_map' => [
        'slug' => 'slug',
        'title' => 'title',
        'content' => 'content',
        'excerpt' => 'excerpt',
        'published_at' => 'published_at',
    ],

];
