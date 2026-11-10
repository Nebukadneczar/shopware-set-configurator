<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Assignment;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AssignmentEntity>
 */
class AssignmentCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AssignmentEntity::class;
    }
}
