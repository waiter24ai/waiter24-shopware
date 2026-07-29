<?php declare(strict_types=1);

namespace Waiter24\Export\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Waiter24\Export\Service\MenuExporter;
use Waiter24\Export\Service\PluginConfig;

#[AsMessageHandler(handles: ExportTask::class)]
class ExportTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly MenuExporter $exporter,
        private readonly PluginConfig $config,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        // The task itself always runs — Shopware owns its schedule — but it
        // exports nothing unless the merchant asked for automatic sync. A store
        // that only ever wanted the manual export must not have its catalog
        // pushed behind its back.
        if (! $this->config->isAutoSyncEnabled()) {
            return;
        }

        try {
            $result = $this->exporter->run();
            $this->logger->info('Waiter24 scheduled export OK', ['result' => $result]);
        } catch (\Throwable $e) {
            $this->logger->error('Waiter24 scheduled export failed', ['error' => $e->getMessage()]);
        }
    }
}
