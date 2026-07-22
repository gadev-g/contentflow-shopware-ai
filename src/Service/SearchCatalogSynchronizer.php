<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final readonly class SearchCatalogSynchronizer
{
    public function __construct(
        private ContentFlowClient $client,
        private EntityRepository $productRepository,
    ) {
    }

    /** @param list<string> $ids
     *  @return array<string, mixed>
     */
    public function sync(Context $context, array $ids = [], string $salesChannelId = 'default', ?string $language = null): array
    {
        $criteria = new Criteria([] !== $ids ? $ids : null);
        $criteria->addAssociation('manufacturer')->addAssociation('categories')->addAssociation('properties.group');
        $products = $this->productRepository->search($criteria, $context);
        $documents = [];

        foreach ($products as $product) {
            if (null !== $product->getParentId()) {
                continue;
            }

            $categories = [];
            foreach ($product->getCategories() ?? [] as $category) {
                $categories[] = (string) $category->getTranslation('name');
            }

            $attributes = [];

            foreach ($product->getProperties() ?? [] as $property) {
                $group = $property->getGroup();
                $attributes[(string) ($group?->getTranslation('name') ?? 'Property')][] = (string) $property->getTranslation('name');
            }

            $documents[] = [
                'id' => $product->getId(),
                'title' => (string) $product->getTranslation('name'),
                'description' => strip_tags((string) $product->getTranslation('description')),
                'category' => implode(' ', array_filter($categories)),
                'manufacturer' => (string) ($product->getManufacturer()?->getTranslation('name') ?? ''),
                'product_number' => $product->getProductNumber(),
                'keywords' => array_values(array_filter(array_map('trim', explode(',', (string) $product->getTranslation('keywords'))))),
                'attributes' => $attributes,
                'active' => $product->getActive(),
            ];
        }

        return $this->client->post('/api/v1/integrations/shopware/search/catalog', [
            'sales_channel_id' => $salesChannelId,
            'language' => $language ?? $context->getLanguageId(),
            'documents' => $documents,
        ]);
    }
}
