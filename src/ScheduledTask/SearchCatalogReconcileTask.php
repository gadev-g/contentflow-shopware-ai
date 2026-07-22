<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class SearchCatalogReconcileTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'contentflow.search_catalog_reconcile';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }
}
