<?php

namespace GeoEngine\Agent;

use GeoEngine\Agent\Models\Page;
use GeoEngine\Agent\Models\Product;
use GeoEngine\Agent\Models\Redirect;
use GeoEngine\Agent\Models\SeoMeta;
use GeoEngine\Agent\Models\Setting;
use GeoEngine\Agent\Models\TaskHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Task executor — dispatches platform CmsTask payloads onto Laravel.
 *
 * Result contract (returned to caller which POSTs /tasks/{id}/result):
 *   ['success' => bool, 'message' => string, 'error' => ?string,
 *    'result_data' => ?array]
 *
 * Unsupported task types fail honestly with a clear message — the
 * platform records them in agent.metadata_.unsupported_task_types and
 * stops delivering them instead of retry-looping.
 */
class TaskExecutor
{
    private AgentClient $client;

    public function __construct(?AgentClient $client = null)
    {
        $this->client = $client ?: new AgentClient();
    }

    public function execute(array $task): array
    {
        $type = (string) ($task['task_type'] ?? '');
        $payload = (array) ($task['payload'] ?? []);
        $taskId = (int) ($task['id'] ?? 0);

        try {
            $result = match ($type) {
                'update_meta', 'update_seo_meta' => $this->updateMeta($payload, $taskId, $type),
                'update_meta_batch' => $this->updateMetaBatch($payload, $taskId),
                'inject_schema', 'add_schema' => $this->injectSchema($payload, $taskId, $type),
                'add_faq' => $this->addFaq($payload, $taskId),
                'fix_seo' => $this->fixSeo($payload, $taskId),
                'apply_preset' => $this->applyPreset($payload, $taskId),
                'apply_command_action' => $this->applyCommandAction($payload, $taskId),
                'upload_file', 'publish_file' => $this->uploadFile($payload),
                'generate_llms' => $this->generateLlms($taskId),
                'fix_robots' => $this->fixRobots($taskId),
                'remove_dead_links' => $this->removeDeadLinks($payload),
                'sync_catalog' => $this->syncCatalog($payload),
                'update_product' => $this->updateProduct($payload, $taskId),
                'update_price' => $this->updatePrice($payload, $taskId),
                'add_product_review', 'remove_product_review' => $this->unsupported($type, 'product reviews need a reviews module — not available in the Laravel agent'),
                'rollback' => $this->rollback($payload),
                'seo_rollback' => $this->seoRollback($payload),
                'self_update' => $this->selfUpdate($payload),
                'self_uninstall' => $this->unsupported($type, 'uninstall is manual: remove the package, run migrations rollback, delete geoengine_* tables'),
                'scan_site_structure' => SiteScanner::runAndSubmit($payload, $this->client),
                'sync_redirects' => $this->syncRedirects($payload),
                'publish_content_plan', 'create_content', 'update_article' => $this->publishContent($payload, $taskId, $type),
                'update_content' => $this->updatePageContent($payload, $taskId),
                'inject_content_block' => $this->injectContentBlock($payload, $taskId),
                'update_category', 'update_information' => $this->unsupported($type, 'Laravel agent has no native taxonomy/information pages — use publish_content_plan'),
                'set_geotargeting' => $this->setGeotargeting($payload),
                'seo_audit' => $this->seoAudit(),
                'scout' => $this->scout(),
                'native_seo_off' => $this->nativeSeoOff($payload),
                'register_campaign_publication' => $this->registerCampaignPublication($payload),
                'test', 'ping' => ['success' => true, 'message' => 'laravel agent ok', 'result_data' => ['version' => \GeoEngine\Agent\Version::VERSION]],
                default => $this->unsupported($type),
            };
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => '',
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ];
        }

