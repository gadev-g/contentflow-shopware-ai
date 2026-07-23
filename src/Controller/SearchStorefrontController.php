<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Controller;

use ContentFlow\ShopwareAi\Service\ContentFlowClient;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
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
        private SalesChannelRepository $productRepository,
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
            $dialogLanguage = \is_string($data['language'] ?? null)
                && 1 === preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $data['language'])
                    ? $data['language']
                    : $context->getLanguageInfo()->localeCode;
            $response = $this->client->post('/api/v1/integrations/shopware/assistant/messages', [
                ...$data,
                'session_id' => $request->getSession()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'language' => $dialogLanguage,
                'catalog_language' => $context->getLanguageId(),
                'provider' => $this->client->provider(),
                'model' => $this->client->model(),
            ]);
            $response['products'] = $this->availableProducts($response['products'] ?? [], $context);
            $action = $response['cart_action'] ?? null;

            if (
                \is_array($action)
                && 'add_product' === ($action['type'] ?? null)
                && \is_string($action['product_id'] ?? null)
                && \in_array($action['product_id'], array_column($response['products'], 'id'), true)
            ) {
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

    /**
     * @param mixed $products
     *
     * @return list<array<string, mixed>>
     */
    private function availableProducts(mixed $products, SalesChannelContext $context): array
    {
        if (!\is_array($products)) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn (mixed $product): string => \is_array($product) && \is_string($product['id'] ?? null)
                ? $product['id']
                : '',
            $products,
        )));
        if ([] === $ids) {
            return [];
        }

        $availableIds = $this->productRepository
            ->search(new Criteria($ids), $context)
            ->getIds();

        return array_values(array_filter(
            $products,
            static fn (mixed $product): bool => \is_array($product)
                && \is_string($product['id'] ?? null)
                && \in_array($product['id'], $availableIds, true),
        ));
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
