<?php

namespace GeoEngine\Agent\Http\Controllers;

use GeoEngine\Agent\AgentClient;
use GeoEngine\Agent\FilePublisher;
use GeoEngine\Agent\Models\Page;
use GeoEngine\Agent\Models\Product;
use GeoEngine\Agent\Models\SeoMeta;
use GeoEngine\Agent\Models\Setting;
use GeoEngine\Agent\SeoStore;
use GeoEngine\Agent\SelfUpdater;
use GeoEngine\Agent\TaskExecutor;
use GeoEngine\Agent\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Bridge endpoints — the HTTP contract GEO Engine's direct_api connector
 * and bridge-aware front-ends consume. Mirrors the FastAPI bridge shape.
 */
class BridgeController extends Controller
{
    // ------------------------------------------------------------- public

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'geo-engine-agent',
            'framework' => 'laravel',
            'version' => Version::VERSION,
            'platform' => 'laravel',
            'laravel' => app()->version(),
            'registered' => AgentClient::agentId() !== null,
            'module_dir_writable' => is_writable(SelfUpdater::packageRoot()),
        ]);
    }

    public function recon(): JsonResponse
    {
        return response()->json([
            'bridge' => 'geo-engine',
            'version' => Version::VERSION,
            'platform' => 'laravel',
            'framework' => 'laravel ' . app()->version(),
            'auth' => ['type' => 'header', 'key' => 'x-api-key'],
            'capabilities' => [
                'pages.list', 'pages.read', 'pages.upsert', 'seo.apply',
                'fix.apply', 'ai_export', 'file.upload', 'file.publish',
                'products.list', 'products.read', 'products.upsert',
                'posts.create', 'self_update',
                'agent.tasks', 'agent.webhook',
            ],
            'endpoints' => [
                'health' => '/api/geo-engine/health',
                'recon' => '/api/geo-engine/recon',
                'pages' => '/api/geo-engine-bridge/pages',
                'page_meta' => '/api/geo-engine/page-meta',
                'structured_data' => '/api/geo-engine/structured-data',
                'llms_txt' => '/api/geo-engine/llms-txt',
                'webhook' => '/api/geo-engine/webhook',
            ],
        ]);
    }

    public function pageMeta(Request $request): JsonResponse
    {
        $path = SeoStore::normalizePath((string) $request->query('page', '/'));
        $row = SeoMeta::where('path', $path)->first();
        return response()->json([
            'page' => $path,
            'title' => $row->title ?? null,
            'description' => $row->description ?? null,
            'keywords' => $row->keywords ?? null,
            'h1' => $row->h1 ?? null,
            'canonical_url' => $row->canonical_url ?? null,
            'indexable' => $row ? (bool) $row->indexable : true,
            'og_title' => $row->og['title'] ?? null,
            'og_description' => $row->og['description'] ?? null,
            'og_image' => $row->og['image'] ?? null,
            'twitter_title' => $row->twitter['title'] ?? null,
            'twitter_description' => $row->twitter['description'] ?? null,
            'twitter_image' => $row->twitter['image'] ?? null,
            'faq' => $row->faq ?? null,
        ]);
    }

    public function structuredData(): JsonResponse
    {
        $out = [];
        foreach (SeoMeta::whereNotNull('json_ld')->get() as $row) {
            $out[$row->path] = $row->json_ld;
        }
        return response()->json($out);
    }

    public function llmsTxt(): \Illuminate\Http\Response
    {
        $content = FilePublisher::read('llms.txt');
        if ($content !== null) {
            return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        // Generate on the fly (same shape as generate_llms task).
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/') ?: url('/');
        $lines = ["# {$siteUrl}", ''];
        foreach (SeoMeta::orderBy('path')->get() as $row) {
            $lines[] = '- [' . ($row->title ?: $row->path) . '](' . $siteUrl . $row->path . ')' . ($row->description ? ': ' . $row->description : '');
        }
        return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function pages(): JsonResponse
    {
        $out = [];
        foreach (SeoMeta::orderBy('path')->get() as $row) {
            $out[] = [
                'id' => $row->path,
                'page' => $row->path,
                'url' => $row->url ?? $row->path,
                'title' => $row->title,
                'description' => $row->description,
                'structuredData' => $row->json_ld,
            ];
        }
        return response()->json(['pages' => $out]);
    }

    public function page(string $slug): JsonResponse
    {
        $path = SeoStore::normalizePath($slug);
        $row = SeoMeta::where('path', $path)->first();
        if (!$row) {
            return response()->json(['detail' => 'Page not found'], 404);
        }
        return response()->json([
            'id' => $row->path,
            'page' => $row->path,
            'url' => $row->url ?? $row->path,
            'title' => $row->title,
            'description' => $row->description,
            'keywords' => $row->keywords,
            'h1' => $row->h1,
            'canonical_url' => $row->canonical_url,
            'indexable' => (bool) $row->indexable,
            'og_title' => $row->og['title'] ?? null,
            'og_description' => $row->og['description'] ?? null,
            'og_image' => $row->og['image'] ?? null,
            'twitter_title' => $row->twitter['title'] ?? null,
            'twitter_description' => $row->twitter['description'] ?? null,
            'twitter_image' => $row->twitter['image'] ?? null,
            'structured_data' => $row->json_ld,
            'faq' => $row->faq,
            'custom_meta' => $row->custom_meta,
        ]);
    }

    public function product(string $externalId): JsonResponse
    {
        $p = Product::where('external_id', $externalId)->first();
        if (!$p) {
            return response()->json(['detail' => 'Product not found'], 404);
        }
        return response()->json(['product_id' => $externalId, 'fields' => $p->fields ?? []]);
    }

    // ------------------------------------------------------------ authed API

    public function adminPages(): JsonResponse
    {
        return $this->pages();
    }

    public function adminPage(string $pageId): JsonResponse
    {
        return $this->page($pageId);
    }

    public function updatePage(Request $request, string $pageId): JsonResponse
    {
        $path = SeoStore::normalizePath($pageId);
        if (!preg_match('/^[\w\-\/]{1,200}$/u', $path)) {
            return response()->json(['detail' => 'Invalid page slug'], 422);
        }
        $fields = SeoStore::fieldsFrom($request->all());
        SeoStore::apply($path, $fields);
        return response()->json(['ok' => true, 'page_id' => $pageId, 'page' => $path]);
    }

    public function upsertPage(Request $request, string $pageId): JsonResponse
    {
        $path = SeoStore::normalizePath($pageId);
        if (!preg_match('/^[\w\-\/]{1,200}$/u', $path)) {
            return response()->json(['detail' => 'Invalid page slug'], 422);
        }
        $created = !SeoMeta::where('path', $path)->exists();
        SeoStore::apply($path, SeoStore::fieldsFrom($request->all()));
        return response()->json(['ok' => true, 'page_id' => $pageId, 'page' => $path, 'created' => $created]);
    }

    public function applyFix(Request $request): JsonResponse
    {
        $body = $request->all();
        $pageId = (string) ($body['page_id'] ?? $body['page'] ?? '');
        $path = SeoStore::normalizePath($pageId);
        if (!SeoMeta::where('path', $path)->exists() && $pageId === '') {
            return response()->json(['success' => false, 'applied' => false, 'message' => 'Unknown page: (empty)']);
        }

        $issueType = strtolower(trim((string) ($body['issue_type'] ?? '')));
        if ($issueType !== '') {
            $executor = new TaskExecutor();
            $res = $executor->execute([
                'id' => 0,
                'task_type' => 'fix_seo',
                'payload' => [
                    'url' => $path,
                    'issue_type' => $issueType,
                    'fix_content' => $body['fix_content'] ?? null,
                ],
            ]);
            if (!($res['success'] ?? false)) {
                return response()->json(['success' => false, 'applied' => false, 'message' => $res['error'] ?? 'fix failed']);
            }
            $field = $res['result_data']['field'] ?? $issueType;
            return response()->json(['success' => true, 'page' => $path, 'applied' => [$field]]);
        }

        $fields = SeoStore::fieldsFrom($body);
        if (!$fields && isset($body['fix_content'])) {
            $fields = ['description' => (string) $body['fix_content']];
        }
        if ($fields) {
            SeoStore::apply($path, $fields);
        }
        return response()->json(['success' => true, 'page' => $path, 'applied' => array_keys($fields)]);
    }

    public function aiExport(): JsonResponse
    {
        $pages = [];
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/');
        foreach (SeoMeta::orderBy('path')->get() as $row) {
            $pages[] = [
                'page' => $row->path,
                'url' => $siteUrl . $row->path,
                'title' => $row->title ?: $row->path,
                'description' => $row->description,
                'content_markdown' => $row->extra['content_markdown'] ?? '',
            ];
        }
        return response()->json(['pages' => $pages, 'count' => count($pages)]);
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $body = $request->all();
        $filename = (string) ($body['filename'] ?? $body['path'] ?? $body['file_path'] ?? '');
        $content = $body['content'] ?? $body['data'] ?? '';
        $encoding = (string) ($body['encoding'] ?? 'utf-8');
        try {
            $target = FilePublisher::publish($filename, (string) $content, $encoding);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
        $siteUrl = rtrim((string) config('geoengine.site_url', ''), '/');
        return response()->json(['ok' => true, 'filename' => $filename, 'url' => $siteUrl . '/' . ltrim($filename, '/')]);
    }

    public function createPost(Request $request): JsonResponse
    {
        $executor = new TaskExecutor();
        $result = $executor->execute([
            'id' => 0,
            'task_type' => 'publish_content_plan',
            'payload' => $request->all(),
        ]);
        if (!($result['success'] ?? false)) {
            return response()->json(['detail' => $result['error'] ?? 'publish failed'], 422);
        }
        return response()->json($result['result_data'] ?? ['ok' => true]);
    }

    public function adminProducts(Request $request): JsonResponse
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $limit = min(500, max(1, (int) $request->query('limit', 200)));
        $q = Product::query()->orderBy('external_id');
        $total = (clone $q)->count();
        $rows = $q->offset($offset)->limit($limit)->get();
        $items = $rows->map(fn ($p) => ['external_id' => $p->external_id, 'kind' => $p->kind] + ($p->fields ?? []));
        return response()->json([
            'items' => $items,
            'total' => $total,
            'has_more' => $offset + $rows->count() < $total,
            'next_offset' => $offset + $rows->count(),
        ]);
    }

    public function upsertProducts(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        if (!is_array($items)) {
            return response()->json(['detail' => 'items must be a list'], 422);
        }
        $upserted = 0;
        foreach (array_slice($items, 0, 1000) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = trim((string) ($item['id'] ?? $item['external_id'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $fields = array_diff_key($item, array_flip(['id', 'external_id']));
            $existing = Product::where('external_id', $pid)->first();
            $merged = array_merge($existing->fields ?? [], $fields);
            Product::updateOrCreate(
                ['external_id' => $pid],
                ['kind' => $existing->kind ?? 'product', 'fields' => $merged, 'modified_at' => now()]
            );
            $upserted++;
        }
        return response()->json(['ok' => true, 'upserted' => $upserted]);
    }

    public function updateProduct(Request $request, string $productId): JsonResponse
    {
        if (!preg_match('/^[\w\-]{1,120}$/', $productId)) {
            return response()->json(['detail' => 'Invalid product_id'], 422);
        }
        $body = $request->all();
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : $body;
        $executor = new TaskExecutor();
        $result = $executor->execute([
            'id' => 0,
            'task_type' => 'update_product',
            'payload' => ['product_id' => $productId, 'fields' => $fields],
        ]);
        if (!($result['success'] ?? false)) {
            return response()->json(['detail' => $result['error'] ?? 'update failed'], 422);
        }
        return response()->json(['ok' => true, 'product_id' => $productId, 'result_data' => $result['result_data'] ?? null]);
    }

    public function selfUpdate(Request $request): JsonResponse
    {
        $executor = new TaskExecutor();
        $result = $executor->execute([
            'id' => 0,
            'task_type' => 'self_update',
            'payload' => $request->all(),
        ]);
        if (!($result['success'] ?? false)) {
            return response()->json(['detail' => $result['error'] ?? 'self_update failed'], 422);
        }
        return response()->json($result['result_data'] ?? ['ok' => true]);
    }
}
