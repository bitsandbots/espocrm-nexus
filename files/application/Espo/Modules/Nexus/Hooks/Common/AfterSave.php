<?php

declare(strict_types=1);

namespace Espo\Modules\Nexus\Hooks\Common;

use Espo\Core\Hook\Hook\AfterSave as AfterSaveInterface;
use Espo\Modules\Nexus\Services\NexusService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * AfterSave — pushes updated entity summaries into NEXUS RAG after every save.
 *
 * Applies to Contact, Account, Lead, and Case. All other entity types are
 * silently skipped. Failures are logged but never bubble up to block the save.
 *
 * @implements AfterSaveInterface<Entity>
 */
class AfterSave implements AfterSaveInterface
{
    private const INGEST_TYPES = ['Contact', 'Account', 'Lead', 'Case'];

    public static int $order = 100;

    public function __construct(
        private NexusService $nexusService,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$this->nexusService->isEnabled()) {
            return;
        }

        if (!in_array($entity->getEntityType(), self::INGEST_TYPES, true)) {
            return;
        }

        $this->nexusService->ingestEntity($entity);
    }
}
