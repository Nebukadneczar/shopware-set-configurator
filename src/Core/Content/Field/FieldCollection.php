<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Field;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<FieldEntity>
 */
class FieldCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return FieldEntity::class;
    }
}
