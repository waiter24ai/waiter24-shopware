<?php declare(strict_types=1);

namespace Waiter24\Export\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Typed accessor for the plugin's system configuration.
 */
class PluginConfig
{
    private const PREFIX = 'Waiter24Export.config.';

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function getImportToken(?string $salesChannelId = null): string
    {
        return trim((string) $this->systemConfig->get(self::PREFIX . 'importToken', $salesChannelId));
    }

    public function getWidgetKey(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->get(self::PREFIX . 'widgetKey', $salesChannelId);
    }

    public function getEndpointUrl(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->get(self::PREFIX . 'endpointUrl', $salesChannelId);
    }

    public function getWidgetUrl(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->get(self::PREFIX . 'widgetUrl', $salesChannelId);
    }

    public function getStoreUrl(?string $salesChannelId = null): string
    {
        return rtrim((string) $this->systemConfig->get(self::PREFIX . 'storeUrl', $salesChannelId), '/');
    }

    public function isSimpleStock(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get(self::PREFIX . 'simpleStock', $salesChannelId);
    }

    public function isWidgetEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get(self::PREFIX . 'enableWidget', $salesChannelId);
    }

    public function isDemoMode(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get(self::PREFIX . 'demoMode', $salesChannelId);
    }
}
