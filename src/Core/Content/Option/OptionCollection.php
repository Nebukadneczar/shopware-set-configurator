<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Option;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<OptionEntity>
 */
class OptionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return OptionEntity::class;
    }
}
