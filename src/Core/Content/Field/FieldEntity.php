<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Field;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class FieldEntity extends Entity
{
    use EntityIdTrait;

    protected string $optionId = '';

    protected string $type = FieldDefinition::TYPE_TEXT;

    protected string $name = '';

    protected string $label = '';

    protected bool $required = false;

    /**
     * Für Auswahlfelder: Liste von {value, label, number?}-Einträgen.
     * "number" ist der Zahlenwert, den eine Preisformel als field.<name> sieht.
     *
     * @var array<int, array{value: string, label?: string, number?: int|float}>|null
     */
    protected ?array $choices = null;

    protected ?string $priceFormula = null;

    protected int $position = 0;

    public function getOptionId(): string
    {
        return $this->optionId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** @return array<int, array{value: string, label?: string, number?: int|float}> */
    public function getChoices(): array
    {
        return $this->choices ?? [];
    }

    public function getPriceFormula(): ?string
    {
        return $this->priceFormula;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
