<?php declare(strict_types=1);

namespace Waiter24\Export\Subscriber;

use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waiter24\Export\Service\PluginConfig;
use Waiter24\Export\Service\RealtimeQueue;

/**
 * Queues every written product (or variant) for the debounced realtime push.
 * Stock, price, description and availability all live on the product entity
 * itself in Shopware, so this one event covers everything the WooCommerce
 * plugin and Magento module need several separate hooks for. A product that
 * gets deactivated is included here too (it is still a write) and pushed as
 * unavailable at flush time; an outright deletion is not observed — nothing
 * would be left to re-read by the time the queue flushes — and is only
 * picked up by the next scheduled export.
 *
 * Same on/off switch as the scheduled export: automatic sync off means
 * nothing pushes on its own, full stop.
 */
class ProductWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RealtimeQueue $queue,
        private readonly PluginConfig $config,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten'];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if (! $this->config->isAutoSyncEnabled()) {
            return;
        }

        foreach ($event->getIds() as $id) {
            $this->queue->push((string) $id);
        }
    }
}
