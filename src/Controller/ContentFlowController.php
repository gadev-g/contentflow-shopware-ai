<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Controller;

use ContentFlow\ShopwareAi\Service\ContentFlowClient;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => [ApiRouteScope::ID]])]
final class ContentFlowController extends AbstractController
{
    public function __construct(
        private readonly ContentFlowClient $client,
        private readonly EntityRepository $productRepository,
        private readonly Connection $connection,
    ) {
    }

    #[Route('/api/_action/contentflow/connection', name: 'api.action.contentflow.connection', methods: ['POST'])]
    public function connection(): JsonResponse
    {
        try {
            $result = $this->client->get('/api/v1/providers');

            $providers = $this->enabledProviders($result['items'] ?? []);
            $provider = $this->client->provider();

            if (!\in_array($provider, $providers, true)) {
                $provider = $providers[0] ?? '';
            }

            return new JsonResponse([
                'connected' => true,
                'context' => $result,
                'provider' => $provider,
                'providers' => $providers,
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['connected' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    #[Route('/api/_action/contentflow/settings/provider', name: 'api.action.contentflow.settings.provider', methods: ['POST'])]
    public function saveProvider(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
            $provider = $data['provider'] ?? null;
            $context = $this->client->get('/api/v1/providers');
            $providers = $this->enabledProviders($context['items'] ?? []);

            if (!\is_string($provider) || !\in_array($provider, $providers, true)) {
                return new JsonResponse(['error' => ['message' => 'Select an enabled ContentFlow provider.']], 422);
            }

            $this->client->setProvider($provider);

            return new JsonResponse(['saved' => true, 'provider' => $provider]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => ['message' => $exception->getMessage()]], 422);
        }
    }

    #[Route('/api/_action/contentflow/products/translate-preview', name: 'api.action.contentflow.products.translate_preview', methods: ['POST'])]
    public function translateProducts(Request $request, Context $context): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->toArray();
        $ids = $this->stringList($data['ids'] ?? []);

        if ([] === $ids || \count($ids) > 25) {
            return new JsonResponse(['error' => ['message' => 'Select between 1 and 25 products.']], 422);
        }

        $criteria = new Criteria($ids);
        $products = $this->productRepository->search($criteria, $context);
        $records = [];

        foreach ($products as $product) {
            $records[] = [
                'reference' => 'product:' . $product->getId(),
                'fields' => array_filter([
                    'name' => $product->getTranslation('name'),
                    'description' => $product->getTranslation('description'),
                    'metaTitle' => $product->getTranslation('metaTitle'),
                    'metaDescription' => $product->getTranslation('metaDescription'),
                ], static fn (mixed $value): bool => \is_string($value) && '' !== trim($value)),
                'formats' => ['description' => 'html'],
            ];
        }

        $result = $this->client->post('/api/v1/integrations/shopware/jobs', [
            'source_language' => (string) ($data['sourceLanguage'] ?? 'de'),
            'target_language' => (string) ($data['targetLanguage'] ?? 'en'),
            'provider' => $this->client->provider(),
            'model' => $this->client->model(),
            'records' => $records,
        ]);

        return new JsonResponse($result, 201);
    }

    #[Route('/api/_action/contentflow/products/approve', name: 'api.action.contentflow.products.approve', methods: ['POST'])]
    public function approveProducts(Request $request, Context $context): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->toArray();
        $languageId = $data['languageId'] ?? null;
        $records = $data['records'] ?? null;

        if (!\is_string($languageId) || !\is_array($records)) {
            return new JsonResponse(['error' => ['message' => 'languageId and records are required.']], 422);
        }

        $updates = [];

        foreach ($records as $record) {
            if (!\is_array($record) || !\is_string($record['reference'] ?? null) || !\is_array($record['fields'] ?? null)) {
                continue;
            }

            $id = str_replace('product:', '', $record['reference']);
            $updates[] = [
                'id' => $id,
                'translations' => [[
                    'languageId' => $languageId,
                    ...array_intersect_key($record['fields'], array_flip(['name', 'description', 'metaTitle', 'metaDescription'])),
                ]],
            ];
        }

        if ([] === $updates) {
            return new JsonResponse(['error' => ['message' => 'No valid records were supplied.']], 422);
        }

        $this->productRepository->update($updates, $context);

        return new JsonResponse(['saved' => \count($updates)]);
    }

    #[Route('/api/_action/contentflow/products/seo-preview', name: 'api.action.contentflow.products.seo_preview', methods: ['POST'])]
    public function analyzeProductSeo(Request $request, Context $context): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->toArray();
        $ids = $this->stringList($data['ids'] ?? []);

        if ([] === $ids || \count($ids) > 25) {
            return new JsonResponse(['error' => ['message' => 'Select between 1 and 25 products.']], 422);
        }

        $products = $this->productRepository->search(new Criteria($ids), $context);
        $records = [];

        foreach ($products as $product) {
            $name = (string) $product->getTranslation('name');
            $description = (string) $product->getTranslation('description');
            $result = $this->client->post('/api/v1/integrations/shopware/seo/analyze', [
                'reference' => 'product:' . $product->getId(),
                'title' => '' !== trim($name) ? $name : $product->getProductNumber(),
                'content' => '' !== trim(strip_tags($description)) ? $description : $name,
                'language' => (string) ($data['language'] ?? 'de'),
                'provider' => $this->client->provider(),
                'model' => $this->client->model(),
            ]);
            $records[] = [
                'reference' => $result['reference'] ?? 'product:' . $product->getId(),
                'metadata' => $result['metadata'] ?? [],
            ];
        }

        return new JsonResponse(['records' => $records], 201);
    }