        // Normalize: handlers may return bare arrays or full result shape.
        if (!isset($result['success'])) {
            $result = ['success' => true, 'message' => '', 'result_data' => $result];
        }
        $result['result_data'] = $result['result_data'] ?? null;
        return $result;
    }

    private function unsupported(string $type, string $why = ''): array
    {
        return [
            'success' => false,
            'message' => '',
            'error' => 'Unknown task type: ' . ($type ?: '(empty)')
                . ($why ? ' — ' . $why : ' on the Laravel agent'),
        ];
    }

    // ------------------------------------------------------------- SEO/meta

    private function updateMeta(array $payload, int $taskId, string $type): array
    {
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        if ($url === '') {
            return ['success' => false, 'message' => '', 'error' => 'update_meta: missing url'];
        }
        $fields = SeoStore::fieldsFrom((array) ($payload['fields'] ?? $payload));
        // `fields` may itself carry nested seo payload — merge top-level too.
        foreach (SeoStore::fieldsFrom($payload) as $k => $v) {
            $fields[$k] = $v;
        }
        if (!$fields) {
            return ['success' => false, 'message' => '', 'error' => 'update_meta: no writable fields'];
        }
        [$row, $applied, $before] = SeoStore::apply($url, $fields, $taskId, $type);
        return [
            'success' => true,
            'message' => 'meta applied to ' . SeoStore::normalizePath($url),
            'result_data' => ['page' => SeoStore::normalizePath($url), 'applied' => $applied, 'before' => $before],
        ];
    }

    private function updateMetaBatch(array $payload, int $taskId): array
    {
        $items = (array) ($payload['items'] ?? []);
        if (!$items) {
            return ['success' => false, 'message' => '', 'error' => 'update_meta_batch: empty items'];
        }
        $applied = 0;
        $errors = [];
        foreach ($items as $i => $item) {
            $url = (string) ($item['url'] ?? '');
            $fields = SeoStore::fieldsFrom((array) ($item['fields'] ?? []));
            if ($url === '' || !$fields) {
                $errors[] = "item {$i}: missing url/fields";
                continue;
            }
            SeoStore::apply($url, $fields, $taskId, 'update_meta_batch');
            $applied++;
        }
        return [
            'success' => $applied > 0,
            'message' => "meta batch: {$applied}/" . count($items) . ' applied',
            'error' => $errors ? implode('; ', array_slice($errors, 0, 5)) : null,
            'result_data' => ['applied' => $applied, 'total' => count($items)],
        ];
    }

    private function injectContentBlock(array $payload, int $taskId): array
    {
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        $html = trim((string) ($payload['html'] ?? $payload['block'] ?? ''));
        if ($url === '' || $html === '') {
            return ['success' => false, 'message' => '', 'error' => 'inject_content_block: missing url/html'];
        }
        $position = (string) ($payload['position'] ?? 'after_h1');
        if (!in_array($position, ['after_h1', 'before_body', 'prepend_body'], true)) {
            $position = 'after_h1';
        }
        [$row, $applied] = SeoStore::apply($url, [
            'content_block' => $html,
            'content_block_position' => $position,
        ], $taskId, 'inject_content_block');
        return [
            'success' => true,
            'message' => 'content block stored for ' . SeoStore::normalizePath($url) . " ({$position})",
            'result_data' => ['page' => SeoStore::normalizePath($url), 'position' => $position],
        ];
    }

    private function injectSchema(array $payload, int $taskId, string $type): array
    {
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        if ($url === '') {
            return ['success' => false, 'message' => '', 'error' => 'inject_schema: missing url'];
        }
        $schema = $payload['json_ld'] ?? $payload['jsonLd'] ?? $payload['structured_data']
            ?? $payload['structuredData'] ?? $payload['schema'] ?? null;
        if ($schema === null) {
            return ['success' => false, 'message' => '', 'error' => 'inject_schema: missing schema payload'];
        }
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : ['@context' => 'https://schema.org', 'raw' => $schema];
        }
        [$row] = SeoStore::apply($url, ['structured_data' => $schema], $taskId, $type);
        return ['success' => true, 'message' => 'schema applied', 'result_data' => ['page' => SeoStore::normalizePath($url)]];
    }

    private function addFaq(array $payload, int $taskId): array
    {
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        $items = $payload['faq_items'] ?? $payload['faq'] ?? $payload['items'] ?? null;
        if ($url === '' || !is_array($items)) {
            return ['success' => false, 'message' => '', 'error' => 'add_faq: missing url or faq_items'];
        }
        SeoStore::apply($url, ['faq' => $items], $taskId, 'add_faq');
        return ['success' => true, 'message' => 'faq applied', 'result_data' => ['page' => SeoStore::normalizePath($url), 'count' => count($items)]];
    }

    private function fixSeo(array $payload, int $taskId): array
    {
        // fix_seo payload: {page|url, fix_type|issue_type, fix_content|value, ...}
        $url = (string) ($payload['url'] ?? $payload['page'] ?? $payload['page_id'] ?? '');
        if ($url === '') {
            return ['success' => false, 'message' => '', 'error' => 'fix_seo: missing url/page'];
        }
        $fixType = strtolower((string) ($payload['fix_type'] ?? $payload['issue_type'] ?? $payload['type'] ?? ''));
        $content = $payload['fix_content'] ?? $payload['value'] ?? $payload['content'] ?? null;

        $fieldMap = [
            'title' => 'title',
            'meta_title' => 'title',
            'missing_title' => 'title',
            'short_title' => 'title',
            'long_title' => 'title',
            'duplicate_title' => 'title',
            'description' => 'description',
            'meta_description' => 'description',
            'missing_description' => 'description',
            'missing_meta_description' => 'description',
            'short_description' => 'description',
            'long_description' => 'description',
            'duplicate_description' => 'description',
            'keywords' => 'keywords',
            'canonical' => 'canonical_url',
            'missing_canonical' => 'canonical_url',
            'h1' => 'h1',
            'missing_h1' => 'h1',
            'og_title' => 'og_title',
            'og_description' => 'og_description',
            'og_image' => 'og_image',
            'schema' => 'structured_data',
            'structured_data' => 'structured_data',
            'missing_schema' => 'structured_data',
            'missing_structured_data' => 'structured_data',
            'schema_markup' => 'structured_data',
            'add_schema' => 'structured_data',
            'index' => 'indexable',
            'noindex' => 'indexable',
        ];
        $field = $fieldMap[$fixType] ?? null;
        if ($field === null && $fixType === '' && $content !== null) {
            $field = 'description';
        }
        if ($field === null) {
            return ['success' => false, 'message' => '', 'error' => "fix_seo: unknown fix_type '{$fixType}'"];
        }
        if ($content === null) {
            return ['success' => false, 'message' => '', 'error' => 'fix_seo: missing fix_content'];
        }
        if (is_string($content)) {
            $trim = trim($content);
            if ($field === 'title' && preg_match('#<title[^>]*>(.*?)</title>#is', $trim, $m)) {
                $content = trim($m[1]);
            } elseif ($field === 'description' && stripos($trim, '<meta') !== false && preg_match('#content=["\'](.*?)["\']#is', $trim, $m)) {
                $content = trim($m[1]);
            } elseif ($field === 'canonical_url' && stripos($trim, '<link') !== false && preg_match('#href=["\']([^"\']+)["\']#i', $trim, $m)) {
                $content = trim($m[1]);
            } elseif ($field === 'h1' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $trim, $m)) {
                $content = trim($m[1]);
            } elseif ($field === 'structured_data') {
                if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $trim, $ms)) {
                    $parsed = [];
                    foreach ($ms[1] as $s) {
                        $d = json_decode(trim($s), true);
                        if (is_array($d)) {
                            $parsed[] = $d;
                        }
                    }
                    $content = count($parsed) === 1 ? $parsed[0] : ($parsed ?: $trim);
                } else {
                    $d = json_decode($trim, true);
                    if (is_array($d)) {
                        $content = $d;
                    }
                }
            }
        }
        if ($field === 'indexable') {
            $content = !in_array(strtolower((string) $content), ['noindex', 'false', '0'], true);
        }
        SeoStore::apply($url, [$field => $content], $taskId, 'fix_seo');
        return ['success' => true, 'message' => "fix_seo applied ({$field})", 'result_data' => ['page' => SeoStore::normalizePath($url), 'field' => $field]];
    }

    private function applyPreset(array $payload, int $taskId): array
    {
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        $actions = (array) ($payload['actions'] ?? ['llms', 'faq', 'schema']);
        $preset = (array) ($payload['preset'] ?? []);
        $done = [];
        $failed = [];

        foreach ($actions as $action) {
            try {
                switch ($action) {
                    case 'llms':
                        $this->generateLlms($taskId);
                        $done[] = 'llms';
                        break;
                    case 'faq':
                        $faq = $preset['faq_items'] ?? $preset['faq'] ?? null;
                        if ($url !== '' && is_array($faq)) {
                            SeoStore::apply($url, ['faq' => $faq], $taskId, 'apply_preset');
                            $done[] = 'faq';
                        } else {
                            $failed[] = 'faq (no items)';
                        }
                        break;
                    case 'schema':
                    case 'add_schema':
                        $schema = $preset['schema'] ?? $preset['json_ld'] ?? $preset['structured_data'] ?? null;
                        if ($url !== '' && $schema !== null) {
                            $this->injectSchema(['url' => $url, 'json_ld' => $schema], $taskId, 'apply_preset');
                            $done[] = 'schema';
                        } else {
                            $failed[] = 'schema (no data)';
                        }
                        break;
                    case 'meta':
                    case 'seo':
                        if ($url !== '') {
                            $fields = SeoStore::fieldsFrom($preset);
                            if ($fields) {
                                SeoStore::apply($url, $fields, $taskId, 'apply_preset');
                                $done[] = 'meta';
                            } else {
                                $failed[] = 'meta (no fields)';
                            }
                        } else {
                            $failed[] = 'meta (no url)';
                        }
                        break;
                    default:
                        $failed[] = $action;
                }
            } catch (\Throwable $e) {
                $failed[] = $action . ': ' . $e->getMessage();
            }
        }

        return [
            'success' => count($done) > 0,
            'message' => 'preset: ' . implode(',', $done) . ($failed ? ' | skipped: ' . implode(',', $failed) : ''),
            'result_data' => ['applied' => $done, 'skipped' => $failed],
        ];
    }

    private function applyCommandAction(array $payload, int $taskId): array
    {
        // Command-center actions carry {action_type, target_page, action_data}.
        // Map the ones that are pure managed-meta writes; refuse the rest.
        $actionType = (string) ($payload['action_type'] ?? '');
        $target = (string) ($payload['target_page'] ?? $payload['url'] ?? '');
        $data = (array) ($payload['action_data'] ?? []);

        $map = [
            'add_meta_description' => ['description' => $data['description'] ?? $data['value'] ?? null],
            'add_title' => ['title' => $data['title'] ?? $data['value'] ?? null],
            'add_canonical' => ['canonical_url' => $data['url'] ?? $data['value'] ?? null],
            'add_schema' => ['structured_data' => $data['schema'] ?? $data['json_ld'] ?? null],
            'add_faq' => ['faq' => $data['items'] ?? $data['faq_items'] ?? null],
        ];
        if (!isset($map[$actionType])) {
            return $this->unsupported('apply_command_action:' . $actionType, 'action type not mapped on Laravel agent');
        }
        if ($target === '') {
            return ['success' => false, 'message' => '', 'error' => 'apply_command_action: missing target_page'];
        }
        $fields = array_filter($map[$actionType], fn ($v) => $v !== null);
        if (!$fields) {
            return ['success' => false, 'message' => '', 'error' => 'apply_command_action: empty action_data'];
        }
        SeoStore::apply($target, $fields, $taskId, 'apply_command_action');
        return ['success' => true, 'message' => 'command action applied', 'result_data' => ['page' => SeoStore::normalizePath($target), 'action' => $actionType]];
    }

    // --------------------------------------------------------------- files

    private function uploadFile(array $payload): array
    {
        $filename = (string) ($payload['filename'] ?? $payload['path'] ?? $payload['file_path'] ?? '');
        $content = $payload['content'] ?? $payload['data'] ?? '';
        $encoding = (string) ($payload['encoding'] ?? 'utf-8');
        if ($filename === '' || $content === '') {
            return ['success' => false, 'message' => '', 'error' => 'upload_file: missing filename/content'];
        }
        try {
            $target = FilePublisher::publish($filename, (string) $content, $encoding);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => '', 'error' => $e->getMessage()];
        }
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/');
        return [
            'success' => true,
            'message' => 'published ' . $filename,
            'result_data' => ['filename' => $filename, 'path' => $target, 'url' => $siteUrl . '/' . ltrim($filename, '/')],
        ];
    }

    private function generateLlms(int $taskId): array
    {
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/') ?: url('/');
        $lines = ["# {$siteUrl}", ''];
        $rows = SeoMeta::query()->orderBy('path')->get();
        foreach ($rows as $row) {
            $title = $row->title ?: $row->path;
            $desc = $row->description ?: '';
            $lines[] = '- [' . $title . '](' . $siteUrl . $row->path . ')' . ($desc ? ': ' . $desc : '');
        }
        // Managed pages without seo rows still deserve listing.
        foreach (Page::query()->whereNotNull('published_at')->get() as $pg) {
            $path = '/blog/' . ltrim((string) $pg->slug, '/');
            if (!SeoMeta::where('path', $path)->exists()) {
                $lines[] = '- [' . ($pg->title ?: $pg->slug) . '](' . $siteUrl . $path . ')';
            }
        }
        $content = implode("\n", $lines) . "\n";
        FilePublisher::publish('llms.txt', $content);
        return ['success' => true, 'message' => 'llms.txt generated (' . count($lines) . ' lines)', 'result_data' => ['entries' => count($lines) - 2]];
    }

    private function fixRobots(int $taskId): array
    {
        // Write a managed robots.txt only when absent or already
        // geoengine-managed — never clobber a hand-written customer file.
        $path = FilePublisher::publicPath() . '/robots.txt';
        $marker = '# GEO Engine managed robots';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        if ($existing !== '' && !str_contains($existing, $marker)) {
            return [
                'success' => false,
                'message' => '',
                'error' => 'robots.txt exists and is not GEO Engine managed — leaving untouched',
            ];
        }
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/') ?: url('/');
        $content = $marker . "\nUser-agent: *\nAllow: /\n\nSitemap: {$siteUrl}/sitemap.xml\n";
        FilePublisher::publish('robots.txt', $content);
        return ['success' => true, 'message' => 'robots.txt written', 'result_data' => ['path' => $path]];
    }

    private function removeDeadLinks(array $payload): array
    {
        $paths = array_values(array_filter(array_map('strval', (array) ($payload['paths'] ?? []))));
        $deadPath = trim((string) ($payload['dead_path'] ?? ''));
        if ($deadPath !== '') {
            $paths[] = $deadPath;
        }
        if (!$paths) {
            return ['success' => false, 'message' => '', 'error' => 'remove_dead_links: no paths'];
        }
        $removed = 0;
        foreach ($paths as $p) {
            $removed += SeoMeta::where('path', SeoStore::normalizePath($p))->delete();
        }
        return ['success' => true, 'message' => "removed {$removed} managed meta rows for dead paths", 'result_data' => ['paths' => count($paths), 'removed' => $removed]];
    }

    // -------------------------------------------------------------- catalog

    private function syncCatalog(array $payload): array
    {
        $offset = max(0, (int) ($payload['offset'] ?? 0));
        $limit = min(500, max(1, (int) ($payload['limit'] ?? 200)));
        $since = $payload['modified_since'] ?? null;

        // Optional Eloquent source model takes precedence when configured.
        $model = (string) config('geoengine.catalog_model', '');
        if ($model !== '' && class_exists($model)) {
            return $this->syncCatalogFromModel($model, $offset, $limit, $since);
        }

        // Default: managed product mirror (site pushes via POST /products).
        $q = Product::query()->orderBy('external_id');
        if ($since) {
            $q->where('modified_at', '>=', $since);
        }
        $total = (clone $q)->count();
        $rows = $q->offset($offset)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit);
        }
        $items = [];
        foreach ($rows as $p) {
            $items[] = ['external_id' => $p->external_id, 'kind' => $p->kind] + ($p->fields ?? []);
        }
        return [
            'success' => true,
            'message' => 'catalog page',
            'result_data' => [
                'items' => $items,
                'total' => $total,
                'has_more' => $hasMore,
                'next_offset' => $offset + count($items),
            ],
        ];
    }

    private function syncCatalogFromModel(string $model, int $offset, int $limit, $since): array
    {
        $map = (array) config('geoengine.catalog_map', []);
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/');
        $urlPrefix = rtrim((string) config('geoengine.catalog_url_prefix', '/product/'), '/') . '/';

        // Resolve mapped columns against the model's real schema — a map
        // pointing at a non-existent column silently yields null for every
        // row (observed: 5200 items synced with no name and fabricated
        // /products/{id} URLs that all 404 on the site). The schema builder
        // MUST come from the model's own connection — catalog tables can
        // live on a non-default DB (Schema:: facade checks the default
        // connection and sees nothing there).
        try {
            $instance = new $model;
            $table = $instance->getTable();
            $schema = $instance->getConnection()->getSchemaBuilder();
            $columns = $schema->getColumnListing($table);
        } catch (\Throwable) {
            $columns = [];
        }
        $resolve = function (string $key, array $candidates) use ($map, $columns) {
            $col = $map[$key] ?? null;
            if ($col && (!$columns || in_array($col, $columns, true))) {
                return $col;
            }
            foreach ($candidates as $c) {
                if (in_array($c, $columns, true)) {
                    return $c;
                }
            }
            return $col; // keep configured value — let the query decide
        };
        $nameCol = $resolve('name', ['name', 'title', 'product_name', 'fullname', 'naimenovanie', 'nom', 'caption']);
        $urlCol = $resolve('url', ['url', 'permalink', 'link', 'seo_url', 'page_url', 'href', 'uri']);
        $slugCol = $resolve('slug', ['slug', 'code', 'simcode', 'alias', 'sef', 'sef_code', 'lat_name']);

        $q = $model::query()->orderBy($map['external_id'] ?? 'id');
        // modified_since filter: use the mapped column or fall back to
        // updated_at when the model's table actually has it. Checked on
        // the model's own connection (see above) — the Schema facade hit
        // the default DB and never found the column on remote catalogs.
        $sinceCol = $map['modified_at'] ?? 'updated_at';
        $sinceApplied = false;
        try {
            if ($since && isset($schema) && $schema->hasColumn($table, $sinceCol)) {
                $q->where($sinceCol, '>=', $since);
                $sinceApplied = true;
            }
        } catch (\Throwable) {
        }
        $total = (clone $q)->count();
        $rows = $q->offset($offset)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit);
        }
        $items = [];
        foreach ($rows as $row) {
            $get = fn (string $key) => $map[$key] ?? null ? ($row->{$map[$key]} ?? null) : null;
            $item = ['external_id' => (string) ($get('external_id') ?? $row->getKey()), 'kind' => 'product'];
            foreach (['sku', 'short_description', 'description', 'price', 'image', 'images', 'category', 'attributes'] as $f) {
                $v = $get($f);
                if ($v !== null) {
                    $item[$f] = $v;
                }
            }
            $v = $nameCol ? ($row->{$nameCol} ?? null) : null;
            if ($v !== null && $v !== '') {
                $item['name'] = $v;
            }
            $v = $urlCol ? ($row->{$urlCol} ?? null) : null;
            if ($v !== null && $v !== '') {
                $item['url'] = (string) $v;
            }
            // URL fallback: real slug + configured prefix — never the
            // hardcoded /products/{id} guess that produced dead links.
            if (empty($item['url'])) {
                $slug = $slugCol ? trim((string) ($row->{$slugCol} ?? '')) : '';
                if ($slug !== '') {
                    $path = str_contains($slug, '/') ? '/' . ltrim($slug, '/') : $urlPrefix . $slug;
                    $item['url'] = $siteUrl ? $siteUrl . $path : $path;
                } elseif ($siteUrl) {
                    $item['url'] = $siteUrl . $urlPrefix . $item['external_id'];
                }
            }
            $items[] = $item;
        }
        // Mapping diagnostics: when every item on the page resolves to
        // external_id + fallback URL only, the configured model/map does not
        // expose product fields — report the real schema so the integration
        // owner can fix GEO_ENGINE_CATALOG_MODEL / catalog_map.
        $nameless = 0;
        foreach ($items as $it) {
            if (empty($it['name'])) {
                $nameless++;
            }
        }
        $diag = [
            'table' => $table ?? null,
            'connection' => isset($instance) ? $instance->getConnectionName() : null,
            'columns' => $columns ?: null,
            'name_col' => $nameCol,
            'url_col' => $urlCol,
            'slug_col' => $slugCol,
            'since_col' => $sinceCol,
            'since_applied' => $sinceApplied,
            'nameless_items' => $nameless,
        ];
        return [
            'success' => true,
            'message' => $nameless === count($items) && $items
                ? 'catalog page (model): all items nameless — catalog_map does not match table columns'
                : 'catalog page (model)',
            'result_data' => [
                'items' => $items,
                'total' => $total,
                'has_more' => $hasMore,
                'next_offset' => $offset + count($items),
                'mapping' => $diag,
            ],
        ];
    }

    private function updateProduct(array $payload, int $taskId): array
    {
        $rawId = (string) ($payload['product_id'] ?? $payload['external_id'] ?? $payload['id'] ?? '');
        if ($rawId === '') {
            return ['success' => false, 'message' => '', 'error' => 'update_product: missing product_id'];
        }
        $fields = (array) ($payload['fields'] ?? $payload);
        $allowName = !empty($fields['_allow_name']) || !empty($payload['_allow_name']);
        $restoreName = !empty($fields['_restore_name']) || !empty($payload['_restore_name']);
        unset($fields['product_id'], $fields['external_id'], $fields['id'], $fields['url'], $fields['_allow_name'], $fields['_restore_name']);

        $existing = Product::where('external_id', $rawId)->first();
        $before = $existing ? ($existing->fields ?? []) : [];
        $skippedFields = [];
        if (array_key_exists('name', $fields) && !empty($before['name']) && !($allowName || $restoreName)) {
            unset($fields['name']);
            $skippedFields[] = 'name:protected';
        }

        // Photo/video URLs: fetch once into public/uploads/geoengine-media
        // because platform signed URLs expire.
        $mediaWarnings = [];
        foreach (['photo_url' => 'png', 'video_url' => 'mp4'] as $key => $ext) {
            $url = (string) ($fields[$key] ?? '');
            if ($url === '') {
                continue;
            }
            unset($fields[$key]);
            $local = $this->fetchMedia($rawId, $key, $url, $ext);
            if ($local) {
                $fields['media_' . $key] = $local;
            } else {
                $mediaWarnings[] = $key . ': download failed';
                $skippedFields[] = $key . ': download failed';
            }
        }

        $snapshot = [];
        foreach (['name', 'description', 'short_description', 'meta_title', 'meta_description', 'attributes', 'price'] as $k) {
            if (array_key_exists($k, $before)) {
                $snapshot[$k] = $before[$k];
            }
        }

        $merged = $before;
        foreach ($fields as $k => $v) {
            $merged[$k] = $v;
        }

        Product::updateOrCreate(
            ['external_id' => $rawId],
            ['kind' => $existing->kind ?? 'product', 'fields' => $merged, 'modified_at' => now()]
        );

        // Mirror generated product meta onto the page-level SeoMeta row:
        // the catalog pipeline has the richest product context (sku, price,
        // attributes) so its meta_title/meta_description should own the
        // live page meta — not a second generic pass over the same URL.
        if (isset($fields['meta_title']) || isset($fields['meta_description'])) {
            $path = '';
            $u = (string) ($merged['url'] ?? '');
            if ($u !== '') {
                $path = SeoStore::normalizePath((string) (parse_url($u, PHP_URL_PATH) ?: ''));
            }
            if ($path !== '') {
                try {
                    $meta = SeoMeta::firstOrNew(['path' => $path]);
                    if (isset($fields['meta_title'])) {
                        $meta->title = (string) $fields['meta_title'];
                    }
                    if (isset($fields['meta_description'])) {
                        $meta->description = (string) $fields['meta_description'];
                    }
                    if ($meta->indexable === null) {
                        $meta->indexable = true;
                    }
                    $meta->save();
                } catch (\Throwable) {
                }
            }
        }

        TaskHistory::create([
            'task_id' => $taskId,
            'task_type' => 'update_product',
            'target' => $rawId,
            'field' => implode(',', array_keys($fields)),
            'before' => $snapshot ?: null,
            'after' => array_intersect_key($merged, array_flip(array_keys($fields))),
        ]);

        $resultData = ['before' => $snapshot, 'product' => $merged];
        if ($mediaWarnings) {
            $resultData['warnings'] = $mediaWarnings;
        }
        if ($skippedFields) {
            $resultData['skipped_fields'] = $skippedFields;
        }
        return ['success' => true, 'message' => 'product updated', 'result_data' => $resultData];
    }

    private function updatePrice(array $payload, int $taskId): array
    {
        $rawId = (string) ($payload['product_id'] ?? $payload['external_id'] ?? '');
        $newPrice = $payload['new_price'] ?? null;
        if ($rawId === '' || $newPrice === null) {
            return ['success' => false, 'message' => '', 'error' => 'update_price: missing product_id/new_price'];
        }
        $product = Product::where('external_id', $rawId)->first();
        if (!$product) {
            return ['success' => false, 'message' => '', 'error' => "update_price: unknown product {$rawId}"];
        }
        $fields = $product->fields ?? [];
        $before = $fields['price'] ?? null;
        $fields['price'] = is_numeric($newPrice) ? (float) $newPrice : $newPrice;
        $fields['currency'] = $fields['currency'] ?? ($payload['currency'] ?? null);
        $product->fields = $fields;
        $product->modified_at = now();
        $product->save();
        TaskHistory::create([
            'task_id' => $taskId, 'task_type' => 'update_price', 'target' => $rawId,
            'field' => 'price', 'before' => ['price' => $before], 'after' => ['price' => $fields['price']],
        ]);
        return ['success' => true, 'message' => 'price updated', 'result_data' => ['before' => $before, 'after' => $fields['price']]];
    }

    private function fetchMedia(string $productId, string $key, string $url, string $ext): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        try {
            $dir = FilePublisher::publicPath() . '/uploads/geoengine-media';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $productId) . '-' . $key[0] . '-' . time() . '.' . $ext;
            $target = $dir . '/' . $name;
            $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'geoengine-laravel-agent'], 'ssl' => ['verify_peer' => true]]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data === false || strlen($data) > 50 * 1024 * 1024) {
                return null;
            }
            file_put_contents($target, $data);
            return 'uploads/geoengine-media/' . $name;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------- rollback

    private function rollback(array $payload): array
    {
        $rawId = (string) ($payload['product_id'] ?? '');
        $original = (array) ($payload['original'] ?? []);
        if ($rawId === '' || !$original) {
            return ['success' => false, 'message' => '', 'error' => 'rollback: missing product_id or original snapshot'];
        }
        $product = Product::where('external_id', $rawId)->first();
        if (!$product) {
            return ['success' => false, 'message' => '', 'error' => "rollback: unknown product {$rawId}"];
        }
        $fields = $product->fields ?? [];
        foreach ($original as $k => $v) {
            $fields[$k] = $v;
        }
        $product->fields = $fields;
        $product->save();
        return ['success' => true, 'message' => 'product rolled back', 'result_data' => ['restored' => array_keys($original)]];
    }

    private function seoRollback(array $payload): array
    {
        $historyId = (int) ($payload['history_id'] ?? 0);
        $targetTaskId = (int) ($payload['target_task_id'] ?? ($payload['task_id'] ?? 0));
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        $all = !empty($payload['all']);
        $clearOverride = !empty($payload['clear_override']);

        if ($historyId) {
            return SeoStore::restoreHistory($historyId)
                ? ['success' => true, 'message' => 'history restored', 'result_data' => ['restored' => 1]]
                : ['success' => false, 'message' => '', 'error' => "History #{$historyId} not found"];
        }
        if ($targetTaskId) {
            $restored = SeoStore::restoreByTaskId($targetTaskId, $url);
            return $restored
                ? ['success' => true, 'message' => "restored {$restored} rows", 'result_data' => ['restored' => $restored]]
                : ['success' => false, 'message' => '', 'error' => "No history rows for task #{$targetTaskId}"];
        }
        if ($url === '' && !$all) {
            return ['success' => false, 'message' => '', 'error' => 'Missing url, history_id or all=true'];
        }
        $restored = SeoStore::restoreAll($url, $clearOverride);
        return ['success' => true, 'message' => "restored {$restored} rows", 'result_data' => ['restored' => $restored, 'cleared' => $clearOverride]];
    }

    // ------------------------------------------------------------ self-update

    private function selfUpdate(array $payload): array
    {
        $res = SelfUpdater::apply($payload, $this->client);
        if (!($res['ok'] ?? false)) {
            return ['success' => false, 'message' => '', 'error' => $res['error'] ?? 'self_update failed'];
        }
        return [
            'success' => true,
            'message' => 'self-update applied → ' . ($res['version'] ?? '?'),
            'result_data' => $res,
        ];
    }

    // ------------------------------------------------------------ redirects

    private function syncRedirects(array $payload): array
    {
        $redirects = (array) ($payload['redirects'] ?? []);
        $applied = 0;
        DB::transaction(function () use ($redirects, &$applied) {
            Redirect::query()->delete();
            foreach ($redirects as $r) {
                $from = SeoStore::normalizePath((string) ($r['from'] ?? $r['from_path'] ?? $r['source'] ?? ''));
                $to = (string) ($r['to'] ?? $r['to_path'] ?? $r['target'] ?? '');
                $code = (int) ($r['code'] ?? $r['status'] ?? 301);
                if ($from === '/' || $to === '' || $from === $to) {
                    continue;
                }
                if (!in_array($code, [301, 302, 307, 308], true)) {
                    $code = 301;
                }
                Redirect::updateOrCreate(
                    ['from_path' => $from],
                    ['to_path' => $to, 'code' => $code]
                );
                $applied++;
            }
        });
        return ['success' => true, 'message' => "redirects synced: {$applied}", 'result_data' => ['count' => $applied]];
    }

    // --------------------------------------------------------------- content

    private function publishContent(array $payload, int $taskId, string $type): array
    {
        $title = (string) ($payload['title'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $slug = trim((string) ($payload['slug'] ?? $payload['alias'] ?? ''));

        // Prefer the site's own posts model when configured.
        $model = (string) config('geoengine.posts_model', '');
        if ($model !== '' && class_exists($model)) {
            return $this->publishToModel($model, $payload, $title, $content, $slug);
        }

        if ($slug === '') {
            $slug = Str::slug($title !== '' ? $title : $content);
        }
        $slug = trim($slug, '/');
        if ($slug === '') {
            return ['success' => false, 'message' => '', 'error' => 'publish_content: cannot derive slug'];
        }

        $meta = SeoStore::fieldsFrom($payload);
        $page = Page::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $title ?: $slug,
                'content' => $content,
                'excerpt' => (string) ($payload['excerpt'] ?? ''),
                'meta' => $meta ?: null,
                'published_at' => now(),
            ]
        );

        // Best-effort: tell the platform the plan item is published.
        $projectId = (int) ($payload['project_id'] ?? 0);
        $planId = (int) ($payload['plan_id'] ?? 0);
        $articleUrl = rtrim((string) config('geoengine.site_url', ''), '/') . '/blog/' . $slug;
        if ($projectId && $planId) {
            $this->client->apiPost("/content/{$projectId}/plans/{$planId}", [
                'status' => 'published',
                'article_url' => $articleUrl,
            ]);
        }

        return [
            'success' => true,
            'message' => 'content published: /blog/' . $slug,
            'result_data' => ['slug' => $slug, 'url' => $articleUrl, 'page_id' => $page->id],
        ];
    }

    /**
     * update_content targets an EXISTING page by url — it must never
     * create a record. The shared publish path slugifies the content into
     * a new page, which turned advisory text ("Expand the page to at
     * least 300 words…") into literal junk pages on client sites.
     */
    private function updatePageContent(array $payload, int $taskId): array
    {
        $url = (string) ($payload['url'] ?? $payload['page'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $title = (string) ($payload['title'] ?? '');
        if ($url === '' || $content === '') {
            return ['success' => false, 'message' => '', 'error' => 'update_content: missing url/content'];
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return ['success' => false, 'message' => '', 'error' => 'update_content: cannot derive slug from url'];
        }

        $model = (string) config('geoengine.posts_model', '');
        if ($model !== '' && class_exists($model)) {
            $map = (array) config('geoengine.posts_map', []);
            $col = fn (string $key) => $map[$key] ?? $key;
            $rec = $model::where($col('slug'), $path)->first()
                ?: $model::where($col('slug'), basename($path))->first();
            if (!$rec) {
                return ['success' => false, 'message' => '', 'error' => "update_content: page not found for /{$path}"];
            }
            $data = [$col('content') => $content];
            if ($title !== '') {
                $data[$col('title')] = $title;
            }
            $rec->update($data);
            return [
                'success' => true,
                'message' => "content updated: /{$path}",
                'result_data' => ['slug' => $path],
            ];
        }

        $page = Page::where('slug', $path)->first()
            ?: Page::where('slug', basename($path))->first();
        if (!$page) {
            return ['success' => false, 'message' => '', 'error' => "update_content: page not found for /{$path}"];
        }
        $data = ['content' => $content];
        if ($title !== '') {
            $data['title'] = $title;
        }
        $page->update($data);

        return [
            'success' => true,
            'message' => "content updated: /{$page->slug}",
            'result_data' => ['slug' => $page->slug, 'page_id' => $page->id],
        ];
    }

    private function publishToModel(string $model, array $payload, string $title, string $content, string $slug): array
    {
        $map = (array) config('geoengine.posts_map', []);
        $col = fn (string $key) => $map[$key] ?? $key;

        $data = [];
        if ($slug !== '' || $title !== '') {
            $data[$col('slug')] = $slug !== '' ? $slug : Str::slug($title);
        }
        if ($title !== '') {
            $data[$col('title')] = $title;
        }
        if ($content !== '') {
            $data[$col('content')] = $content;
        }
        if (!empty($payload['excerpt'])) {
            $data[$col('excerpt')] = (string) $payload['excerpt'];
        }
        if (isset($map['published_at'])) {
            $data[$col('published_at')] = now();
        }
        // SEO meta into model fields when the mapping knows them.
        foreach (['meta_title' => 'title', 'meta_description' => 'description'] as $payloadKey => $modelKey) {
            if (!empty($payload[$payloadKey]) && isset($map[$modelKey])) {
                $data[$map[$modelKey]] = $payload[$payloadKey];
            }
        }

        $existing = null;
        $slugCol = $col('slug');
        if ($slug !== '' && isset($data[$slugCol])) {
            $existing = $model::where($slugCol, $data[$slugCol])->first();
        }
        if ($existing) {
            $existing->fill($data)->save();
        } else {
            $existing = $model::create($data);
        }

        return [
            'success' => true,
            'message' => 'content published via ' . class_basename($model),
            'result_data' => ['id' => $existing->getKey(), 'slug' => $data[$slugCol] ?? $slug],
        ];
    }

    // --------------------------------------------------------- misc/platform

    private function setGeotargeting(array $payload): array
    {
        Setting::put('geotargeting', [
            'country' => $payload['country'] ?? '',
            'city' => $payload['city'] ?? '',
            'region' => $payload['region'] ?? '',
        ]);
        return ['success' => true, 'message' => 'geotargeting stored', 'result_data' => $payload];
    }

    private function seoAudit(): array
    {
        return [
            'success' => true,
            'message' => 'site signals',
            'result_data' => [
                'managed_pages' => SeoMeta::count(),
                'products' => Product::count(),
                'redirects' => Redirect::count(),
                'content_pages' => Page::count(),
                'agent_version' => \GeoEngine\Agent\Version::VERSION,
            ],
        ];
    }

    private function scout(): array
    {
        return [
            'success' => true,
            'message' => 'recon',
            'result_data' => [
                'bridge' => 'geo-engine',
                'version' => \GeoEngine\Agent\Version::VERSION,
                'platform' => 'laravel',
                'framework' => 'laravel ' . app()->version() . ' / php ' . PHP_VERSION,
                'capabilities' => [
                    'pages.meta', 'pages.schema', 'files.publish', 'llms.txt',
                    'catalog.sync', 'catalog.update', 'redirects.sync',
                    'content.publish', 'rollback', 'self_update', 'site.scan',
                ],
            ],
        ];
    }

    private function nativeSeoOff(array $payload): array
    {
        $enable = array_key_exists('enable', $payload) ? (bool) $payload['enable'] : true;
        Setting::put('native_seo_off', !$enable);
        return ['success' => true, 'message' => 'native_seo_off=' . var_export(!$enable, true), 'result_data' => ['native_seo_off' => !$enable]];
    }

    private function registerCampaignPublication(array $payload): array
    {
        $projectId = (int) ($payload['project_id'] ?? 0);
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $url = (string) ($payload['url'] ?? '');
        if (!$projectId || !$campaignId || $url === '') {
            return ['success' => false, 'message' => '', 'error' => 'register_campaign_publication: missing ids/url'];
        }
        $ok = $this->client->apiPost("/command-center/{$projectId}/campaigns/{$campaignId}/publications", [
            'url' => $url,
            'title' => $payload['title'] ?? '',
            'is_self_hosted' => (bool) ($payload['is_self_hosted'] ?? true),
        ]);
        return $ok !== null
            ? ['success' => true, 'message' => 'publication registered', 'result_data' => $ok]
            : ['success' => false, 'message' => '', 'error' => 'platform rejected publication registration'];
    }
}
