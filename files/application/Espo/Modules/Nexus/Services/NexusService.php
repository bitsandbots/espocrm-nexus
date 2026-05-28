<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;

/**
 * NexusService — EspoCRM-injectable facade over the NEXUS platform.
 *
 * Reads credentials from EspoCRM system config (nexusUrl, nexusUsername,
 * nexusPassword, nexusEnabled, nexusRagEnabled) and lazily constructs
 * the underlying HTTP clients.
 */
class NexusService
{
    private ?NexusAuth    $auth        = null;
    private ?QueueClient  $queueClient = null;
    private ?AgentClient  $agentClient = null;
    private ?RagClient    $ragClient   = null;

    public function __construct(
        private Config       $config,
        private ConfigWriter $configWriter,
        private Log          $log,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('nexusEnabled', false);
    }

    // ------------------------------------------------------------------
    // Health
    // ------------------------------------------------------------------

    /**
     * Unauthenticated ping of the public /api/health endpoint.
     * Returns the decoded response body, or an empty array on failure.
     *
     * @return array<string, mixed>
     */
    public function checkHealthRaw(): array
    {
        $url = $this->baseUrl() . '/api/health';
        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $status !== 200 || !is_string($raw) || $raw === '') {
            return [];
        }

        try {
            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }

    public function checkHealth(): bool
    {
        $data = $this->checkHealthRaw();
        return isset($data['status']) && $data['status'] === 'ok';
    }

    // ------------------------------------------------------------------
    // Queue
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null $context
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function submitJob(
        string  $prompt,
        string  $urgency = 'normal',
        ?string $label   = null,
        ?array  $context = null
    ): array {
        return $this->queue()->submitJob($prompt, $urgency, $label, null, null, $context);
    }

    /** @return array<string, mixed> */
    public function getJobStatus(string $jobId): array
    {
        return $this->queue()->getJobStatus($jobId);
    }

    /** @return array<string, mixed> */
    public function getJobResult(string $jobId): array
    {
        return $this->queue()->getJobResult($jobId);
    }

    // ------------------------------------------------------------------
    // Agent chat
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function chat(string $message, ?string $sessionId = null, array $context = []): array
    {
        return $this->agent()->chat($message, $sessionId, $context);
    }

    // ------------------------------------------------------------------
    // RAG
    // ------------------------------------------------------------------

    /**
     * Ingest an entity summary into the NEXUS RAG knowledge base.
     * Silently skips unsupported entity types and swallows failures
     * so a RAG outage never blocks a CRM save.
     */
    public function ingestEntity(Entity $entity): void
    {
        if (!(bool) $this->config->get('nexusRagEnabled', true)) {
            return;
        }

        $text = $this->buildEntityText($entity);
        if ($text === '') {
            return;
        }

        $entityType = $entity->getEntityType();

        try {
            $this->rag()->ingest(
                'espocrm',
                $entityType . '_' . $entity->getId(),
                $text,
                [
                    'entityType' => $entityType,
                    'entityId'   => $entity->getId(),
                    'source'     => 'espocrm',
                ]
            );
        } catch (\Throwable $e) {
            $this->log->warning(
                "Nexus: RAG ingest failed for {$entityType}/{$entity->getId()} — {$e->getMessage()}"
            );
        }
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return [
            'nexusUrl'        => (string) $this->config->get('nexusUrl', ''),
            'nexusUsername'   => (string) $this->config->get('nexusUsername', ''),
            'nexusEnabled'    => (bool)   $this->config->get('nexusEnabled', false),
            'nexusRagEnabled' => (bool)   $this->config->get('nexusRagEnabled', true),
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): void
    {
        $allowed = ['nexusUrl', 'nexusUsername', 'nexusPassword', 'nexusEnabled', 'nexusRagEnabled'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                $this->configWriter->set($key, $settings[$key]);
            }
        }
        $this->configWriter->save();
        $this->resetClients();
    }

