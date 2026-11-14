<?php

namespace GeoEngine\Agent\Console\Commands;

use GeoEngine\Agent\AgentClient;
use GeoEngine\Agent\TaskExecutor;
use Illuminate\Console\Command;

class PollCommand extends Command
{
    protected $signature = 'geoengine:poll {--ping : Only send heartbeat, skip task poll}';

    protected $description = 'Poll GEO Engine for pending CMS tasks and execute them';

    public function handle(): int
    {
        if (!AgentClient::configured()) {
            $this->warn('Agent not configured — run php artisan geoengine:install first.');
            return self::FAILURE;
        }

        $client = new AgentClient();

        // Heartbeat every poll — cheap and keeps last_ping_at fresh.
        $client->ping();

        if ($this->option('ping')) {
            $this->info('Ping sent.');
            return self::SUCCESS;
        }

        $tasks = $client->pendingTasks();
        if (!$tasks) {
            $this->info('No pending tasks.');
            return self::SUCCESS;
        }

        $this->info('Executing ' . count($tasks) . ' task(s)…');
        $executor = new TaskExecutor($client);
        $ok = 0;
        $fail = 0;

        foreach ($tasks as $task) {
            $id = (int) ($task['id'] ?? 0);
            $type = (string) ($task['task_type'] ?? '?');
            $this->line("  #{$id} {$type}…");

            try {
                $result = $executor->execute($task);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => '', 'error' => get_class($e) . ': ' . $e->getMessage()];
            }

            $reported = $client->reportResult(
                $id,
                (bool) ($result['success'] ?? false),
                (string) ($result['message'] ?? ''),
                $result['error'] ?? null,
                $result['result_data'] ?? null,
                isset($task['attempt']) ? (int) $task['attempt'] : null,
            );

            if (($result['success'] ?? false) && $reported) {
                $ok++;
                $this->info("    ✓ " . ($result['message'] ?: 'ok'));
            } else {
                $fail++;
                $this->warn('    ✗ ' . ($result['error'] ?? 'result report failed'));
            }
        }

        $this->info("Done: {$ok} ok, {$fail} failed.");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
