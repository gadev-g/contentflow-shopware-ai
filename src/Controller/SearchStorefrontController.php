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
            ], 180.0);
            $liveProducts = $this->availableProducts($response['products'] ?? [], $context);
            $response['products'] = $this->filterByLivePrice(
                $liveProducts,
                $response['meta']['filters'] ?? [],
            );
            if ([] !== $liveProducts && [] === $response['products']) {
                $reply = str_starts_with(mb_strtolower($dialogLanguage), 'de')
                    ? 'In dieser Preisspanne habe ich nach Prüfung der aktuellen Shoppreise keine passenden Produkte gefunden. Soll ich die Preisspanne erweitern?'
                    : 'After checking the current shop prices, I found no matching products in this price range. Should I widen the range?';
                $response['reply'] = $reply;
                $response['message'] = $reply;
                $response['type'] = 'clarification';
                $response['needs_clarification'] = true;
                $response['suggestions'] = [];
            }
            $response['comparison'] = $this->liveComparison($response['comparison'] ?? null, $response['products']);
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

    #[Route('/contentflow/assistant', name: 'frontend.contentflow.assistant.clear', methods: ['DELETE'], defaults: ['XmlHttpRequest' => true])]
    public function clearAssistant(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $data = $request->toArray();
            $dialogLanguage = \is_string($data['language'] ?? null)
                && 1 === preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $data['language'])
                    ? $data['language']
                    : $context->getLanguageInfo()->localeCode;

            return new JsonResponse($this->client->delete('/api/v1/integrations/shopware/assistant/messages', [
                'session_id' => $request->getSession()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'language' => $dialogLanguage,
            ]));
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

        $criteria = new Criteria($ids);
        $criteria->addAssociations(['cover.media', 'media.media', 'categories', 'manufacturer']);
        $availableProducts = $this->productRepository->search($criteria, $context)->getEntities();
        $currency = $context->getCurrency()->getIsoCode();
        $result = [];

        foreach ($products as $product) {
            if (!\is_array($product) || !\is_string($product['id'] ?? null)) {
                continue;
            }

            $availableProduct = $availableProducts->get($product['id']);
            if (null === $availableProduct) {
                continue;
            }

            $category = $availableProduct->getCategories()?->first();
            $product['title'] = $availableProduct->getTranslation('name') ?: ($product['title'] ?? '');
            $product['category'] = $category?->getTranslation('name') ?: ($product['category'] ?? '');
            $product['manufacturer'] = $availableProduct->getManufacturer()?->getTranslation('name')
                ?: ($product['manufacturer'] ?? '');
            $localImageUrl = $availableProduct->getCover()?->getMedia()?->getUrl()
                ?: $availableProduct->getMedia()?->first()?->getMedia()?->getUrl();
            $syncedImageUrl = \is_array($product['attributes'] ?? null)
                && \is_string($product['attributes']['_contentflow_image_url'] ?? null)
                    ? $product['attributes']['_contentflow_image_url']
                    : null;
            $product['image_url'] = $localImageUrl ?: $syncedImageUrl;
            $product['price'] = $availableProduct->getCalculatedPrice()->getUnitPrice();
            $product['currency'] = $currency;
            $result[] = $product;
        }

        return $result;
    }

    /**
     * Revalidate hard price constraints against calculated Storefront prices.
     * The search index can briefly lag behind rule or currency price changes.
     *
     * @param list<array<string, mixed>> $products
     * @param mixed                      $filters
     * @return list<array<string, mixed>>
     */
    private function filterByLivePrice(array $products, mixed $filters): array
    {
        if (!\is_array($filters)) {
            return $products;
        }
        $minimum = is_numeric($filters['price_min'] ?? null) ? (float) $filters['price_min'] : null;
        $maximum = is_numeric($filters['price_max'] ?? null) ? (float) $filters['price_max'] : null;
        if (null === $minimum && null === $maximum) {
            return $products;
        }

        return array_values(array_filter($products, static function (array $product) use ($minimum, $maximum): bool {
            if (!is_numeric($product['price'] ?? null)) {
                return false;
            }
            $price = (float) $product['price'];

            return (null === $minimum || $price >= $minimum)
                && (null === $maximum || $price <= $maximum);
        }));
    }

    /**
     * @param mixed $comparison
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>|null
     */
    private function liveComparison(mixed $comparison, array $products): ?array
    {
        if (!\is_array($comparison) || !\is_array($comparison['products'] ?? null)) {
            return null;
        }
        $liveProducts = array_column($products, null, 'id');
        $items = [];
        foreach ($comparison['products'] as $item) {
            if (!\is_array($item) || !\is_string($item['id'] ?? null) || !isset($liveProducts[$item['id']])) {
                continue;
            }
            $live = $liveProducts[$item['id']];
            $items[] = [
                ...$item,
                'name' => (string) ($live['title'] ?? $item['name'] ?? ''),
                'price' => $live['price'] ?? null,
                'currency' => (string) ($live['currency'] ?? $item['currency'] ?? ''),
            ];
        }
        $comparison['products'] = $items;

        return $comparison;
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
