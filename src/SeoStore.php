<?php

namespace GeoEngine\Agent;

use GeoEngine\Agent\Models\SeoMeta;
use GeoEngine\Agent\Models\TaskHistory;

/**
 * Managed SEO store — geoengine_seo rows keyed by public path.
 *
 * Accepts both snake_case and camelCase field names (the platform sends
 * camelCase for og_/twitter_ fields in some payloads). Unknown fields land in
 * `extra` so nothing is silently dropped.
 */
class SeoStore
{
    private const KNOWN = [
        'title', 'description', 'keywords', 'h1',
        'og_title', 'og_description', 'og_image',
        'twitter_title', 'twitter_description', 'twitter_image',
        'canonical_url', 'canonical', 'indexable',
        'structured_data', 'structuredData', 'json_ld', 'schema',
        'faq_items', 'faq', 'content_markdown', 'custom_meta',
    ];

    /** Normalize a request path/URL to the store key ("/about"). */
    public static function normalizePath(string $urlOrPath): string
    {
        $p = trim($urlOrPath);
        if ($p === '') {
            return '/';
        }
        // Full URL → path part only.
        if (preg_match('#^https?://[^/]+#i', $p)) {
            $p = (string) (parse_url($p, PHP_URL_PATH) ?: '/');
            $q = parse_url($urlOrPath, PHP_URL_QUERY);
            if ($q && !str_contains($p, '?')) {
                // keep query out of the key — managed meta is per-path
            }
        }
        $p = '/' . ltrim($p, '/');
        $p = rtrim($p, '/');
        return $p === '' ? '/' : $p;
    }

