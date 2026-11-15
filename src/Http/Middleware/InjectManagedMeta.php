<?php

namespace GeoEngine\Agent\Http\Middleware;

use Closure;
use GeoEngine\Agent\Models\Redirect;
use GeoEngine\Agent\Models\SeoMeta;
use GeoEngine\Agent\Models\Setting;
use GeoEngine\Agent\SeoStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * InjectManagedMeta — renders managed SEO into HTML responses.
 *
 * Push this into the "web" middleware group (the service provider does it
 * automatically unless geoengine.inject_middleware=false). For each HTML
 * response it:
 *   1. applies a managed redirect when the path matches geoengine_redirects
 *   2. injects <title>, meta description/keywords, canonical, OG, Twitter,
 *      JSON-LD and FAQ JSON-LD before </head>
 *
 * Only text/html GET/HEAD responses are touched; API/JSON/file responses
 * pass through. Managed rows win over the template's static tags — the
 * platform is the source of truth once it manages a page.
 */
class InjectManagedMeta
{
    public function handle(Request $request, Closure $next): Response
    {
        // Managed redirects first — cheap indexed lookup.
        $redirect = $this->matchRedirect($request);
        if ($redirect) {
            return redirect($redirect['to'], $redirect['code']);
        }

        $response = $next($request);

        try {
            if ($this->shouldInject($request, $response)) {
                $this->inject($request, $response);
            }
        } catch (\Throwable $e) {
            // Managed-meta injection must NEVER break the page.
            report($e);
        }

        return $response;
    }

    private function matchRedirect(Request $request): ?array
    {
        if (Setting::get('native_seo_off')) {
            return null;
        }
        $path = SeoStore::normalizePath($request->getPathInfo());
        $row = Redirect::where('from_path', $path)->first();
        if (!$row) {
            return null;
        }
        $to = (string) $row->to_path;
        if (!preg_match('#^https?://#i', $to) && str_starts_with($to, '/')) {
            $to = $request->getSchemeAndHttpHost() . $to;
        }
        return ['to' => $to, 'code' => (int) $row->code];
    }

    private function shouldInject(Request $request, Response $response): bool
    {
        if (Setting::get('native_seo_off')) {
            return false;
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $type = $response->headers->get('Content-Type', '');
        return str_contains($type, 'text/html');
    }

    private function inject(Request $request, Response $response): void
    {
        $content = $response->getContent();
        if (!is_string($content) || stripos($content, '</head>') === false) {
            return;
        }

        $path = SeoStore::normalizePath($request->getPathInfo());
        $row = SeoMeta::where('path', $path)->first();
        if (!$row) {
            return;
        }

        $tags = [];
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/');

        if ($row->title) {
            $tags[] = '<title>' . e($row->title) . '</title>';
        }
        if ($row->description) {
            $tags[] = '<meta name="description" content="' . e($row->description) . '">';
        }
        if ($row->keywords) {
            $tags[] = '<meta name="keywords" content="' . e($row->keywords) . '">';
        }
        if ($row->h1) {
            // h1 is content, not head — exposed via extra meta tag for
            // templates that want it; never fabricate an h1 element.
            $tags[] = '<meta name="geoengine:h1" content="' . e($row->h1) . '">';
        }
        if (!$row->indexable) {
            $tags[] = '<meta name="robots" content="noindex,nofollow">';
        }
        $canonical = $row->canonical_url ?: ($siteUrl !== '' ? $siteUrl . $path : null);
        if ($canonical) {
            $tags[] = '<link rel="canonical" href="' . e($canonical) . '">';
        }

        $ogTitle = $row->og['title'] ?? $row->title;
        $ogDesc = $row->og['description'] ?? $row->description;
        $ogImage = $row->og['image'] ?? null;
        if ($ogTitle) $tags[] = '<meta property="og:title" content="' . e($ogTitle) . '">';
        if ($ogDesc) $tags[] = '<meta property="og:description" content="' . e($ogDesc) . '">';
        if ($ogImage) $tags[] = '<meta property="og:image" content="' . e($ogImage) . '">';
        $tags[] = '<meta property="og:type" content="website">';
        if ($canonical) $tags[] = '<meta property="og:url" content="' . e($canonical) . '">';

        $twTitle = $row->twitter['title'] ?? $ogTitle;
        $twDesc = $row->twitter['description'] ?? $ogDesc;
        $twImage = $row->twitter['image'] ?? $ogImage;
        if ($twTitle) $tags[] = '<meta name="twitter:title" content="' . e($twTitle) . '">';
        if ($twDesc) $tags[] = '<meta name="twitter:description" content="' . e($twDesc) . '">';
        if ($twImage) $tags[] = '<meta name="twitter:image" content="' . e($twImage) . '">';
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';

        // JSON-LD: managed schema + FAQPage generated from faq items.
        $schemas = [];
        if (is_array($row->json_ld)) {
            $schemas[] = $row->json_ld;
        } elseif (is_string($row->json_ld) && $row->json_ld !== '') {
            $decoded = json_decode($row->json_ld, true);
            if (is_array($decoded)) {
                $schemas[] = $decoded;
            }
        }
        if (is_array($row->faq) && $row->faq) {
            $mainEntity = [];
            foreach ($row->faq as $item) {
                $q = $item['question'] ?? $item['q'] ?? '';
                $a = $item['answer'] ?? $item['a'] ?? '';
                if ($q !== '' && $a !== '') {
                    $mainEntity[] = [
                        '@type' => 'Question',
                        'name' => $q,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
                    ];
                }
            }
            if ($mainEntity) {
                $schemas[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $mainEntity];
            }
        }
        foreach ($schemas as $schema) {
            $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json) {
                $tags[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        if (!$tags) {
            return;
        }

        // Strip pre-existing template tags for keys we emit so the rendered
        // HTML never carries two <title> or conflicting <link rel="canonical">
        // tags (crawlers ignore duplicated canonicals and read the first title).
        $headEnd = stripos($content, '</head>');
        $head = substr($content, 0, $headEnd);
        $tail = substr($content, $headEnd);
        if ($row->title) {
            $head = (string) preg_replace('#<title[^>]*>.*?</title>#is', '', $head);
        }
        if ($canonical) {
            $head = (string) preg_replace('#<link\s+[^>]*rel=["\']canonical["\'][^>]*>#i', '', $head);
        }
        $stripNames = [];
        if ($row->description) $stripNames[] = 'description';
        if ($row->keywords) $stripNames[] = 'keywords';
        if (!$row->indexable) $stripNames[] = 'robots';
        if ($ogTitle) $stripNames[] = 'og:title';
        if ($ogDesc) $stripNames[] = 'og:description';
        if ($ogImage) $stripNames[] = 'og:image';
        $stripNames[] = 'og:type';
        if ($canonical) $stripNames[] = 'og:url';
        if ($twTitle) $stripNames[] = 'twitter:title';
        if ($twDesc) $stripNames[] = 'twitter:description';
        if ($twImage) $stripNames[] = 'twitter:image';
        $stripNames[] = 'twitter:card';
        foreach ($stripNames as $metaKey) {
            $head = (string) preg_replace(
                '#<meta\s+[^>]*(?:name|property)=["\']' . preg_quote($metaKey, '#') . '["\'][^>]*>#i',
                '',
                $head
            );
        }

        $block = "<!-- GEO Engine managed meta -->\n" . implode("\n", $tags) . "\n<!-- /GEO Engine -->\n";
        $response->setContent($head . $block . $tail);
    }
}
