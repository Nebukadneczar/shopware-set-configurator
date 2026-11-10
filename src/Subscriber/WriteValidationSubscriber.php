<?php

declare(strict_types=1);

namespace SetConfigurator\Subscriber;

use SetConfigurator\Core\Content\Field\FieldDefinition;
use SetConfigurator\Core\Content\Option\OptionDefinition;
use SetConfigurator\Core\Content\Set\SetDefinition;
use SetConfigurator\Core\Content\Slot\SlotDefinition;
use SetConfigurator\Core\Content\Target\TargetDefinition;
use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Formula\FormulaException;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Verhindert beim Speichern in der Verwaltung (und über die API) ungültige Daten:
 * Formeln mit Syntaxfehlern, unbekannte Vorlagen/Typen, Min > Max.
 */
class WriteValidationSubscriber implements EventSubscriberInterface
{
    private const FORMULA_ENTITIES = [
        SetDefinition::ENTITY_NAME,
        OptionDefinition::ENTITY_NAME,
        FieldDefinition::ENTITY_NAME,
    ];

    public function __construct(private readonly FormulaEvaluator $formula)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'onPreWrite'];
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) {
                continue;
            }

            $problems = $this->check($command);
            foreach ($problems as $property => $message) {
                $this->fail($event->getExceptions(), $command, $property, $message);
            }
        }
    }

    /**
     * @return array<string, string> Eigenschaft => Meldung
     */
    private function check(WriteCommand $command): array
    {
        $entity = $command->getEntityName();
        $payload = $command->getPayload();
        $problems = [];

        if (\in_array($entity, self::FORMULA_ENTITIES, true)
            && isset($payload['price_formula'])
            && trim((string) $payload['price_formula']) !== ''
        ) {
            try {
                $this->formula->validate((string) $payload['price_formula']);
            } catch (FormulaException $e) {
                $problems['priceFormula'] = 'Ungültige Preisformel: ' . $e->getMessage();
            }
        }

        if ($entity === SlotDefinition::ENTITY_NAME) {
            if (isset($payload['template']) && !\in_array($payload['template'], SlotDefinition::TEMPLATES, true)) {
                $problems['template'] = 'Unbekannte Vorlage.';
            }
            if (isset($payload['min_select'], $payload['max_select']) && $payload['max_select'] < $payload['min_select']) {
                $problems['maxSelect'] = 'Das Maximum darf nicht kleiner als das Minimum sein.';
            }
        }

        if ($entity === OptionDefinition::ENTITY_NAME) {
            if (isset($payload['type']) && !\in_array($payload['type'], [OptionDefinition::TYPE_PRODUCT, OptionDefinition::TYPE_STATIC], true)) {
                $problems['type'] = 'Unbekannter Optionstyp.';
            }
            if (isset($payload['quantity_min'], $payload['quantity_max']) && $payload['quantity_max'] < $payload['quantity_min']) {
                $problems['quantityMax'] = 'Die Höchstmenge darf nicht kleiner als die Mindestmenge sein.';
            }
            if (isset($payload['quantity_min']) && $payload['quantity_min'] < 0) {
                $problems['quantityMin'] = 'Die Menge darf nicht negativ sein.';
            }
        }

        if ($entity === TargetDefinition::ENTITY_NAME && $command instanceof InsertCommand) {
            $type = $payload['type'] ?? null;
            if ($type === TargetDefinition::TYPE_CATEGORY && empty($payload['category_id'])) {
                $problems['categoryId'] = 'Bitte eine Kategorie wählen.';
            } elseif ($type === TargetDefinition::TYPE_MANUFACTURER && empty($payload['manufacturer_id'])) {
                $problems['manufacturerId'] = 'Bitte einen Hersteller wählen.';
            } elseif ($type !== TargetDefinition::TYPE_CATEGORY && $type !== TargetDefinition::TYPE_MANUFACTURER) {
                $problems['type'] = 'Unbekannter Zuweisungstyp.';
            }
        }

        if ($entity === FieldDefinition::ENTITY_NAME) {
            if (isset($payload['type']) && !\in_array($payload['type'], FieldDefinition::TYPES, true)) {
                $problems['type'] = 'Unbekannter Feldtyp.';
            }
            if (isset($payload['name']) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $payload['name']) !== 1) {
                $problems['name'] = 'Der Feldname darf nur Buchstaben, Ziffern und "_" enthalten und nicht mit einer Ziffer beginnen.';
            }
        }

        return $problems;
    }

    private function fail(WriteException $exceptions, WriteCommand $command, string $property, string $message): void
    {
        $violation = new ConstraintViolation($message, $message, [], null, '/' . $property, null);

        $exceptions->add(new WriteConstraintViolationException(new ConstraintViolationList([$violation]), $command->getPath()));
    }
}
