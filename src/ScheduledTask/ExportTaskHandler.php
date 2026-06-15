<?php declare(strict_types=1);

namespace Waiter24\Export\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Waiter24\Export\Service\MenuExporter;

#[AsMessageHandler(handles: ExportTask::class)]
class ExportTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly MenuExporter $exporter,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        try {
            $result = $this->exporter->run();
            $this->logger->info('Waiter24 scheduled export OK', ['result' => $result]);
        } catch (\Throwable $e) {
            $this->logger->error('Waiter24 scheduled export failed', ['error' => $e->getMessage()]);
        }
    }
}
