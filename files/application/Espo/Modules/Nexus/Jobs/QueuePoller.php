<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Log\Log;
use Espo\Modules\Nexus\Services\NexusService;

/**
 * QueuePoller — scheduled job that verifies NEXUS connectivity every 5 minutes.
 *
 * Extend this class to implement job state persistence once a NexusJob entity
 * is added to the data model (planned for v1.1).
 */
class QueuePoller implements JobDataLess
{
    public function __construct(
        private NexusService $nexusService,
        private Log          $log,
    ) {}

    public function run(): void
    {
        if (!$this->nexusService->isEnabled()) {
            return;
        }

        $healthy = $this->nexusService->checkHealth();

        if (!$healthy) {
            $this->log->warning('Nexus: QueuePoller — NEXUS is unreachable at ' . date('Y-m-d H:i:s'));
        }
    }
}
