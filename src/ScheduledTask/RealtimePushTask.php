<?php declare(strict_types=1);

namespace Waiter24\Export\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Debounced flush of the realtime queue (see
 * Subscriber\ProductWrittenSubscriber) — a minute-ish after a product is
 * saved, not a full day.
 */
class RealtimePushTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'waiter24.realtime_push';
    }

    public static function getDefaultInterval(): int
    {
        return 60; // 1 minute
    }
}
