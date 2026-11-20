# GEO Engine Agent — Laravel

Агент полной интеграции для сайтов на Laravel. Управляется платформой
[GEO Engine](https://geoengine.pro): платформа ставит задачи, агент
исполняет их на сайте — мета, schema, файлы, каталог, контент, откаты.

- Версия: **1.9.30**
- PHP 8.1+, Laravel 9–13
- Пакет: [`geoengine/agent` на Packagist](https://packagist.org/packages/geoengine/agent)
- Исходники: [github.com/lyalik/geoengine-laravel-agent](https://github.com/lyalik/geoengine-laravel-agent)
  (subtree-split монорепо `geo-engine`, автосинк каждые 30 мин)
- Установка: `composer require geoengine/agent` → обновления `composer update geoengine/agent`;
  детали в `INSTALL.md`

## Архитектура

```
geoengine.pro ──push──▶ POST /api/geo-engine/webhook ──▶ TaskExecutor
     ▲                        (X-GEOEngine-Token)            │
     │                                                       ▼
     └──poll fallback── GET /api/v1/cms/tasks/pending   geoengine_* tables
                          POST /tasks/{id}/result            │
                                                            ▼
                                              InjectManagedMeta middleware
                                              (HTML <head> injection)
```

- **Polling**: `php artisan geoengine:poll` по расписанию (5 мин).
- **Webhook push**: платформа доставляет задачи мгновенно; ack
  `{"success":true,"accepted":N}`, исполнение в `terminating`-колбэке.
- **Self-update**: `self_update` задача → zip с `/agent/package`,
  backup+rollback поверх `vendor/geoengine/agent`. Composer-установки
  также обновляются через `composer update geoengine/agent` (релизы =
  git-теги пакетного репозитория).

## Контракт контента

- Внедряемые фрагменты (`inject_content_block`) всегда уходят в DOM
  **невидимо** — обёрнуты в `<template class="ai-citability-fix">`;
  middleware дополнительно оборачивает любой хранимый фрагмент не в
  `<template>` (старые записи становятся невидимыми после обновления).
- `update_content` обновляет только существующую страницу по URL — не
  нашёл → fail. Страницы из инструкций не создаются.

## Безопасность

- Webhook: `hash_equals` по agent_token; невалидный токен → 401.
- Bridge API: `X-Api-Key`/`Bearer` должен совпадать с agent_token или
  project_key; без секретов → 401 (fail closed).
- upload_file: whitelist имён (`[A-Za-z0-9._-]`, `.well-known/*`),
  `..` запрещён.
- Managed-роботы: `robots.txt` пишется только если файла нет или он
  уже содержит маркер GEO Engine — чужой файл не затирается.
- Инъекция meta никогда не роняет страницу (try/catch + report).
