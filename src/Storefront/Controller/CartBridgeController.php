<?php declare(strict_types=1);

namespace Waiter24\Export\Storefront\Controller;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Waiter24\Export\Service\PluginConfig;

/**
 * Waiter24 cart-bridge: the platform-neutral cart contract the chat widget
 * speaks on every CMS.
 *
 *   POST /waiter24/cart/add   {"product_id": "<uuid>", "qty": 2, "addons": [{"name","price","qty"}]} → {"success": true}
 *   GET  /waiter24/cart       → {"items": [{"name", "qty", "price"}]}
 *
 * product_id may be a variant id — Shopware variants are ordinary products,
 * and the export ships each variation's id as external_id. Everything acts on
 * the visitor's own cart token (session cookie), theme-independent. Shopware
 * 6.5+ storefront POSTs need no CSRF token (SameSite cookies), and the widget
 * only calls these routes same-origin.
 *
 * Add-ons (see docs/addons.md in the main waiter-saas repo) are quantity-priced
 * dish extras, not real products — modeled as CONTAINER children of the dish's
 * line item (Shopware's cart calculation derives a container's own total as
 * the sum of its children's calculated prices). NOT verified against a live
 * 6.6.x install: (1) that container-price aggregation still behaves this way
 * on the exact patch version installed, and (2) the add-on's tax rate, which
 * is hardcoded to 0% below pending a product-independent way to resolve the
 * dish's real tax class in this controller (it only has a product id, not the
 * loaded product entity). Fix by wiring a product repository lookup if the
 * displayed tax total needs to be exact.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class CartBridgeController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly PluginConfig $config,
    ) {
    }

    #[Route(path: '/waiter24/cart/add', name: 'frontend.waiter24.cart.add', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function add(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (! $this->config->isWidgetEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['success' => false, 'error' => 'disabled'], 404);
        }

        [$productId, $qty, $addons] = $this->parseInput($request);
        if ($productId === '') {
            return new JsonResponse(['success' => false, 'error' => 'product_id is required']);
        }

        try {
            $lineItem = $this->lineItemFactory->create([
                'type'         => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $productId,
                'quantity'     => $qty,
            ], $context);

            if (! empty($addons)) {
                $lineItem->setType(LineItem::CONTAINER_LINE_ITEM_TYPE);
                foreach ($addons as $addon) {
                    $child = new LineItem(Uuid::randomHex(), LineItem::CUSTOM_LINE_ITEM_TYPE, null, (int) $addon['qty']);
                    $child->setLabel((string) $addon['name']);
                    $child->setGood(false);
                    $child->setStackable(false);
                    $child->setRemovable(false);
                    $child->setPriceDefinition(new QuantityPriceDefinition(
                        (float) $addon['price'],
                        new TaxRuleCollection([new TaxRule(0.0)]), // see class docblock — not the dish's real tax rate
                        (int) $addon['qty'],
                    ));
                    $lineItem->addChild($child);
                }
            }

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart = $this->cartService->add($cart, $lineItem, $context);

            // The cart calculation silently drops unknown/unsellable products —
            // confirm the item actually landed instead of trusting the add call.
            // Type is PRODUCT normally, or CONTAINER when add-ons were attached
            // above — referencedId is what actually identifies the dish either way.
            foreach ($cart->getLineItems() as $item) {
                if ($item->getReferencedId() === $productId
                    && ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE || $item->getType() === LineItem::CONTAINER_LINE_ITEM_TYPE)) {
                    return new JsonResponse(['success' => true]);
                }
            }

            return new JsonResponse(['success' => false, 'error' => 'product not available']);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    #[Route(path: '/waiter24/cart', name: 'frontend.waiter24.cart', methods: ['GET'], defaults: ['XmlHttpRequest' => true])]
    public function cart(SalesChannelContext $context): JsonResponse
    {
        if (! $this->config->isWidgetEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['items' => []], 404);
        }

        $items = [];
        try {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            foreach ($cart->getLineItems() as $item) {
                // A dish with add-ons is added as a CONTAINER (see add() above),
                // not a plain PRODUCT — both are top-level dish lines here;
                // add-on children live nested under getChildren(), never at
                // this level, so there is no double-counting.
                if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE
                    && $item->getType() !== LineItem::CONTAINER_LINE_ITEM_TYPE) {
                    continue;
                }
                $items[] = [
                    'name'  => (string) $item->getLabel(),
                    'qty'   => $item->getQuantity(),
                    'price' => $item->getPrice()?->getUnitPrice(),
                ];
            }
        } catch (\Throwable) {
            // An unreadable cart must not break the chat — report it empty.
        }

        return new JsonResponse(['items' => $items]);
    }

    /** @return array{0: string, 1: int, 2: list<array{name:string,price:float,qty:int}>} */
    private function parseInput(Request $request): array
    {
        $body = [];
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            $body = is_array($body) ? $body : [];
        } catch (\Throwable) {
            // Fall through to form fields.
        }

        $productId = (string) ($body['product_id'] ?? $request->request->get('product_id', ''));
        $qty       = (int) ($body['qty'] ?? $request->request->get('qty', 1));

        $addons = [];
        foreach ((array) ($body['addons'] ?? []) as $a) {
            if (! is_array($a) || empty($a['name'])) {
                continue;
            }
            $addons[] = [
                'name'  => (string) $a['name'],
                'price' => isset($a['price']) ? (float) $a['price'] : 0.0,
                'qty'   => isset($a['qty']) ? max(1, (int) $a['qty']) : 1,
            ];
        }

        return [trim($productId), max(1, $qty), $addons];
    }
}