    // ------------------------------------------------------------------
    // Private factory / entity text builder
    // ------------------------------------------------------------------

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('nexusUrl', 'http://potpie.local:5000'), '/');
    }

    private function auth(): NexusAuth
    {
        if ($this->auth === null) {
            $this->auth = new NexusAuth(
                $this->baseUrl(),
                (string) $this->config->get('nexusUsername', ''),
                (string) $this->config->get('nexusPassword', ''),
            );
        }
        return $this->auth;
    }

    private function queue(): QueueClient
    {
        if ($this->queueClient === null) {
            $this->queueClient = QueueClient::withAuth($this->baseUrl(), $this->auth());
        }
        return $this->queueClient;
    }

    private function agent(): AgentClient
    {
        if ($this->agentClient === null) {
            $this->agentClient = new AgentClient($this->baseUrl(), $this->auth());
        }
        return $this->agentClient;
    }

    private function rag(): RagClient
    {
        if ($this->ragClient === null) {
            $this->ragClient = new RagClient($this->baseUrl(), $this->auth());
        }
        return $this->ragClient;
    }

    private function resetClients(): void
    {
        $this->auth        = null;
        $this->queueClient = null;
        $this->agentClient = null;
        $this->ragClient   = null;
    }

    private function buildEntityText(Entity $entity): string
    {
        $parts = [];

        switch ($entity->getEntityType()) {
            case 'Contact':
                $name = trim(($entity->get('firstName') ?? '') . ' ' . ($entity->get('lastName') ?? ''));
                if ($name) { $parts[] = "{$name} is a contact."; }
                if ($entity->get('accountName')) { $parts[] = "Organization: {$entity->get('accountName')}."; }
                if ($entity->get('title'))        { $parts[] = "Title: {$entity->get('title')}."; }
                if ($entity->get('emailAddress')) { $parts[] = "Email: {$entity->get('emailAddress')}."; }
                if ($entity->get('phoneNumber'))  { $parts[] = "Phone: {$entity->get('phoneNumber')}."; }
                if ($entity->get('description'))  { $parts[] = (string) $entity->get('description'); }
                break;

            case 'Account':
                if ($entity->get('name'))        { $parts[] = "{$entity->get('name')} is an organization."; }
                if ($entity->get('industry'))    { $parts[] = "Industry: {$entity->get('industry')}."; }
                if ($entity->get('type'))        { $parts[] = "Type: {$entity->get('type')}."; }
                if ($entity->get('website'))     { $parts[] = "Website: {$entity->get('website')}."; }
                if ($entity->get('phoneNumber')) { $parts[] = "Phone: {$entity->get('phoneNumber')}."; }
                if ($entity->get('description')) { $parts[] = (string) $entity->get('description'); }
                break;

            case 'Lead':
                $name = trim(($entity->get('firstName') ?? '') . ' ' . ($entity->get('lastName') ?? ''));
                if ($name) { $parts[] = "{$name} is a lead."; }
                if ($entity->get('accountName')) { $parts[] = "Organization: {$entity->get('accountName')}."; }
                if ($entity->get('status'))      { $parts[] = "Status: {$entity->get('status')}."; }
                if ($entity->get('source'))      { $parts[] = "Source: {$entity->get('source')}."; }
                if ($entity->get('emailAddress')){ $parts[] = "Email: {$entity->get('emailAddress')}."; }
                if ($entity->get('description')) { $parts[] = (string) $entity->get('description'); }
                break;

            case 'Case':
                if ($entity->get('name'))        { $parts[] = "Case: {$entity->get('name')}."; }
                if ($entity->get('status'))      { $parts[] = "Status: {$entity->get('status')}."; }
                if ($entity->get('priority'))    { $parts[] = "Priority: {$entity->get('priority')}."; }
                if ($entity->get('type'))        { $parts[] = "Type: {$entity->get('type')}."; }
                if ($entity->get('description')) { $parts[] = (string) $entity->get('description'); }
                if ($entity->get('resolution'))  { $parts[] = "Resolution: {$entity->get('resolution')}."; }
                break;

            default:
                return '';
        }

        return implode(' ', array_filter($parts));
    }
}
