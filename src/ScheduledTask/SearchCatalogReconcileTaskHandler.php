<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\ScheduledTask;

use ContentFlow\ShopwareAi\Service\SearchCatalogSynchronizer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: SearchCatalogReconcileTask::class)]
final class SearchCatalogReconcileTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly SearchCatalogSynchronizer $synchronizer,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->synchronizer->sync(Context::createDefaultContext());
    }
}
