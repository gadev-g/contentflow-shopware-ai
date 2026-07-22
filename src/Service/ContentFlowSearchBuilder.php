<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\Adapter\Request\RequestParamHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

final readonly class ContentFlowSearchBuilder implements ProductSearchBuilderInterface
{
    public function __construct(
        private ProductSearchBuilderInterface $decorated,
        private ContentFlowClient $client,
        private LoggerInterface $logger,
    ) {
    }

    public function build(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$this->client->searchEnabled()) {
            $this->decorated->build($request, $criteria, $context);

            return;
        }

        $query = trim((string) RequestParamHelper::get($request, 'search'));
        try {
            $response = $this->client->post('/api/v1/integrations/shopware/search', [
                'query' => $query,
                'sales_channel_id' => $context->getSalesChannelId(),
                'language' => $context->getLanguageId(),
                'limit' => 100,
            ], 0.6);
            $candidates = \is_array($response['candidates'] ?? null) ? $response['candidates'] : [];
            $ids = [];

            foreach ($candidates as $candidate) {
                if (!\is_array($candidate) || !\is_string($candidate['id'] ?? null)) {
                    continue;
                }

                $ids[] = $candidate['id'];
                $criteria->addQuery(new ScoreQuery(
                    new EqualsFilter('product.id', $candidate['id']),
                    max(1.0, (float) ($candidate['score'] ?? 1.0)),
                ));
            }

            if ([] === $ids) {
                $criteria->addFilter(new EqualsAnyFilter('product.id', ['00000000000000000000000000000000']));

                return;
            }

            $criteria->addFilter(new EqualsAnyFilter('product.id', $ids));
        } catch (\Throwable $exception) {
            $this->logger->warning('ContentFlow AI Search fell back to Shopware search.', [
                'error' => $exception->getMessage(),
                'query' => $query,
            ]);
            $this->decorated->build($request, $criteria, $context);
        }
    }
}
