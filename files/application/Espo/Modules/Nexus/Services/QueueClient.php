<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Services;

/**
 * QueueClient — PHP client for the NEXUS LLM queue service (/api/v1/queue/*).
 *
 * Offline-first: a network error throws \RuntimeException with a message
 * beginning "QueueClient: unreachable" so callers can show degraded UI.
 */
class QueueClient
{
    private string     $baseUrl;
    private ?string    $token;
    private ?NexusAuth $auth;

    private function __construct(string $baseUrl, ?string $token, ?NexusAuth $auth)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
        $this->auth    = $auth;
    }

    public static function withToken(string $baseUrl, string $token): self
    {
        return new self($baseUrl, $token, null);
    }

    public static function withAuth(string $baseUrl, NexusAuth $auth): self
    {
        return new self($baseUrl, null, $auth);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Submit a new LLM job.
     *
     * @param  array<string, mixed>|null $contextJson
     * @return array{job_id:string,status:string,priority_assigned:int,position_in_queue:int,estimated_wait_sec:float|null}
     */
    public function submitJob(
        string  $prompt,
        string  $urgency       = 'normal',
        ?string $label         = null,
        ?int    $preferredTier = null,
        ?string $modelHint     = null,
        ?array  $contextJson   = null
    ): array {
        $payload = ['prompt' => $prompt, 'urgency' => $urgency];
        if ($label !== null)         { $payload['job_label']      = $label; }
        if ($preferredTier !== null) { $payload['preferred_tier'] = $preferredTier; }
        if ($modelHint !== null)     { $payload['model_hint']     = $modelHint; }
        if ($contextJson !== null)   { $payload['context_json']   = $contextJson; }

        return $this->post('/api/v1/queue/submit', $payload);
    }

    /** @return array{job_id:string,status:string,position_in_queue:int,started_at:string|null,progress_hint:string|null} */
    public function getJobStatus(string $jobId): array
    {
        return $this->get('/api/v1/queue/status/' . rawurlencode($jobId));
    }

    /** @return array{job_id:string,status:string,result_text:string|null,tier_used:string|null,model_used:string|null,error_message:string|null} */
    public function getJobResult(string $jobId): array
    {
        return $this->get('/api/v1/queue/result/' . rawurlencode($jobId));
    }

    public function cancelJob(string $jobId): bool
    {
        $this->httpDelete('/api/v1/queue/cancel/' . rawurlencode($jobId));
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function listJobs(int $limit = 20, ?string $statusFilter = null): array
    {
        $params = ['limit' => $limit];
        if ($statusFilter !== null) { $params['status'] = $statusFilter; }
        return $this->get('/api/v1/queue/jobs?' . http_build_query($params));
    }

    /** @return array{queued:int,running:int,completed_today:int,failed_today:int} */
    public function getOverview(): array
    {
        return $this->get('/api/v1/queue/overview');
    }

    /** Never throws — returns false on any error. */
    public function isHealthy(): bool
    {
        try {
            $resp = $this->get('/api/health');
            return isset($resp['status']) && $resp['status'] === 'ok';
        } catch (\RuntimeException) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Private HTTP helpers
    // ------------------------------------------------------------------

    /** @return array<string|int, mixed> */
    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @return array<string|int, mixed> */
    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    private function httpDelete(string $path): void
    {
        $this->request('DELETE', $path);
    }

    /**
     * @param  array<string, mixed>|null $payload
     * @return array<string|int, mixed>
     * @throws \RuntimeException
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $token = $this->resolveToken();
        $url   = $this->baseUrl . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('QueueClient: curl_init failed');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($payload !== null) {
            $body      = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errStr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(
                "QueueClient: unreachable — {$errStr} (errno {$errno}, url {$url})"
            );
        }

        if ($status === 401) {
            if ($this->auth !== null) {
                $this->auth->invalidate();
            }
            throw new \RuntimeException(
                "QueueClient: authentication failed — HTTP 401 from {$url}"
            );
        }

        if ($status < 200 || $status >= 300) {
            $detail = '';
            if (is_string($raw) && str_contains($raw, '"detail"')) {
                try {
                    $err    = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                    $detail = ' — ' . ($err['detail'] ?? '');
                } catch (\JsonException) {}
            }
            throw new \RuntimeException("QueueClient: HTTP {$status} from {$url}{$detail}");
        }

        if ($raw === '' || $raw === false) {
            return [];
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function resolveToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        if ($this->auth !== null) {
            return $this->auth->getToken();
        }
        throw new \RuntimeException('QueueClient: no token or auth provider configured');
    }
}
