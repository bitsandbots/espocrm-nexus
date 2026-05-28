<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Services;

/**
 * AgentClient — wraps the NEXUS /api/v1/agent/chat endpoint.
 *
 * Synchronous: call, wait, get reply. Suitable for interactive use.
 * Set a generous timeout (default 90s) — LLM inference can be slow.
 */
class AgentClient
{
    private string    $baseUrl;
    private NexusAuth $auth;

    public function __construct(string $baseUrl, NexusAuth $auth)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->auth    = $auth;
    }

    /**
     * Send a message to the NEXUS agent and return the reply.
     *
     * @param  array<string, mixed> $context  Extra context passed as-is to the agent.
     * @return array{reply:string, session_id:string, ...}
     * @throws \RuntimeException on network or HTTP error.
     */
    public function chat(string $message, ?string $sessionId = null, array $context = []): array
    {
        $payload = ['message' => $message];
        if ($sessionId !== null) {
            $payload['session_id'] = $sessionId;
        }
        if (!empty($context)) {
            $payload['context'] = $context;
        }

        return $this->post('/api/v1/agent/chat', $payload);
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array<string|int, mixed>
     * @throws \RuntimeException
     */
    private function post(string $path, array $payload): array
    {
        $token = $this->auth->getToken();
        $url   = $this->baseUrl . $path;
        $body  = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('AgentClient: curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errStr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(
                "AgentClient: unreachable — {$errStr} (errno {$errno})"
            );
        }

        if ($status === 401) {
            $this->auth->invalidate();
            throw new \RuntimeException('AgentClient: authentication failed (HTTP 401)');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("AgentClient: HTTP {$status} from {$url}");
        }

        if ($raw === '' || $raw === false) {
            return [];
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
