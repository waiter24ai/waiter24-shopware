<?php declare(strict_types=1);

namespace Waiter24\Export\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily catalog push to Waiter24.
 */
class ExportTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'waiter24.export';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24h
    }
}
