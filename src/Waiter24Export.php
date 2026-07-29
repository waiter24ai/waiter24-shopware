<?php declare(strict_types=1);

namespace Waiter24\Export;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Plugin entry point. Shopware discovers services, config and scheduled tasks
 * from Resources/config and src/ automatically; the only thing that needs
 * saying here is how a working daily sync survives the 1.2.0 upgrade.
 */
class Waiter24Export extends Plugin
{
    private const PREFIX = 'Waiter24Export.config.';

    /**
     * Carry a working daily sync across the 1.2.0 upgrade.
     *
     * Before 1.2.0 the scheduled task exported whenever a token was configured,
     * with no way to say no. `autoSync` now gates it and ships as `false` —
     * which, on its own, would silently stop the nightly push for every store
     * already relying on it. So a store that is already configured is switched
     * on explicitly, and only stores installing the plugin fresh get the new
     * off-by-default behaviour.
     *
     * Keyed off the version being upgraded *from*, not off "is the value unset":
     * Shopware writes config defaults during the update itself, so by the time
     * this runs `autoSync` may already read as an explicit false. Before 1.2.0
     * there was no switch to turn off, so that false can only ever be the
     * default that was just written.
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.2.0', '>=')) {
            return;
        }

        $systemConfig = $this->container->get(SystemConfigService::class);

        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');

        $scopes = array_merge(
            [null], // global scope
            array_values($salesChannelRepository->searchIds(new Criteria(), Context::createDefaultContext())->getIds())
        );

        foreach ($scopes as $salesChannelId) {
            $token = trim((string) $systemConfig->get(self::PREFIX . 'importToken', $salesChannelId));

            if ($token === '') {
                continue;
            }

            $systemConfig->set(self::PREFIX . 'autoSync', true, $salesChannelId);
        }
    }
}