    #[Route('/api/_action/contentflow/products/seo-approve', name: 'api.action.contentflow.products.seo_approve', methods: ['POST'])]
    public function approveProductSeo(Request $request, Context $context): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->toArray();
        $languageId = $data['languageId'] ?? null;
        $records = $data['records'] ?? null;

        if (!\is_string($languageId) || !\is_array($records)) {
            return new JsonResponse(['error' => ['message' => 'languageId and records are required.']], 422);
        }

        $updates = [];

        foreach ($records as $record) {
            if (!\is_array($record) || !\is_string($record['reference'] ?? null) || !\is_array($record['metadata'] ?? null)) {
                continue;
            }

            $metadata = $record['metadata'];
            $keywords = $metadata['focus_keywords'] ?? [];
            $updates[] = [
                'id' => str_replace('product:', '', $record['reference']),
                'translations' => [[
                    'languageId' => $languageId,
                    'metaTitle' => (string) ($metadata['seo_title'] ?? ''),
                    'metaDescription' => (string) ($metadata['meta_description'] ?? ''),
                    'keywords' => \is_array($keywords) ? implode(', ', array_filter($keywords, 'is_string')) : '',
                ]],
            ];
        }

        if ([] === $updates) {
            return new JsonResponse(['error' => ['message' => 'No valid SEO records were supplied.']], 422);
        }

        $this->productRepository->update($updates, $context);

