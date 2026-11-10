<?php

declare(strict_types=1);

namespace SetConfigurator\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Hinweis im Warenkorb, wenn eine Set-Konfiguration nicht (mehr) gültig oder nicht lieferbar ist.
 * Die Position wird in diesem Fall aus dem Warenkorb entfernt.
 */
class SetConfigurationError extends Error
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $lineItemId,
        private readonly string $reason,
        private readonly array $parameters = []
    ) {
        $this->message = sprintf('Set-Konfiguration %s entfernt: %s', $lineItemId, $reason);

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return sprintf('set-configurator-%s-%s', $this->reason, $this->lineItemId);
    }

    public function getMessageKey(): string
    {
        return 'set-configurator-configuration-' . $this->reason;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function isPersistent(): bool
    {
        return false;
    }

    public function getParameters(): array
    {
        return ['id' => $this->lineItemId] + $this->parameters;
    }
}
