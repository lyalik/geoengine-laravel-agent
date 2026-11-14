<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Managed SEO meta per public path/URL — written by update_meta*,
        // inject_schema, add_faq; rendered by InjectManagedMeta middleware.
        Schema::create('geoengine_seo', function (Blueprint $table) {
            $table->id();
            $table->string('path', 500)->unique(); // "/", "/about", "/products/x"
            $table->string('url', 1000)->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('keywords')->nullable();
            $table->string('h1')->nullable();
            $table->string('canonical_url', 1000)->nullable();
            $table->boolean('indexable')->default(true);
            $table->json('og')->nullable();       // {title,description,image}
            $table->json('twitter')->nullable();  // {title,description,image}
            $table->json('json_ld')->nullable();  // structured_data / schema
            $table->json('faq')->nullable();      // faq_items
            $table->json('custom_meta')->nullable();
            $table->json('extra')->nullable();    // any other managed fields
            $table->timestamps();
        });

        // Product mirror — the site's own catalog pushed via
        // POST /api/geo-engine/products, enriched by update_product tasks.
        Schema::create('geoengine_products', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 190)->unique();
            $table->string('kind', 40)->default('product');
            $table->json('fields')->nullable();   // name/desc/meta/price/images…
            $table->timestamp('modified_at')->nullable();
            $table->timestamps();
        });

        // Managed redirects — written by sync_redirects, applied by
        // RedirectMiddleware / InjectManagedMeta chain.
        Schema::create('geoengine_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 500)->unique();
            $table->string('to_path', 1000);
            $table->unsignedSmallInteger('code')->default(301);
            $table->timestamps();
        });

        // Managed content pages (blog posts, landing pages) — written by
        // publish_content_plan / update_article when no posts_model is set.
        Schema::create('geoengine_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 300)->unique();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->json('meta')->nullable();      // seo fields for the page
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Per-task rollback history — before/after snapshots so
        // seo_rollback / rollback can restore precisely what a task wrote.
        Schema::create('geoengine_task_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->string('task_type', 60);
            $table->string('target', 500)->nullable(); // url / product external_id
            $table->string('field', 120)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->boolean('restored')->default(false);
            $table->timestamps();
        });

        // Misc agent state (geotargeting, flags, last sync markers).
        Schema::create('geoengine_settings', function (Blueprint $table) {
            $table->string('key', 190)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geoengine_settings');
        Schema::dropIfExists('geoengine_task_history');
        Schema::dropIfExists('geoengine_pages');
        Schema::dropIfExists('geoengine_redirects');
        Schema::dropIfExists('geoengine_products');
        Schema::dropIfExists('geoengine_seo');
    }
};
