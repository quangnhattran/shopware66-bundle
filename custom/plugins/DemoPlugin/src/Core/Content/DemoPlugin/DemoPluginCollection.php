<?php declare(strict_types=1);

namespace DemoPlugin\Core\Content\DemoPlugin;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(DemoPluginEntity $entity)
 * @method void set(string $key, DemoPluginEntity $entity)
 * @method DemoPluginEntity[] getIterator()
 * @method DemoPluginEntity[] getElements()
 * @method DemoPluginEntity|null get(string $key)
 * @method DemoPluginEntity|null first()
 * @method DemoPluginEntity|null last()
 */
class DemoPluginCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return DemoPluginEntity::class;
    }
}
