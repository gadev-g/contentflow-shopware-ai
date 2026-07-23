<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final readonly class SearchCatalogSynchronizer
{
    private const BATCH_SIZE = 500;

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
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        $language ??= $context->getLanguageId();
        $saved = 0;
        $batches = 0;

        if ([] !== $ids) {
            foreach (array_chunk($ids, self::BATCH_SIZE) as $idBatch) {
                $criteria = $this->criteria($idBatch);
                $products = $this->productRepository->search($criteria, $context);
                $documents = $this->documents($products);

                if ([] !== $documents) {
                    $saved += $this->sendBatch($documents, $salesChannelId, $language);
                    ++$batches;
                }
            }

            return ['saved' => $saved, 'batches' => $batches];
        }

        $offset = 0;

        do {
            $criteria = $this->criteria();
            $criteria->setLimit(self::BATCH_SIZE);
            $criteria->setOffset($offset);
            $products = $this->productRepository->search($criteria, $context);
            $documents = $this->documents($products);

            if ([] !== $documents) {
                $saved += $this->sendBatch($documents, $salesChannelId, $language);
                ++$batches;
            }

            $offset += self::BATCH_SIZE;
        } while ($products->count() === self::BATCH_SIZE);

        return ['saved' => $saved, 'batches' => $batches];
    }

    /** @param list<string> $ids */
    private function criteria(array $ids = []): Criteria
    {
        $criteria = new Criteria([] !== $ids ? $ids : null);
        $criteria
            ->addAssociation('manufacturer')
            ->addAssociation('categories')
            ->addAssociation('cover.media')
            ->addAssociation('media.media')
            ->addAssociation('properties.group');

        return $criteria;
    }

    /**
     * @param iterable<\Shopware\Core\Content\Product\ProductEntity> $products
     * @return list<array<string, mixed>>
     */
    private function documents(iterable $products): array
    {
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
            $coverUrl = $product->getCover()?->getMedia()?->getUrl()
                ?: $product->getMedia()?->first()?->getMedia()?->getUrl();
            if (\is_string($coverUrl) && '' !== $coverUrl) {
                $attributes['_contentflow_image_url'] = $coverUrl;
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

        return $documents;
    }

    /** @param list<array<string, mixed>> $documents */
    private function sendBatch(array $documents, string $salesChannelId, string $language): int
    {
        $result = $this->client->post('/api/v1/integrations/shopware/search/catalog', [
            'sales_channel_id' => $salesChannelId,
            'language' => $language,
            'documents' => $documents,
        ]);

        return (int) ($result['saved'] ?? 0);
    }
}
