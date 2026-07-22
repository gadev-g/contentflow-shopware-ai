<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Subscriber;

use ContentFlow\ShopwareAi\Message\SearchCatalogSyncMessage;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ProductSearchIndexSubscriber implements EventSubscriberInterface
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten'];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        $ids = array_values(array_filter($event->getIds(), 'is_string'));

        if ([] !== $ids) {
            $this->bus->dispatch(new SearchCatalogSyncMessage($ids));
        }
    }
}
