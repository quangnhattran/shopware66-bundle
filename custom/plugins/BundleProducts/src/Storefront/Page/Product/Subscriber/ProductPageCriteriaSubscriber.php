<?php declare(strict_types=1);

namespace BundleProducts\Storefront\Page\Product\Subscriber;

use Shopware\Storefront\Page\Product\ProductPageCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageCriteriaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            ProductPageCriteriaEvent::class => 'loadBundlesAssociation',
        ];
    }

    public function loadBundlesAssociation(ProductPageCriteriaEvent $event): void
    {
        $event->getCriteria()->addAssociation('bundles');
    }
}
