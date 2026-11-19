<?php

namespace GeoEngine\Agent;

use GeoEngine\Agent\Models\Page;
use GeoEngine\Agent\Models\Product;
use GeoEngine\Agent\Models\SeoMeta;
use GeoEngine\Agent\Models\Setting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * scan_site_structure — enumerate the site's public surface and submit it
 * to the platform as CrawlPage rows.
 *
 * Sources (union, deduped):
 *  - Laravel GET routes (names + URIs)
 *  - geoengine_seo managed paths
 *  - geoengine_pages published slugs
 *  - geoengine_products urls (when the mirror carries them)
 *  - config('geoengine.extra_paths') — manual additions
 *
 * The result posts to /agent/submit-pages which the platform binds to
 * the active SiteCrawl (crawl_id from the task payload).
 */
class SiteScanner
{
    public static function scan(array $payload = []): array
    {
        $maxPages = max(1, (int) ($payload['max_pages'] ?? 100000));
        $pages = [];
        $seen = [];

        $add = function (string $path, array $extra = []) use (&$pages, &$seen, $maxPages) {
            if (count($pages) >= $maxPages) {
                return;
            }
            $path = SeoStore::normalizePath($path);
            if (isset($seen[$path])) {
                return;
            }
            $seen[$path] = true;
            $pages[] = ['path' => $path, 'url' => $path] + $extra;
        };

        // 1. Registered GET routes — skip framework/api/agent internals.
        try {
            foreach (Route::getRoutes() as $route) {
                if (!in_array('GET', $route->methods(), true) && !in_array('HEAD', $route->methods(), true)) {
                    continue;
                }
                $uri = trim((string) $route->uri(), '/');
                if ($uri === '' || str_contains($uri, '{')) {
                    continue; // parameterized routes can't be enumerated
                }
                if (preg_match('#^(api|geo-engine|_ignition|telescope|horizon|vendor)(/|$)#', $uri)) {
                    continue;
                }
                $add('/' . $uri);
            }
        } catch (\Throwable) {
            // Route facade unavailable in console edge cases — continue
        }

        // 2. Managed SEO rows.
        try {
            foreach (SeoMeta::query()->limit($maxPages)->pluck('path') as $p) {
                $add((string) $p);
            }
        } catch (\Throwable) {
        }

        // 3. Managed pages.
        try {
            foreach (Page::query()->whereNotNull('published_at')->pluck('slug') as $slug) {
                $add('/' . ltrim((string) $slug, '/'));
            }
        } catch (\Throwable) {
        }

        // 4. Product mirror urls.
        try {
            foreach (Product::query()->limit($maxPages)->get(['fields']) as $prod) {
                $fields = $prod->fields ?? [];
                $url = $fields['url'] ?? $fields['permalink'] ?? null;
                if ($url) {
                    $add((string) $url);
                }
            }
        } catch (\Throwable) {
        }

        // 4.5 Site's own catalog/posts models (when configured) — without
        // this the scan only sees geoengine_* tables, i.e. pages the
        // module already touched, never the real catalog.
        $siteModels = [
            ['model' => (string) config('geoengine.catalog_model', ''), 'map' => (array) config('geoengine.catalog_map', []), 'prefix' => (string) config('geoengine.catalog_url_prefix', '/product/')],
            ['model' => (string) config('geoengine.posts_model', ''), 'map' => (array) config('geoengine.posts_map', []), 'prefix' => (string) config('geoengine.posts_url_prefix', '/blog/')],
        ];
        foreach ($siteModels as $m) {
            $modelClass = $m['model'];
            if ($modelClass === '' || !class_exists($modelClass)) {
                continue;
            }
            try {
                $map = $m['map'];
                $urlCol = $map['url'] ?? $map['permalink'] ?? $map['slug'] ?? null;
                if (!$urlCol) {
                    continue;
                }
                foreach ($modelClass::query()->limit($maxPages)->pluck($urlCol) as $u) {
                    $u = trim((string) $u);
                    if ($u === '') {
                        continue;
                    }
                    // Full URL → strip host; bare slug → wrap in the
                    // configured prefix (/product/{slug}, /blog/{slug}).
                    if (preg_match('#^https?://#i', $u)) {
                        $u = (string) (parse_url($u, PHP_URL_PATH) ?: '');
                    } elseif (!str_starts_with($u, '/')) {
                        $u = $m['prefix'] . $u;
                    }
                    if ($u !== '' && $u !== '/') {
                        $add($u);
                    }
                }
            } catch (\Throwable) {
            }
        }

        // 5. Extra manual paths from config.
        foreach ((array) config('geoengine.extra_paths', []) as $p) {
            $add((string) $p);
        }

        // Enrich with managed meta where present (title/description help
        // the platform's audit skip already-optimized pages).
        try {
            $meta = SeoMeta::query()->get()->keyBy('path');
            foreach ($pages as &$pg) {
                $row = $meta->get(SeoStore::normalizePath($pg['path']));
                if ($row) {
                    $pg['title'] = $row->title;
                    $pg['meta_description'] = $row->description;
                    $pg['has_structured_data'] = !empty($row->json_ld);
                }
            }
            unset($pg);
        } catch (\Throwable) {
        }

        return [
            'pages' => array_values($pages),
            'count' => count($pages),
        ];
    }

    /**
     * Execute scan_site_structure end-to-end: collect + submit.
     */
    public static function runAndSubmit(array $payload, AgentClient $client): array
    {
        $result = self::scan($payload);
        $pages = $result['pages'];
        $crawlId = isset($payload['crawl_id']) ? (int) $payload['crawl_id'] : null;

        // Chunk submissions — submit-pages accepts batches.
        $chunks = array_chunk($pages, 500);
        $submitted = 0;
        foreach ($chunks as $i => $chunk) {
            // crawl_id on EVERY chunk — the server's "latest running crawl"
            // fallback misroutes pages when two scans overlap, so later
            // chunks would land in a different project's audit.
            $ok = $client->submitPages($chunk, $crawlId);
            if (!$ok) {
                return [
                    'success' => false,
                    'error' => 'submit-pages failed on chunk ' . ($i + 1),
                    'pages_found' => count($pages),
                    'pages_submitted' => $submitted,
                ];
            }
            $submitted += count($chunk);
        }

        return [
            'success' => true,
            'pages_found' => count($pages),
            'pages_submitted' => $submitted,
        ];
    }
}
