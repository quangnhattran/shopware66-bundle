<?php declare(strict_types=1);

namespace BundleProducts\Core\Checkout\Cart;

use BundleProducts\Core\Content\Bundle\BundleCollection;
use BundleProducts\Core\Content\Bundle\BundleEntity;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\LineItem\QuantityInformation;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BundleCartProcessor implements CartProcessorInterface, CartDataCollectorInterface
{
    public const TYPE = 'bundleProducts';
    public const DISCOUNT_TYPE = 'bundle-discount';
    public const DATA_KEY = 'bundle-';
    public const DISCOUNT_TYPE_ABSOLUTE = 'absolute';
    public const DISCOUNT_TYPE_PERCENTAGE = 'percentage';

    public function __construct(private readonly EntityRepository $bundleRepository, private readonly PercentagePriceCalculator $percentagePriceCalculator, private readonly AbsolutePriceCalculator $absolutePriceCalculator, private readonly QuantityPriceCalculator $quantityPriceCalculator)
    {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $bundleLineItems = $original->getLineItems()->filterType(self::TYPE);

        if (\count($bundleLineItems) === 0) {
            return;
        }

        $bundles = $this->fetchBundles($bundleLineItems, $data, $context);

        foreach ($bundles as $bundle) {
            /** @var BundleEntity $bundle */
            $data->set(self::DATA_KEY . $bundle->getId(), $bundle);
        }

        foreach ($bundleLineItems as $bundleLineItem) {
            $bundle = $data->get(self::DATA_KEY . $bundleLineItem->getReferencedId());
            $this->enrichBundle($bundleLineItem, $bundle);
            $this->addMissingProducts($bundleLineItem, $bundle);
            $this->addDiscount($bundleLineItem, $bundle, $context);
        }
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $bundleLineItems = $original->getLineItems()->filterType(self::TYPE);

        if (\count($bundleLineItems) === 0) {
            return;
        }

        foreach ($bundleLineItems as $bundleLineItem) {
            $this->calculateChildProductPrices($bundleLineItem, $context);
            $this->calculateDiscountPrice($bundleLineItem, $context);
            $bundleLineItem->setPrice($bundleLineItem->getChildren()->getPrices()->sum());
            $toCalculate->add($bundleLineItem);
        }
    }

    private function fetchBundles(LineItemCollection $bundleLineItems, CartDataCollection $data, SalesChannelContext $context): BundleCollection
    {
        $bundleIds = $bundleLineItems->getReferenceIds();
        $filtered = [];

        foreach ($bundleIds as $bundleId) {
            if ($data->has(self::DATA_KEY . $bundleId)) {
                continue;
            }

            $filtered[] = $bundleId;
        }

        if (empty($filtered)) {
            return new BundleCollection();
        }

        $criteria = new Criteria($filtered);
        $criteria->addAssociation('products.deliveryTime');
        return $this->bundleRepository->search($criteria, $context->getContext())->getEntities();
    }

    public function enrichBundle(LineItem $bundleLineItem, BundleEntity $bundle): void
    {
        if (!$bundleLineItem->getLabel()) {
            $bundleLineItem->setLabel($bundle->getTranslation('name'));
        }

        $bundleLineItem->setRemovable(true)
            ->setStackable(true)
            ->setDeliveryInformation(
                new DeliveryInformation(
                    (int) $bundle->getProducts()->first()->getStock(),
                    (float) $bundle->getProducts()->first()->getWeight(),
                    $bundle->getProducts()->first()->getShippingFree(),
                    $bundle->getProducts()->first()->getRestockTime(),
                    $bundle->getProducts()->first()->getDeliveryTime() ?
                        DeliveryTime::createFromEntity($bundle->getProducts()->first()->getDeliveryTime()) :
                        (new DeliveryTime())->assign([
                            'name' => '2 days',
                            'min' => 1,
                            'max' => 2,
                            'unit' => 'day'
                        ])
                )
            )
            ->setQuantityInformation(new QuantityInformation());
    }

    private function addMissingProducts(LineItem $bundleLineItem, BundleEntity $bundle): void
    {
        foreach ($bundle->getProducts()->getIds() as $productId) {
            if ($bundleLineItem->getChildren()->has($productId)) {
                continue;
            }

            $productLineItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);
            $bundleLineItem->addChild($productLineItem);
        }
    }

    private function addDiscount(LineItem $bundleLineItem, BundleEntity $bundle, SalesChannelContext $context): void
    {
        if ($this->getDiscount($bundleLineItem)) {
            return;
        }

        $discount = $this->createDiscount($bundle, $context);

        if ($discount) {
            $bundleLineItem->addChild($discount);
        }
    }

    private function getDiscount(LineItem $bundle): ?LineItem
    {
        return $bundle->getChildren()->get($bundle->getReferencedId() . '-discount');
    }

    private function createDiscount(BundleEntity $bundleData, SalesChannelContext $context): ?LineItem
    {
        if ($bundleData->getDiscount() === 0) {
            return null;
        }

        switch ($bundleData->getDiscountType()) {
            case self::DISCOUNT_TYPE_ABSOLUTE:
                $priceDefinition = new AbsolutePriceDefinition(-$bundleData->getDiscount(), $context->getContext()->getCurrencyPrecision());
                $label = 'Absolute bundle voucher';
                break;
            case self::DISCOUNT_TYPE_PERCENTAGE:
                $priceDefinition = new PercentagePriceDefinition(-$bundleData->getDiscount(), $context->getContext()->getCurrencyPrecision());
                $label = sprintf('Percentage bundle voucher (%s%%)', $bundleData->getDiscount());
                break;
            default:
                throw new \RuntimeException('Invalid discount type.');
        }

        $discount = new LineItem(
            $bundleData->getId() . '-discount',
            self::DISCOUNT_TYPE,
            $bundleData->getId()
        );

        $discount->setPriceDefinition($priceDefinition)
            ->setLabel($label)
            ->setGood(false);

        return $discount;
    }

    private function calculateChildProductPrices(LineItem $bundleLineItem, SalesChannelContext $context): void
    {
        $products = $bundleLineItem->getChildren()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        foreach ($products as $product) {
            $priceDefinition = $product->getPriceDefinition();
            $product->setPrice($this->quantityPriceCalculator->calculate($priceDefinition, $context));
        }
    }

    private function calculateDiscountPrice(LineItem $bundleLineItem, SalesChannelContext $context): void
    {
        $discount = $this->getDiscount($bundleLineItem);

        if (!$discount) {
            return;
        }

        $childPrices = $bundleLineItem->getChildren()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE)->getPrices();
        $priceDefinition = $discount->getPriceDefinition();

        if (!$priceDefinition) {
            return;
        }

        switch (\get_class($priceDefinition)) {
            case AbsolutePriceDefinition::class:
                $discount->setPrice($this->absolutePriceCalculator
                    ->calculate($priceDefinition->getPrice(), $childPrices, $context));
                break;
            case PercentagePriceDefinition::class:
                $discount->setPrice($this->percentagePriceCalculator
                    ->calculate($priceDefinition->getPercentage(), $childPrices, $context));
                break;
            default:
                throw new \RuntimeException('Invalid discount type.');
        }
    }
}