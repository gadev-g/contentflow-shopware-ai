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
        private EntityRepository $currencyRepository,
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
                $documents = $this->documents($products, $context);

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
            $documents = $this->documents($products, $context);

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
            ->addAssociation('properties.group')
            ->addAssociation('prices')
            ->addAssociation('children.options.group')
            ->addAssociation('children.prices')
            ->addAssociation('children.properties.group');

        return $criteria;
    }

    /**
     * @param iterable<\Shopware\Core\Content\Product\ProductEntity> $products
     * @return list<array<string, mixed>>
     */
    private function documents(iterable $products, Context $context): array
    {
        $documents = [];
        $currency = $this->currencyRepository->search(new Criteria([$context->getCurrencyId()]), $context)->first();
        $currencyIso = (string) ($currency?->getIsoCode() ?? 'EUR');

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
            $technicalData = $attributes;
            $customFields = \is_array($product->getCustomFields()) ? $product->getCustomFields() : [];
            $variants = [];
            foreach ($product->getChildren() ?? [] as $variant) {
                $options = [];
                foreach ($variant->getOptions() ?? [] as $option) {
                    $options[(string) ($option->getGroup()?->getTranslation('name') ?? 'Option')] =
                        (string) $option->getTranslation('name');
                }
                $variants[] = [
                    'id' => $variant->getId(),
                    'name' => (string) $variant->getTranslation('name'),
                    'product_number' => $variant->getProductNumber(),
                    'options' => $options,
                    'price' => $this->lowestCatalogPrice($variant, $context),
                    'active' => $variant->getActive(),
                ];
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
                'technical_data' => $technicalData,
                'custom_fields' => $customFields,
                'variants' => \array_slice($variants, 0, 50),
                'price' => $this->lowestCatalogPrice($product, $context),
                'currency' => $currencyIso,
                'active' => $product->getActive(),
            ];
        }

        return $documents;
    }

    private function lowestCatalogPrice(
        \Shopware\Core\Content\Product\ProductEntity $product,
        Context $context,
    ): ?float {
        $prices = [];
        $basePrice = $product->getPrice()?->getCurrencyPrice($context->getCurrencyId());
        if (null !== $basePrice) {
            $prices[] = $basePrice->getGross();
        }
        foreach ($product->getPrices() ?? [] as $rulePrice) {
            $price = $rulePrice->getPrice()->getCurrencyPrice($context->getCurrencyId());
            if (null !== $price) {
                $prices[] = $price->getGross();
            }
        }

        return [] === $prices ? null : min($prices);
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
