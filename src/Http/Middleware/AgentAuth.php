<?php

namespace GeoEngine\Agent\Http\Middleware;

use Closure;
use GeoEngine\Agent\AgentClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth for the /api/geo-engine-bridge/* endpoints.
 *
 * Accepts either:
 *  - X-Api-Key: <project api key>  (geo_… key — same as FastAPI bridge)
 *  - Authorization: Bearer <agent_token>
 *
 * The project key is verified by asking the platform's /agent/package
 * endpoint? No — cheaper: we accept the agent_token (issued at register)
 * OR the configured project key. Project-key validation against the
 * platform happens implicitly: only the platform holds the key→project
 * binding, and this endpoint only reads/writes LOCAL managed state, so
 * a leaked project key grants nothing beyond what the site already
 * publishes. Still, we compare against configured secrets only —
 * fail closed when neither is set.
 */
class AgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->header('X-Api-Key', '');
        if ($provided === '') {
            $auth = (string) $request->header('Authorization', '');
            if (stripos($auth, 'Bearer ') === 0) {
                $provided = trim(substr($auth, 7));
            }
        }
        $provided = trim($provided);
        if ($provided === '') {
            return response()->json(['detail' => 'Invalid or missing API key'], 401);
        }

        $accepted = [
            AgentClient::agentToken(),
            (string) config('geoengine.project_key', ''),
        ];
        $ok = false;
        foreach ($accepted as $secret) {
            if ($secret !== '' && hash_equals($secret, $provided)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return response()->json(['detail' => 'Invalid or missing API key'], 401);
        }
        return $next($request);
    }
}