        return new JsonResponse(['saved' => \count($updates)]);
    }

    #[Route('/api/_action/contentflow/coverage', name: 'api.action.contentflow.coverage', methods: ['GET'])]
    public function coverage(Request $request): JsonResponse
    {
        $languageId = preg_replace('/[^a-f0-9]/i', '', (string) $request->query->get('languageId'));

        if (32 !== \strlen($languageId)) {
            return new JsonResponse(['error' => ['message' => 'Select a valid target language.']], 422);
        }

        $summary = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(DISTINCT p.id) AS total,
                    COUNT(DISTINCT CASE WHEN NULLIF(TRIM(pt.name), '') IS NOT NULL THEN p.id END) AS translated,
                    COUNT(DISTINCT CASE WHEN NULLIF(TRIM(pt.meta_title), '') IS NOT NULL AND NULLIF(TRIM(pt.meta_description), '') IS NOT NULL THEN p.id END) AS seo_complete
                FROM product p
                LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = UNHEX(?)
                WHERE p.parent_id IS NULL
                SQL,
            [$languageId],
        ) ?: [];
        $media = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(DISTINCT m.id) AS total,
                    COUNT(DISTINCT CASE WHEN NULLIF(TRIM(mt.alt), '') IS NOT NULL THEN m.id END) AS with_alt
                FROM media m
                LEFT JOIN media_translation mt ON mt.media_id = m.id AND mt.language_id = UNHEX(?)
                SQL,
            [$languageId],
        ) ?: [];
        $missing = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(p.id)) AS id, p.product_number, COALESCE(pt.name, '') AS name,
                    CASE WHEN NULLIF(TRIM(pt.name), '') IS NULL THEN 0 ELSE 1 END AS translated,
                    CASE WHEN NULLIF(TRIM(pt.meta_title), '') IS NOT NULL AND NULLIF(TRIM(pt.meta_description), '') IS NOT NULL THEN 1 ELSE 0 END AS seo_complete
                FROM product p
                LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = UNHEX(?)
                WHERE p.parent_id IS NULL
                  AND (NULLIF(TRIM(pt.name), '') IS NULL OR NULLIF(TRIM(pt.meta_title), '') IS NULL OR NULLIF(TRIM(pt.meta_description), '') IS NULL)
                ORDER BY p.product_number ASC
                LIMIT 10
                SQL,
            [$languageId],
        );

        return new JsonResponse([
            'products' => [
                'total' => (int) ($summary['total'] ?? 0),
                'translated' => (int) ($summary['translated'] ?? 0),
                'seo_complete' => (int) ($summary['seo_complete'] ?? 0),
            ],
            'media' => [
                'total' => (int) ($media['total'] ?? 0),
                'with_alt' => (int) ($media['with_alt'] ?? 0),
            ],
            'missing' => $missing,
        ]);
    }

    #[Route('/api/_action/contentflow/search/sync', name: 'api.action.contentflow.search.sync', methods: ['POST'])]
    public function syncSearchCatalog(Request $request, Context $context): JsonResponse
    {
        $data = $request->toArray();
        $criteria = (new Criteria())
            ->addAssociation('manufacturer')
            ->addAssociation('categories')
            ->addAssociation('properties.group');
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

        $result = $this->client->post('/api/v1/integrations/shopware/search/catalog', [
            'sales_channel_id' => \is_string($data['salesChannelId'] ?? null) ? $data['salesChannelId'] : 'default',
            'language' => \is_string($data['language'] ?? null) ? $data['language'] : $context->getLanguageId(),
            'documents' => $documents,
        ]);

        return new JsonResponse($result, 202);
    }

    #[Route('/api/_action/contentflow/search/analytics', name: 'api.action.contentflow.search.analytics', methods: ['GET'])]
    public function searchAnalytics(): JsonResponse
    {
        return new JsonResponse($this->client->get('/api/v1/integrations/shopware/search/analytics'));
    }

    #[Route('/api/_action/contentflow/search/test', name: 'api.action.contentflow.search.test', methods: ['POST'])]
    public function testSearch(Request $request): JsonResponse
    {
        return new JsonResponse($this->client->post('/api/v1/integrations/shopware/search', $request->toArray(), 0.6));
    }

    #[Route('/api/_action/contentflow/search/rules', name: 'api.action.contentflow.search.rules', methods: ['POST'])]
    public function saveSearchRule(Request $request): JsonResponse
    {
        return new JsonResponse($this->client->post('/api/v1/integrations/shopware/search/rules', $request->toArray()), 201);
    }

    #[Route('/api/_action/contentflow/search/rules/{id}', name: 'api.action.contentflow.search.rules.delete', methods: ['DELETE'])]
    public function deleteSearchRule(string $id): JsonResponse
    {
        return new JsonResponse($this->client->delete('/api/v1/integrations/shopware/search/rules/' . rawurlencode($id)));
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn (mixed $value): bool => \is_string($value) && '' !== $value));
    }

    /** @return list<string> */
    private function enabledProviders(mixed $providers): array
    {
        if (!\is_array($providers)) {
            return [];
        }

        $enabled = [];

        foreach ($providers as $provider) {
            if (\is_array($provider) && true === ($provider['enabled'] ?? false) && \is_string($provider['id'] ?? null)) {
                $enabled[] = $provider['id'];
            }
        }

        return array_values(array_unique($enabled));
    }
}
