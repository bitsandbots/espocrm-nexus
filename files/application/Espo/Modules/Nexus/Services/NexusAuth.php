<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Services;

/**
 * NexusAuth — JWT authentication helper for the NEXUS platform.
 *
 * Transparently caches tokens and re-fetches them 5 minutes before expiry
 * so callers never have to manage JWT lifetimes.
 */
class NexusAuth
{
    private const EXPIRY_BUFFER_SEC = 300;

    private string $baseUrl;
    private string $username;
    private string $password;
    private string $cacheFile;

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->username  = $username;
        $this->password  = $password;
        $this->cacheFile = sys_get_temp_dir()
            . '/nexus_espo_token_' . hash('sha256', $baseUrl . $username) . '.json';
    }

    /**
     * Return a valid JWT, fetching a fresh one if the cached copy is expired.
     *
     * @throws \RuntimeException if login fails.
     */
    public function getToken(): string
    {
        $cached = $this->loadCached();
        if ($cached !== null) {
            return $cached;
        }

        return $this->login();
    }

    /**
     * Force a fresh login and cache the new token.
     *
     * @throws \RuntimeException on HTTP or network error.
     */
    public function login(): string
    {
        $url  = $this->baseUrl . '/api/v1/auth/login';
        $body = json_encode(
            ['username' => $this->username, 'password' => $this->password],
            JSON_THROW_ON_ERROR
        );

        $response = $this->curlPost($url, $body);

        if (empty($response['token'])) {
            throw new \RuntimeException('NexusAuth: login response missing token field');
        }

        $token        = (string) $response['token'];
        $expiresHours = isset($response['expires_hours']) ? (float) $response['expires_hours'] : 8.0;
        $expiresAt    = time() + (int) ($expiresHours * 3600);

        $this->saveCache($token, $expiresAt);

        return $token;
    }

    /** Remove the cached token so the next call forces a fresh login. */
    public function invalidate(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function curlPost(string $url, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('NexusAuth: curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errStr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(
                "NexusAuth: request to {$url} failed — {$errStr} (errno {$errno})"
            );
        }

        if ($status !== 200) {
            throw new \RuntimeException(
                "NexusAuth: login returned HTTP {$status} from {$url}"
            );
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function loadCached(): ?string
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $raw = file_get_contents($this->cacheFile);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (empty($data['token']) || empty($data['expires_at'])) {
            return null;
        }

        if ((int) $data['expires_at'] - self::EXPIRY_BUFFER_SEC < time()) {
            return null;
        }

        return (string) $data['token'];
    }

    private function saveCache(string $token, int $expiresAt): void
    {
        $payload = json_encode(
            ['token' => $token, 'expires_at' => $expiresAt],
            JSON_THROW_ON_ERROR
        );
        $tmp = $this->cacheFile . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            rename($tmp, $this->cacheFile);
        }
    }
}
