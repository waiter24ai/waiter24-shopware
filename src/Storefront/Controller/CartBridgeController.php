<?php declare(strict_types=1);

namespace Waiter24\Export\Storefront\Controller;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Waiter24\Export\Service\PluginConfig;

/**
 * Waiter24 cart-bridge: the platform-neutral cart contract the chat widget
 * speaks on every CMS.
 *
 *   POST /waiter24/cart/add   {"product_id": "<uuid>", "qty": 2} → {"success": true}
 *   GET  /waiter24/cart       → {"items": [{"name", "qty", "price"}]}
 *
 * product_id may be a variant id — Shopware variants are ordinary products,
 * and the export ships each variation's id as external_id. Everything acts on
 * the visitor's own cart token (session cookie), theme-independent. Shopware
 * 6.5+ storefront POSTs need no CSRF token (SameSite cookies), and the widget
 * only calls these routes same-origin.
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

        [$productId, $qty] = $this->parseInput($request);
        if ($productId === '') {
            return new JsonResponse(['success' => false, 'error' => 'product_id is required']);
        }

        try {
            $lineItem = $this->lineItemFactory->create([
                'type'         => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $productId,
                'quantity'     => $qty,
            ], $context);

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart = $this->cartService->add($cart, $lineItem, $context);

            // The cart calculation silently drops unknown/unsellable products —
            // confirm the item actually landed instead of trusting the add call.
            foreach ($cart->getLineItems() as $item) {
                if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $item->getReferencedId() === $productId) {
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
            foreach ($cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $item) {
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

    /** @return array{0: string, 1: int} */
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

        return [trim($productId), max(1, $qty)];
    }
}
