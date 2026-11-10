<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Slot;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<SlotEntity>
 */
class SlotCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SlotEntity::class;
    }
}
