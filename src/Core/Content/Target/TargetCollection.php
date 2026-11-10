<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Target;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<TargetEntity>
 */
class TargetCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TargetEntity::class;
    }
}
