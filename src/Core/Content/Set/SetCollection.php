<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Set;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<SetEntity>
 */
class SetCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SetEntity::class;
    }
}
