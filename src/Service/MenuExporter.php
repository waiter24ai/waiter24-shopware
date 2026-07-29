<?php declare(strict_types=1);

namespace Waiter24\Export\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Builds the platform-neutral menu JSON from the Shopware catalog and pushes it
 * to the Waiter24 import endpoint. Same schema as the WooCommerce, Shopify and
 * Magento integrations (see examples/menu-import-sample.json).
 */
class MenuExporter
{
    public function __construct(
        private readonly PluginConfig $config,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $currencyRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build + push. Returns the decoded server response.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException when not configured or the push fails.
     */
    /**
     * Products per push. A catalogue is sent in slices so that neither this
     * process nor the import endpoint ever has to hold all of it at once.
     */
    private const BATCH_SIZE = 200;

    /**
     * Smallest product photo worth shipping, in pixels. Matches the 150px every
     * other Waiter24 integration exports; Shopware serves whichever configured
     * thumbnail is the first at or above it.
     */
    private const PHOTO_MIN_WIDTH = 150;

    public function run(?string $salesChannelId = null): array
    {
        $token    = $this->config->getImportToken($salesChannelId);
        $endpoint = $this->config->getEndpointUrl($salesChannelId);

        if ($token === '' || $endpoint === '') {
            throw new \RuntimeException('Waiter24: import token or endpoint URL is not configured.');
        }

        // One import session spans every slice: each slice is an upsert, and only
        // the closing call tells Waiter24 which products the store no longer
        // sells. An export that dies half way therefore hides nothing — it just
        // leaves the previous menu in place.
        $session = 'sw6' . bin2hex(random_bytes(12));
        $sent    = 0;
        $page    = 1;

        do {
            $slice = $this->buildSlice($salesChannelId, $page, self::BATCH_SIZE);

            $payload = [
                'items'          => $slice['items'],
                'import_session' => $session,
                'chunk'          => $page,
            ];

            // The store's selectors and endpoints do not change between slices,
            // so they ride the first one.
            if ($page === 1) {
                $payload['site_config'] = $this->siteConfig();
            }

            $this->push($endpoint, $token, $payload);

            $sent += count($slice['items']);
            ++$page;
        } while ($slice['has_more']);

        if ($sent === 0) {
            throw new \RuntimeException('Waiter24: no visible products to export.');
        }

        return $this->push($endpoint, $token, [
            'items'          => [],
            'import_session' => $session,
            'final'          => true,
        ]);
    }

    /**
     * POST one payload to the import endpoint.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function push(string $endpoint, string $token, array $payload): array
    {
        $client   = HttpClient::create();
        $response = $client->request('POST', $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('Waiter24 export failed', ['status' => $status]);
            throw new \RuntimeException(sprintf('Waiter24: import endpoint returned HTTP %d.', $status));
        }

        return $response->toArray(false);
    }

    /**
     * The whole catalogue in one array. Kept for callers that want it (and for
     * small stores); `run()` goes slice by slice instead.
     *
     * @return array{site_config: array<string,mixed>, items: array<int,array<string,mixed>>}
     */
    public function build(?string $salesChannelId = null): array
    {
        $items = [];
        $page  = 1;

        do {
            $slice = $this->buildSlice($salesChannelId, $page, self::BATCH_SIZE);
            $items = array_merge($items, $slice['items']);
            ++$page;
        } while ($slice['has_more']);

        return [
            'site_config' => $this->siteConfig(),
            'items'       => $items,
        ];
    }

    /**
     * One page of the catalogue.
     *
     * Only products a shopper can actually reach are exported. `active` alone is
     * not enough: a product also has to be visible in the sales channel being
     * pushed, and Shopware keeps that in the `visibilities` association. Without
     * this filter the assistant would happily offer products whose page returns
     * a 404 in that storefront.
     *
     * @return array{items: array<int,array<string,mixed>>, has_more: bool}
     */
    private function buildSlice(?string $salesChannelId, int $page, int $pageSize): array
    {
        $context     = Context::createDefaultContext();
        $simpleStock = $this->config->isSimpleStock($salesChannelId);
        $storeUrl    = $this->config->getStoreUrl($salesChannelId);
        $currency    = $this->resolveCurrencyIso($context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('parentId', null)); // skip variant children; listed under parents

        if ($salesChannelId !== null && $salesChannelId !== '') {
            $criteria->addFilter(new EqualsFilter('visibilities.salesChannelId', $salesChannelId));
        }

        $criteria->addAssociation('categories');
        // Load the cover media's generated thumbnails too, so the export can ship
        // a small ~400px image instead of the full-size original (see resolvePhotoUrl).
        $criteria->addAssociation('cover.media.thumbnails');
        $criteria->addAssociation('children.options.group');
        $criteria->addAssociation('prices');

        // Ordered by a unique key: paging over a non-unique one can shuffle ties
        // between queries and skip a product, and a product no slice ever sent
        // would be hidden when the session closes.
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->setLimit($pageSize);
        $criteria->setOffset(($page - 1) * $pageSize);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $result = $this->productRepository->search($criteria, $context);

        /** @var ProductEntity[] $products */
        $products = $result->getElements();

        $items = [];
        $sort  = ($page - 1) * $pageSize;

        foreach ($products as $product) {
            $items[] = $this->mapProduct($product, ++$sort, $simpleStock, $storeUrl, $currency);
        }

        return [
            'items'    => $items,
            'has_more' => ($page * $pageSize) < $result->getTotal(),
        ];
    }

    /**
     * The plugin ships the waiter24 cart-bridge endpoints (see
     * Storefront/Controller/CartBridgeController), so the widget can add to and
     * read the Shopware cart on any theme. Panel-owned keys
     * (cart_integration_enabled, show_go_to_cart, cart_context_enabled) are
     * deliberately absent.
     *
     * @return array<string,mixed>
     */
    private function siteConfig(): array
    {
        return [
            'platform_preset' => 'shopware',
            'ajax_add_url'    => '/waiter24/cart/add',
            'cart_read_url'   => '/waiter24/cart',
            'cart_url'        => '/checkout/cart',
        ];
    }

    /**
     * Resolve the ISO code (e.g. "EUR") of the context's default currency, so
     * exported prices carry the store's real currency instead of the server's
     * fallback. Returns null if it can't be determined.
     */
    private function resolveCurrencyIso(Context $context): ?string
    {
        try {
            $currency = $this->currencyRepository
                ->search(new Criteria([$context->getCurrencyId()]), $context)
                ->first();

            return $currency?->getIsoCode();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function mapProduct(ProductEntity $product, int $sortOrder, bool $simpleStock, string $storeUrl, ?string $currency): array
    {
        [$category, $subcategory] = $this->resolveCategories($product);
        [$price, $salePrice]      = $this->resolvePrice($product);

        $description = $product->getDescription();
        $description = $description ? trim(html_entity_decode(strip_tags($description))) : null;

        $photoUrl = $this->resolvePhotoUrl($product);

        $available = $simpleStock ? true : (bool) $product->getAvailable();

        $item = [
            'external_id' => $product->getId(),
            'category'    => $category,
            'subcategory' => $subcategory,
            'name'        => (string) $product->getName(),
            'description' => $description ?: null,
            'price'       => $price,
            'sale_price'  => $salePrice,
            'currency'    => $currency,
            'photo_url'   => $photoUrl,
            'product_url' => $storeUrl !== '' ? $storeUrl . '/detail/' . $product->getId() : null,
            'is_available' => $available,
            'sort_order'  => $sortOrder,
        ];

        $variations = $this->resolveVariations($product);
        if ($variations !== []) {
            $item['variations'] = $variations;
        }

        return $item;
    }

    /**
     * Cover image URL for a product, preferring the smallest generated thumbnail
     * that is still big enough over the full-size original — the widget renders
     * dish photos small, so the lighter file loads faster with no visible
     * quality loss. Every Waiter24 integration targets 150px; Shopware only
     * offers the thumbnail sizes the media folder was configured with (400px
     * upwards by default) and does not generate one on demand, so this picks the
     * closest available above the target rather than an exact size. Falls back
     * to the original media URL when the product has no cover or no thumbnails
     * were generated (so the export never loses an image it would have shipped
     * before).
     */
    private function resolvePhotoUrl(ProductEntity $product): ?string
    {
        $media = $product->getCover()?->getMedia();
        if ($media === null) {
            return null;
        }

        $thumbnails = $media->getThumbnails();
        if ($thumbnails !== null && $thumbnails->count() > 0) {
            $sorted = $thumbnails->getElements();
            usort($sorted, static fn ($a, $b) => $a->getWidth() <=> $b->getWidth());

            // Smallest thumbnail still at least PHOTO_MIN_WIDTH wide; if none
            // reach that, keep the largest available.
            $chosen = null;
            foreach ($sorted as $thumb) {
                if ($thumb->getWidth() >= self::PHOTO_MIN_WIDTH) {
                    $chosen = $thumb;
                    break;
                }
            }
            $chosen ??= end($sorted) ?: null;

            $url = $chosen?->getUrl();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $media->getUrl();
    }

    /**
     * @return array{0: float, 1: float|null} [price, salePrice]
     */
    private function resolvePrice(ProductEntity $product): array
    {
        $priceObj = $product->getPrice()?->first();

        if ($priceObj === null) {
            // Variant parents may carry no own price — fall back to the first child.
            $child = $product->getChildren()?->first();
            $priceObj = $child?->getPrice()?->first();
        }

        if ($priceObj === null) {
            return [0.0, null];
        }

        $gross     = (float) $priceObj->getGross();
        $listGross = $priceObj->getListPrice() ? (float) $priceObj->getListPrice()->getGross() : 0.0;

        if ($listGross > $gross && $gross > 0) {
            return [$listGross, $gross]; // regular = "was", sale = current
        }

        return [$gross, null];
    }

    /**
     * Shopware categories are a tree: shallowest → category, next → subcategory.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCategories(ProductEntity $product): array
    {
        $categories = $product->getCategories();
        if ($categories === null || $categories->count() === 0) {
            return [null, null];
        }

        $sorted = $categories->getElements();
        usort($sorted, static fn ($a, $b) => ($a->getLevel() ?? 0) <=> ($b->getLevel() ?? 0));

        $category = null;
        $subcategory = null;
        foreach ($sorted as $cat) {
            $name = (string) $cat->getName();
            if ($name === '') {
                continue;
            }
            if ($category === null) {
                $category = $name;
            } elseif ($subcategory === null) {
                $subcategory = $name;
                break;
            }
        }

        return [$category, $subcategory];
    }

    /**
     * Variant children become selectable variations.
     *
     * @return array<int,array<string,mixed>>
     */
    private function resolveVariations(ProductEntity $product): array
    {
        $children = $product->getChildren();
        if ($children === null || $children->count() === 0) {
            return [];
        }

        $variations = [];
        foreach ($children as $child) {
            if (! $child->getActive()) {
                continue;
            }

            $optionNames = [];
            $options = $child->getOptions();
            if ($options !== null) {
                foreach ($options as $option) {
                    $optionNames[] = (string) $option->getName();
                }
            }

            $priceObj = $child->getPrice()?->first();

            $variations[] = [
                'name'  => $optionNames !== [] ? implode(', ', $optionNames) : (string) $child->getName(),
                'price' => $priceObj ? (float) $priceObj->getGross() : 0.0,
                // Lets the widget push the exact variant the guest picked through
                // the cart bridge (POST /waiter24/cart/add).
                'external_id' => $child->getId(),
            ];
        }

        return $variations;
    }
}
