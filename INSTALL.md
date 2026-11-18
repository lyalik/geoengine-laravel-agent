# GEO Engine Agent для Laravel — установка

Полная интеграция сайтов на Laravel (солярис.рф и др.): SEO-мета, JSON-LD,
llms.txt, синхронизация каталога, управляемые редиректы, AI-контент,
откаты и self-update — под управлением geoengine.pro.

## Требования

- PHP 8.1+, Laravel 9–13
- Доступ на запись в `public/` (upload_file: llms.txt, robots.txt, .well-known/*)
- Проект в GEO Engine с доменом сайта + API-ключ (Settings → API Keys, `ge_…`)

## Установка

### Вариант А — напрямую из GitHub (рекомендуется)

```bash
# 1. Подключите репозиторий пакета
composer config repositories.geoengine \
  vcs https://github.com/lyalik/geoengine-agent-laravel

# 2. Установите пакет
composer require geoengine/agent:^1.9

# 3. Опубликуйте конфиг (опционально — есть дефолты)
php artisan vendor:publish --tag=geoengine-config
```

### Вариант Б — из скачанного zip-архива (geoengine-laravel.zip)

```bash
# 1. Скопируйте пакет в проект
mkdir -p packages/geoengine-agent
unzip geoengine-laravel.zip -d packages/
# → packages/geoengine-agent/

# 2. Подключите как path-зависимость (КЛЮЧЕВО: symlink=false,
#    чтобы self_update мог перезаписывать файлы в vendor/)
composer config repositories.geoengine-agent \
  '{"type":"path","url":"packages/geoengine-agent","options":{"symlink":false}}'
composer require geoengine/agent:@dev

# 3. Опубликуйте конфиг (опционально — есть дефолты)
php artisan vendor:publish --tag=geoengine-config

# 4. Установите и зарегистрируйте агента
php artisan geoengine:install --key=ge_ВАШ_КЛЮЧ --site=https://xn--h1afijce9g.xn--p1ai
```

`geoengine:install` сам: прогонит миграции, запишет `.env`, зарегистрирует
агента на платформе и выдаст `GEO_ENGINE_AGENT_ID`/`GEO_ENGINE_AGENT_TOKEN`.

## Расписание (обязательный шаг для polling)

В `app/Console/Kernel.php` (Laravel ≤10) или `routes/console.php` (Laravel 11+):

```php
// Laravel 11+: routes/console.php
Schedule::command('geoengine:poll')->everyFiveMinutes()->withoutOverlapping();

// Laravel ≤10: app/Console/Kernel.php → schedule()
$schedule->command('geoengine:poll')->everyFiveMinutes()->withoutOverlapping();
```

Webhook-push работает без крона (платформа сама POST'ит задачи на
`/api/geo-engine/webhook`), но polling — fallback на случай firewall/даунтайма.

## Что умеет агент

| Возможность | Реализация |
|---|---|
| SEO-мета (title/description/keywords/h1/canonical/OG/Twitter/indexable) | таблица `geoengine_seo` + middleware-инъекция в `<head>` |
| JSON-LD / schema.org | `geoengine_seo.json_ld` → `<script type="application/ld+json">` |
| FAQ → FAQPage schema | `geoengine_seo.faq` → авто-генерация FAQPage JSON-LD |
| llms.txt / robots.txt / sitemap / .well-known/* | запись в `public/` (robots.txt — только если отсутствует или уже managed) |
| Каталог товаров | `geoengine_products` mirror; push через `POST /api/geo-engine-bridge/products` или `GEO_ENGINE_CATALOG_MODEL` (Eloquent-модель) |
| AI-контент карточек (update_product) | поля в `geoengine_products.fields`, фото/видео скачиваются в `public/uploads/geoengine-media` |
| Цены (update_price) | поле `price` в карточке |
| Редиректы | `geoengine_redirects` + middleware 301/302/307/308 |
| Блог/контент-план | `geoengine_pages` (или `GEO_ENGINE_POSTS_MODEL` для записи в свою таблицу) |
| Откат | `geoengine_task_history` — точечный по задаче/URL/all |
| Self-update | `self_update` → скачивание zip с geoengine.pro, backup+rollback |
| Скан структуры | `scan_site_structure` → routes + managed tables → submit-pages |

## Endpoint'ы

Публичные (для direct_api-коннектора и фронта):
- `GET /api/geo-engine/health` — статус, версия
- `GET /api/geo-engine/recon` — capabilities для Scout
- `GET /api/geo-engine/page-meta?page=/path` — managed meta одной страницы
- `GET /api/geo-engine/structured-data` — map path → JSON-LD
- `GET /api/geo-engine/llms-txt` — llms.txt
- `GET /api/geo-engine/pages`, `/pages/{slug}` — managed контент
- `GET /api/geo-engine/products/{id}` — карточка товара (render-time)

Authed (`X-Api-Key` или `Bearer agent_token`), контракт FastAPI bridge:
- `GET/PUT/POST /api/geo-engine-bridge/pages/{page}`
- `POST /api/geo-engine-bridge/apply-fix`
- `GET /api/geo-engine-bridge/ai-export`
- `POST /api/geo-engine-bridge/upload-file` (`/publish-file` — alias)
- `POST /api/geo-engine-bridge/posts`
- `GET/POST /api/geo-engine-bridge/products`, `PUT .../products/{id}`
- `POST /api/geo-engine-bridge/self-update`

Webhook push: `POST /api/geo-engine/webhook` (X-GEOEngine-Token).

## Artisan-команды

```bash
php artisan geoengine:install     # регистрация + миграции + .env
php artisan geoengine:poll        # один цикл poll+execute (для крона)
php artisan geoengine:poll --ping # только heartbeat
php artisan geoengine:status      # статус и счётчики managed-состояния
```

## Конфигурация (.env / config/geoengine.php)

| Ключ | Назначение |
|---|---|
| `GEO_ENGINE_API_URL` | базовый URL платформы (default https://geoengine.pro) |
| `GEO_ENGINE_PROJECT_KEY` | API-ключ проекта `ge_…` |
| `GEO_ENGINE_SITE_URL` | публичный URL сайта (должен совпадать с доменом проекта) |
| `GEO_ENGINE_AGENT_ID` / `GEO_ENGINE_AGENT_TOKEN` | выдаются при install |
| `GEO_ENGINE_INJECT_MIDDLEWARE` | `false` — отключить авто-инъекцию meta в HTML |
| `GEO_ENGINE_PUBLIC_PATH` | переопределить public/ для upload_file |
| `GEO_ENGINE_CATALOG_MODEL` | FQCN Eloquent-модели каталога для sync_catalog |
| `GEO_ENGINE_POSTS_MODEL` | FQCN модели постов для публикации контента |
| `GEO_ENGINE_EXTRA_PATHS` | доп. пути для scan_site_structure (через запятую) |

## Откат / off-boarding

- `seo_rollback` / `rollback` задачи — точечный откат по истории.
- Полное удаление: `php artisan migrate:rollback` (таблицы `geoengine_*`),
  удалить пакет, `composer remove geoengine/agent`, строки `.env`.
  Файлы в `public/` (llms.txt и пр.) удаляются вручную.
