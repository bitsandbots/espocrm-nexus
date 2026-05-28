<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\Nexus\Services\NexusService;

/**
 * NexusGateway — EspoCRM API controller that proxies requests to NEXUS.
 *
 * Routes (all under /api/v1/ prefix, defined in Resources/routes.json):
 *   GET    nexus/health
 *   GET    nexus/settings
 *   PUT    nexus/settings
 *   POST   nexus/chat
 *   POST   nexus/submit
 *   GET    nexus/status/:jobId
 *   GET    nexus/result/:jobId
 */
class NexusGateway
{
    public function __construct(
        private NexusService $nexusService,
    ) {}

    /** GET /api/v1/nexus/health */
    public function getActionHealth(Request $request, Response $response): void
    {
        $raw     = $this->nexusService->checkHealthRaw();
        $healthy = isset($raw['status']) && $raw['status'] === 'ok';
        $this->json($response, [
            'healthy'       => $healthy,
            'status'        => $healthy ? 'ok' : 'unreachable',
            'version'       => $raw['version'] ?? null,
            'serviceCount'  => isset($raw['services']) ? count($raw['services']) : null,
        ]);
    }

    /** GET /api/v1/nexus/settings */
    public function getActionSettings(Request $request, Response $response): void
    {
        $this->json($response, $this->nexusService->getSettings());
    }

    /** PUT /api/v1/nexus/settings */
    public function putActionSettings(Request $request, Response $response): void
    {
        $data = (array) $request->getParsedBody();
        $this->nexusService->saveSettings($data);
        $this->json($response, ['status' => 'saved']);
    }

    /** POST /api/v1/nexus/chat */
    public function postActionChat(Request $request, Response $response): void
    {
        // Ollama inference can take 60-90s; override the default 30s PHP limit.
        set_time_limit(120);

        $data      = (array) $request->getParsedBody();
        $message   = trim((string) ($data['message'] ?? ''));
        $sessionId = isset($data['sessionId']) ? (string) $data['sessionId'] : null;
        $context   = [];

        if (!empty($data['entityType']) && !empty($data['entityId'])) {
            $context['entityType'] = (string) $data['entityType'];
            $context['entityId']   = (string) $data['entityId'];
        }

        if ($message === '') {
            $response->setStatus(400);
            $this->json($response, ['error' => 'message is required']);
            return;
        }

        try {
            $result = $this->nexusService->chat($message, $sessionId, $context);
            $this->json($response, $result);
        } catch (\RuntimeException $e) {
            $response->setStatus(502);
            $this->json($response, ['error' => $e->getMessage()]);
        }
    }

    /** POST /api/v1/nexus/submit */
    public function postActionSubmit(Request $request, Response $response): void
    {
        $data    = (array) $request->getParsedBody();
        $prompt  = trim((string) ($data['prompt'] ?? ''));
        $urgency = (string) ($data['urgency'] ?? 'normal');
        $label   = isset($data['label']) ? (string) $data['label'] : null;
        $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : null;

        if ($prompt === '') {
            $response->setStatus(400);
            $this->json($response, ['error' => 'prompt is required']);
            return;
        }

        try {
            $result = $this->nexusService->submitJob($prompt, $urgency, $label, $context);
            $this->json($response, $result);
        } catch (\RuntimeException $e) {
            $response->setStatus(502);
            $this->json($response, ['error' => $e->getMessage()]);
        }
    }

    /** GET /api/v1/nexus/status/:jobId */
    public function getActionStatus(Request $request, Response $response): void
    {
        $jobId = (string) ($request->getRouteParam('jobId') ?? '');
        if ($jobId === '') {
            $response->setStatus(400);
            $this->json($response, ['error' => 'jobId is required']);
            return;
        }

        try {
            $this->json($response, $this->nexusService->getJobStatus($jobId));
        } catch (\RuntimeException $e) {
            $response->setStatus(502);
            $this->json($response, ['error' => $e->getMessage()]);
        }
    }

    /** GET /api/v1/nexus/result/:jobId */
    public function getActionResult(Request $request, Response $response): void
    {
        $jobId = (string) ($request->getRouteParam('jobId') ?? '');
        if ($jobId === '') {
            $response->setStatus(400);
            $this->json($response, ['error' => 'jobId is required']);
            return;
        }

        try {
            $this->json($response, $this->nexusService->getJobResult($jobId));
        } catch (\RuntimeException $e) {
            $response->setStatus(502);
            $this->json($response, ['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(Response $response, array $data): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $response->writeBody(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
