<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Product;

use SetConfigurator\Core\Content\Assignment\AssignmentDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductExtension extends EntityExtension
{
    public function getEntityName(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToManyAssociationField('setConfiguratorAssignments', AssignmentDefinition::class, 'product_id', 'id'))
                ->addFlags(new CascadeDelete())
        );
    }
}
