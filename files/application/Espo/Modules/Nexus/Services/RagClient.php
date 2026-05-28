<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Services;

/**
 * RagClient — wraps NEXUS /api/v1/rag/ingest and /api/v1/rag/query.
 *
 * Used by the AfterSave hook to push entity summaries into the NEXUS
 * knowledge base so the AI assistant has up-to-date CRM context.
 */
class RagClient
{
    private string    $baseUrl;
    private NexusAuth $auth;

    public function __construct(string $baseUrl, NexusAuth $auth)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->auth    = $auth;
    }

    /**
     * Ingest a single document into a named collection.
     *
     * @param  array<string, mixed> $metadata
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function ingest(string $collection, string $docId, string $text, array $metadata = []): array
    {
        return $this->post('/api/v1/rag/ingest', [
            'collection' => $collection,
            'documents'  => [
                [
                    'id'       => $docId,
                    'text'     => $text,
                    'metadata' => $metadata,
                ],
            ],
        ]);
    }

    /**
     * Semantic search across a collection.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function query(string $collection, string $queryText, int $topK = 5): array
    {
        return $this->post('/api/v1/rag/query', [
            'collection' => $collection,
            'query'      => $queryText,
            'top_k'      => $topK,
        ]);
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
            throw new \RuntimeException('RagClient: curl_init failed');
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
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errStr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(
                "RagClient: unreachable — {$errStr} (errno {$errno})"
            );
        }

        if ($status === 401) {
            $this->auth->invalidate();
            throw new \RuntimeException('RagClient: authentication failed (HTTP 401)');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("RagClient: HTTP {$status} from {$url}");
        }

        if ($raw === '' || $raw === false) {
            return [];
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
