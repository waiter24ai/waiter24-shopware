<?php declare(strict_types=1);

namespace Waiter24\Export\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Debounced "products that changed" queue, filled by
 * Subscriber\ProductWrittenSubscriber and drained by RealtimePushTaskHandler.
 * Piggybacks on system_config — the same store PluginConfig already reads —
 * rather than a new table; this only ever holds a handful of ids between two
 * runs, and losing it costs nothing more than waiting for the next scheduled
 * export. The key is not declared in config.xml, so it never surfaces in the
 * plugin's admin settings screen.
 */
class RealtimeQueue
{
    private const CONFIG_KEY = 'Waiter24Export.realtime.dirtyIds';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function push(string $productId): void
    {
        $ids = $this->all();

        if (in_array($productId, $ids, true)) {
            return;
        }

        $ids[] = $productId;
        $this->systemConfig->set(self::CONFIG_KEY, $ids);
    }

    /**
     * Read and clear the queue in one call, so nothing is processed twice.
     *
     * @return string[]
     */
    public function flush(): array
    {
        $ids = $this->all();
        $this->systemConfig->set(self::CONFIG_KEY, null);

        return $ids;
    }

    /**
     * @return string[]
     */
    private function all(): array
    {
        $ids = $this->systemConfig->get(self::CONFIG_KEY);

        return is_array($ids) ? array_values(array_unique(array_map('strval', $ids))) : [];
    }
}
