<?php declare(strict_types=1);

namespace Waiter24\Export\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Waiter24\Export\Service\MenuExporter;
use Waiter24\Export\Service\PluginConfig;
use Waiter24\Export\Service\RealtimeQueue;

#[AsMessageHandler(handles: RealtimePushTask::class)]
class RealtimePushTaskHandler extends ScheduledTaskHandler
{
    // See ExportTaskHandler: the parent keeps its logger as `exceptionLogger`.
    private readonly LoggerInterface $taskLogger;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly RealtimeQueue $queue,
        private readonly MenuExporter $exporter,
        private readonly PluginConfig $config,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
        $this->taskLogger = $logger;
    }

    public function run(): void
    {
        // Always drain the queue, even when sync is off — it must not pile up
        // ids waiting for a setting the merchant already turned off.
        $productIds = $this->queue->flush();

        if ($productIds === [] || ! $this->config->isAutoSyncEnabled()) {
            return;
        }

        try {
            $result = $this->exporter->pushProducts($productIds);
            $this->taskLogger->info('Waiter24 realtime push OK', ['result' => $result]);
        } catch (\Throwable $e) {
            $this->taskLogger->error('Waiter24 realtime push failed', ['error' => $e->getMessage()]);
        }
    }
}
