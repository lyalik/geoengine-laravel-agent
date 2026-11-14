<?php

namespace GeoEngine\Agent\Http\Controllers;

use GeoEngine\Agent\AgentClient;
use GeoEngine\Agent\TaskExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Push webhook — the platform POSTs {"tasks":[...]} here when the site is
 * publicly reachable. Contract (mirrors Joomla/WP agents):
 *
 *   - Auth: X-GEOEngine-Token or X-Webhook-Secret header == agent_token
 *     (also accepts ?token= / body token for compatibility).
 *   - Response MUST be fast JSON {"success":true,"accepted":N} — the
 *     platform marks tasks assigned on this ack.
 *   - Execution happens in a terminating callback (after the response is
 *     flushed) so long tasks can't hit the web timeout; each task then
 *     reports back via POST /tasks/{id}/result.
 */
class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $token = (string) ($request->header('X-GEOEngine-Token')
            ?: $request->header('X-Webhook-Secret')
            ?: $request->query('token', '')
            ?: $request->input('token', ''));

        $agentToken = AgentClient::agentToken();
        if ($agentToken === '' || !hash_equals($agentToken, $token)) {
            return response()->json(['success' => false, 'error' => 'Invalid or missing token'], 401);
        }

        $tasks = $request->input('tasks', []);
        if (!is_array($tasks) || !$tasks) {
            return response()->json(['success' => false, 'error' => 'No tasks provided'], 422);
        }

        // Keep only well-formed tasks — anything else is not acked.
        $valid = array_values(array_filter($tasks, fn ($t) => is_array($t) && !empty($t['id'])));

        app()->terminating(function () use ($valid) {
            $this->executeTasks($valid);
        });

        return response()->json(['success' => true, 'accepted' => count($valid)]);
    }

    private function executeTasks(array $tasks): void
    {
        @set_time_limit(3600);
        $executor = new TaskExecutor();
        $client = new AgentClient();

        foreach ($tasks as $task) {
            try {
                $result = $executor->execute($task);
            } catch (\Throwable $e) {
                Log::warning('geoengine webhook task crashed', ['id' => $task['id'] ?? null, 'error' => $e->getMessage()]);
                $result = ['success' => false, 'message' => '', 'error' => 'Internal error: ' . $e->getMessage()];
            }
            $client->reportResult(
                (int) $task['id'],
                (bool) ($result['success'] ?? false),
                (string) ($result['message'] ?? ''),
                $result['error'] ?? null,
                $result['result_data'] ?? null,
                isset($task['attempt']) ? (int) $task['attempt'] : null,
            );
        }
    }
}
