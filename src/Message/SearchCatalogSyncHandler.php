<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Message;

use ContentFlow\ShopwareAi\Service\SearchCatalogSynchronizer;
use Shopware\Core\Framework\Context;

final readonly class SearchCatalogSyncHandler
{
    public function __construct(private SearchCatalogSynchronizer $synchronizer)
    {
    }

    public function __invoke(SearchCatalogSyncMessage $message): void
    {
        $this->synchronizer->sync(Context::createDefaultContext(), $message->productIds);
    }
}
