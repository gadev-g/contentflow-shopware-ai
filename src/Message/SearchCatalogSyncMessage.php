<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final readonly class SearchCatalogSyncMessage implements AsyncMessageInterface
{
    /** @param list<string> $productIds */
    public function __construct(public array $productIds)
    {
    }
}
