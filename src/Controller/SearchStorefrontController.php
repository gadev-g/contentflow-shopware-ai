<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Controller;

use ContentFlow\ShopwareAi\Service\ContentFlowClient;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => [StorefrontRouteScope::ID]])]
final readonly class SearchStorefrontController
{
    public function __construct(
        private ContentFlowClient $client,
        private CartService $cartService,
        private LineItemFactoryInterface $productLineItemFactory,
    ) {
    }

    #[Route('/contentflow/assistant', name: 'frontend.contentflow.assistant', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function assistant(Request $request, Cart $cart, SalesChannelContext $context): JsonResponse
    {
        if (!$this->client->assistantEnabled()) {
            return new JsonResponse(['error' => ['message' => 'The shopping assistant is disabled.']], 404);
        }

        try {
            $data = $request->toArray();
            $response = $this->client->post('/api/v1/integrations/shopware/assistant/messages', [
                ...$data,
                'session_id' => $request->getSession()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'language' => $context->getLanguageId(),
            ]);
            $action = $response['cart_action'] ?? null;

            if (\is_array($action) && 'add_product' === ($action['type'] ?? null) && \is_string($action['product_id'] ?? null)) {
                $lineItem = $this->productLineItemFactory->create([
                    'id' => $action['product_id'],
                    'referencedId' => $action['product_id'],
                    'quantity' => max(1, (int) ($action['quantity'] ?? 1)),
                ], $context);
                $this->cartService->add($cart, $lineItem, $context);
                $response['cart_updated'] = true;
            }

            return new JsonResponse($response);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => ['message' => $exception->getMessage()]], 502);
        }
    }

    #[Route('/contentflow/search/event', name: 'frontend.contentflow.search.event', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function event(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $data = $request->toArray();
            $this->client->post('/api/v1/integrations/shopware/search/events', [
                ...$data,
                'session_id' => $request->getSession()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'language' => $context->getLanguageId(),
            ], 0.6);
        } catch (\Throwable) {
            // Analytics must never interrupt the storefront.
        }

        return new JsonResponse(['accepted' => true], 202);
    }
}