    /**
     * Extract writable SEO fields from a payload (camelCase or snake_case).
     */
    public static function fieldsFrom(array $body): array
    {
        $out = [];
        $map = [
            'title' => ['title'],
            'description' => ['description'],
            'keywords' => ['keywords'],
            'h1' => ['h1'],
            'og_title' => ['og_title', 'ogTitle'],
            'og_description' => ['og_description', 'ogDescription'],
            'og_image' => ['og_image', 'ogImage'],
            'twitter_title' => ['twitter_title', 'twitterTitle'],
            'twitter_description' => ['twitter_description', 'twitterDescription'],
            'twitter_image' => ['twitter_image', 'twitterImage'],
            'canonical_url' => ['canonical_url', 'canonicalUrl', 'canonical'],
            'indexable' => ['indexable'],
            'structured_data' => ['structured_data', 'structuredData', 'json_ld', 'schema', 'jsonLd'],
            'faq' => ['faq_items', 'faq', 'faqItems'],
            'content_markdown' => ['content_markdown', 'contentMarkdown'],
            'custom_meta' => ['custom_meta', 'customMeta'],
        ];
        foreach ($map as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $body)) {
                    $value = $body[$alias];
                    if ($field === 'keywords' && is_array($value)) {
                        $value = implode(', ', array_map('strval', $value));
                    }
                    if ($field === 'structured_data' && is_string($value)) {
                        $decoded = json_decode($value, true);
                        $value = is_array($decoded) ? $decoded : ['@context' => 'https://schema.org', 'raw' => $value];
                    }
                    $out[$field] = $value;
                    break;
                }
            }
        }
        return $out;
    }

    /** Snapshot of the current row for rollback history. */
    public static function snapshot(?SeoMeta $row): array
    {
        return $row ? $row->only([
            'title', 'description', 'keywords', 'h1', 'canonical_url',
            'indexable', 'og', 'twitter', 'json_ld', 'faq', 'custom_meta', 'extra',
        ]) : [];
    }

    /**
     * Apply fields to a path. Returns [SeoMeta, changedFields, beforeSnapshot].
     */
    public static function apply(string $path, array $fields, ?int $taskId = null, string $taskType = ''): array
    {
        $path = self::normalizePath($path);
        $row = SeoMeta::where('path', $path)->first();
        $before = self::snapshot($row);

        $sets = [];
        $og = $row?->og ?? [];
        $twitter = $row?->twitter ?? [];
        $extra = $row?->extra ?? [];

        foreach ($fields as $field => $value) {
            switch ($field) {
                case 'og_title': $og['title'] = $value; break;
                case 'og_description': $og['description'] = $value; break;
                case 'og_image': $og['image'] = $value; break;
                case 'twitter_title': $twitter['title'] = $value; break;
                case 'twitter_description': $twitter['description'] = $value; break;
                case 'twitter_image': $twitter['image'] = $value; break;
                case 'structured_data': $sets['json_ld'] = $value; break;
                case 'faq': $sets['faq'] = $value; break;
                case 'custom_meta': $sets['custom_meta'] = $value; break;
                case 'indexable': $sets['indexable'] = (bool) $value; break;
                case 'canonical_url': $sets['canonical_url'] = $value; break;
                case 'content_markdown': $extra['content_markdown'] = $value; break;
                default:
                    if (in_array($field, ['title', 'description', 'keywords', 'h1'], true)) {
                        $sets[$field] = $value;
                    } else {
                        $extra[$field] = $value;
                    }
            }
        }
        if ($og) $sets['og'] = $og;
        if ($twitter) $sets['twitter'] = $twitter;
        if ($extra) $sets['extra'] = $extra;
        $sets['updated_at'] = now();

        if (!$row) {
            $row = new SeoMeta(['path' => $path]);
        }
        $row->fill($sets);
        $row->save();

        if ($taskId) {
            TaskHistory::create([
                'task_id' => $taskId,
                'task_type' => $taskType,
                'target' => $path,
                'field' => implode(',', array_keys($sets)),
                'before' => $before ?: null,
                'after' => self::snapshot($row),
            ]);
        }

        return [$row, array_keys($fields), $before];
    }

    /** Restore a single history row (seo_rollback by history_id). */
    public static function restoreHistory(int $historyId): bool
    {
        $h = TaskHistory::find($historyId);
        if (!$h || $h->task_type !== 'seo') {
            // history rows written by seo tasks carry task_type of the task;
            // accept any row whose target is a managed path
        }
        if (!$h) {
            return false;
        }
        $path = self::normalizePath((string) $h->target);
        $row = SeoMeta::where('path', $path)->first();
        $before = $h->before ?? [];
        if (!$row) {
            if (!$before) {
                return false;
            }
            $row = new SeoMeta(['path' => $path]);
        } elseif (!$before) {
            // Empty before-snapshot means the row did not exist before this
            // task — rollback removes the created row entirely.
            $row->delete();
            $h->restored = true;
            $h->save();
            return true;
        }
        foreach (['title', 'description', 'keywords', 'h1', 'canonical_url', 'indexable', 'og', 'twitter', 'json_ld', 'faq', 'custom_meta', 'extra'] as $f) {
            if (array_key_exists($f, $before)) {
                $row->{$f} = $before[$f];
            }
        }
        $row->save();
        $h->restored = true;
        $h->save();
        return true;
    }

    /** Restore all history rows for a task id, optionally scoped to a url. */
    public static function restoreByTaskId(int $taskId, string $url = ''): int
    {
        // Newest first: each restore overwrites fields with that change's
        // before-snapshot, so the oldest snapshot must be applied last.
        $q = TaskHistory::where('task_id', $taskId)->where('restored', false)->orderByDesc('id');
        if ($url !== '') {
            $q->where('target', self::normalizePath($url));
        }
        $count = 0;
        foreach ($q->get() as $h) {
            if (self::restoreHistory((int) $h->id)) {
                $count++;
            }
        }
        return $count;
    }

    /** Restore all seo history (optionally one url, optionally clear rows). */
    public static function restoreAll(string $url = '', bool $clearOverride = false): int
    {
        $q = TaskHistory::where('restored', false)->orderByDesc('id');
        if ($url !== '') {
            $q->where('target', self::normalizePath($url));
        }
        $count = 0;
        foreach ($q->get() as $h) {
            if (self::restoreHistory((int) $h->id)) {
                $count++;
            }
        }
        if ($clearOverride) {
            if ($url !== '') {
                SeoMeta::where('path', self::normalizePath($url))->delete();
            } else {
                SeoMeta::query()->delete();
                TaskHistory::query()->delete();
            }
        }
        return $count;
    }
}
