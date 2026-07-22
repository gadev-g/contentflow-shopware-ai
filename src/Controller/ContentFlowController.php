<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Controller;

use ContentFlow\ShopwareAi\Service\ContentFlowClient;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
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

    #[Route('/api/_action/contentflow/coverage', name: 'api.action.contentflow.coverage', methods: ['POST'])]
    public function coverage(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $languageId = $data['languageId'] ?? Defaults::LANGUAGE_SYSTEM;

        if (!\is_string($languageId) || !Uuid::isValid($languageId)) {
            return new JsonResponse(['error' => ['message' => 'A valid languageId is required.']], 422);
        }

        $liveVersion = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $systemLanguage = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $selectedLanguage = Uuid::fromHexToBytes($languageId);

        $summary = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    LOWER(HEX(language.id)) AS id,
                    locale.code,
                    (
                        SELECT COUNT(*)
                        FROM product
                        WHERE product.version_id = :liveVersion
                            AND product.parent_id IS NULL
                    ) AS totalProducts,
                    (
                        SELECT COUNT(*)
                        FROM product
                        INNER JOIN product_translation
                            ON product_translation.product_id = product.id
                            AND product_translation.product_version_id = product.version_id
                            AND product_translation.language_id = language.id
                        WHERE product.version_id = :liveVersion
                            AND product.parent_id IS NULL
                            AND NULLIF(TRIM(product_translation.name), '') IS NOT NULL
                            AND NULLIF(TRIM(product_translation.description), '') IS NOT NULL
                    ) AS translatedProducts,
                    (
                        SELECT COUNT(*)
                        FROM product
                        INNER JOIN product_translation
                            ON product_translation.product_id = product.id
                            AND product_translation.product_version_id = product.version_id
                            AND product_translation.language_id = language.id
                        WHERE product.version_id = :liveVersion
                            AND product.parent_id IS NULL
                            AND NULLIF(TRIM(product_translation.meta_title), '') IS NOT NULL
                            AND NULLIF(TRIM(product_translation.meta_description), '') IS NOT NULL
                    ) AS seoProducts,
                    (SELECT COUNT(*) FROM media) AS totalMedia,
                    (
                        SELECT COUNT(*)
                        FROM media
                        INNER JOIN media_translation
                            ON media_translation.media_id = media.id
                            AND media_translation.language_id = language.id
                        WHERE NULLIF(TRIM(media_translation.alt), '') IS NOT NULL
                            AND NULLIF(TRIM(media_translation.title), '') IS NOT NULL
                    ) AS describedMedia
                FROM language
                INNER JOIN locale ON locale.id = language.locale_id
                ORDER BY locale.code
                SQL,
            ['liveVersion' => $liveVersion],
        );

        $products = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    LOWER(HEX(product.id)) AS id,
                    product.product_number AS productNumber,
                    COALESCE(NULLIF(TRIM(product_translation.name), ''), system_translation.name, product.product_number) AS name,
                    CASE
                        WHEN NULLIF(TRIM(product_translation.name), '') IS NOT NULL
                            AND NULLIF(TRIM(product_translation.description), '') IS NOT NULL
                        THEN 1 ELSE 0
                    END AS translated,
                    CASE
                        WHEN NULLIF(TRIM(product_translation.meta_title), '') IS NOT NULL
                            AND NULLIF(TRIM(product_translation.meta_description), '') IS NOT NULL
                        THEN 1 ELSE 0
                    END AS seoComplete
                FROM product
                LEFT JOIN product_translation
                    ON product_translation.product_id = product.id
                    AND product_translation.product_version_id = product.version_id
                    AND product_translation.language_id = :selectedLanguage
                LEFT JOIN product_translation system_translation
                    ON system_translation.product_id = product.id
                    AND system_translation.product_version_id = product.version_id
                    AND system_translation.language_id = :systemLanguage
                WHERE product.version_id = :liveVersion
                    AND product.parent_id IS NULL
                ORDER BY translated ASC, seoComplete ASC, name ASC
                LIMIT 100
                SQL,
            [
                'liveVersion' => $liveVersion,
                'selectedLanguage' => $selectedLanguage,
                'systemLanguage' => $systemLanguage,
            ],
        );

        return new JsonResponse([
            'languageId' => $languageId,
            'summary' => $summary,
            'products' => $products,
        ]);
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
