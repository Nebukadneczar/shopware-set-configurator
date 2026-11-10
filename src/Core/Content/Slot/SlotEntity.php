<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Slot;

use SetConfigurator\Core\Content\Option\OptionCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SlotEntity extends Entity
{
    use EntityIdTrait;

    protected string $setId = '';

    protected string $name = '';

    protected ?string $description = null;

    protected string $template = 'select';

    protected int $minSelect = 0;

    protected ?int $maxSelect = null;

    protected int $position = 0;

    protected ?OptionCollection $options = null;

    public function getSetId(): string
    {
        return $this->setId;
    }

    public function setSetId(string $setId): void
    {
        $this->setId = $setId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplate(string $template): void
    {
        $this->template = $template;
    }

    public function getMinSelect(): int
    {
        return $this->minSelect;
    }

    public function setMinSelect(int $minSelect): void
    {
        $this->minSelect = $minSelect;
    }

    public function getMaxSelect(): ?int
    {
        return $this->maxSelect;
    }

    public function setMaxSelect(?int $maxSelect): void
    {
        $this->maxSelect = $maxSelect;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getOptions(): ?OptionCollection
    {
        return $this->options;
    }

    public function setOptions(?OptionCollection $options): void
    {
        $this->options = $options;
    }
}
