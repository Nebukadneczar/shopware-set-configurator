# Set-Konfigurator für Shopware 6 · Set Configurator

Kunden stellen Produktsets selbst zusammen: Sets mit Slots, Optionen (Produkt oder statisch), Mengen, Zusatzfeldern, verschachtelten Sets und dynamischer Preisformel. Zuweisung an einzelne Produkte, Kategorien oder Hersteller. Verwaltung im Shopware-Admin, Konfigurator auf der Produktseite, Preis- und Lagerprüfung im Warenkorb.

Customers configure product sets themselves: sets with slots, options (product or static), quantities, extra fields, nested sets and dynamic price formulas. Assign a set to products, categories or manufacturers. Admin module, configurator on the product page, price and stock checks in the cart. **Shopware 6.7**, PHP 8.2+. License: **MIT**.

> **Status: BETA.** Das Plugin ist in einem Testshop (Shopware 6.7.15) installiert, die Administration lädt und die Slot-/Optionsmaske funktioniert. **Noch nicht ausführlich geprüft** sind: Warenkorb und Bestellung, Lagerbuchung (auch Storno), Datei-Upload, Import/Export und die Zuweisung nach Kategorie/Hersteller. Die 68 PHPUnit-Tests (Formel, Validierung, Preis, Austauschformat, Rangfolge) laufen durch. Bitte nur auf einem Testsystem einsetzen und vorher die Datenbank sichern. Nutzung auf eigene Verantwortung (siehe Lizenz). Fehler bitte als [Issue](https://github.com/Nebukadneczar/shopware-set-configurator/issues) melden.
>
> **Status: BETA.** Installed in a test shop (Shopware 6.7.15); the admin loads and the slot/option form works. Cart, orders, stock handling (incl. cancellation), upload, import/export and category/manufacturer assignment are **not yet thoroughly tested**. The 68 PHPUnit tests (formulas, validation, pricing, exchange format, precedence) pass. Use on a test system only and back up your database first.

## Funktionen
- **Sets** mit **Slots** (Auswahlliste, Optionsfelder, Checkboxen oder Karten), Mindest-/Höchstanzahl je Slot
- **Optionen**: Produkt (bei Varianten: Elternprodukt = Kunde wählt die Variante, Variante = feste Vorauswahl) oder statische Option; Menge von/bis (Min = Max = fester Multiplikator); vorausgewählt; verschachtelte Sets
- **Zusatzfelder** je Option: Checkbox, Auswahlfeld, Textfeld, Nummernfeld, Uploadfeld (Pflicht, Preisformel)
- **Preisformeln** auf Set-, Options- und Feldebene (sichere eigene Formelsprache, kein `eval`)
- **Zuweisung** an Produkte (Vererbung auf Varianten), Kategorien (optional mit Unterkategorien) und Hersteller
- Live-Preis auf der Produktseite, serverseitige Prüfung und Preisberechnung bei jeder Warenkorb-Berechnung
- Lagerprüfung der Optionsprodukte (Abverkaufsartikel), Abbuchung bei Bestellung, Rückbuchung bei Storno
- Anzeige der Auswahl in Warenkorb, Bestellbestätigung und Bestellungen
- Export/Import aller Sets als JSON (Produkte über die Produktnummer, mit Trockenlauf)
- Farben im Shop über die Plugin-Konfiguration einstellbar; Texte de/en

## Installation
1. ZIP aus dem Release herunterladen (Ordner `SetConfigurator` im ZIP; die gebauten Admin-/Storefront-Dateien sind enthalten), im Admin unter Erweiterungen → Meine Erweiterungen → „Erweiterung hochladen“, installieren und aktivieren. Bei einem Update zusätzlich „Aktualisieren“.
2. `bin/console assets:install`, `bin/console theme:compile` und `bin/console cache:clear`.
3. Menü: **Kataloge → Produktsets**. Farben: Erweiterungen → Set-Konfigurator → Konfiguration.

Aus dem Quellcode (Entwickler): Ordner nach `custom/plugins/SetConfigurator` legen, `bin/console plugin:refresh`, `plugin:install --activate SetConfigurator`, dann `bin/build-administration.sh` und `bin/build-storefront.sh`. Details: [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Datenmodell

| Tabelle | Inhalt |
|---|---|
| `set_configurator` | Set (Name, aktiv, optionale Set-Preisformel) |
| `set_configurator_slot` | Slot: Vorlage (`select`, `radio`, `checkbox`, `cards`), Min/Max Auswahl, Reihenfolge |
| `set_configurator_option` | Option: Produkt oder statisch, Menge Min/Max, Preisformel, eigene Werte, optional verschachteltes Set |
| `set_configurator_option_field` | Zusatzfeld: `checkbox`, `select`, `text`, `number`, `upload`, mit Preisformel |
| `set_configurator_product` | Zuweisung Set → Produkt (ein Set je Produkt) |
| `set_configurator_target` | Zuweisung Set → Kategorie oder Hersteller |

## Zuweisung und Rangfolge
1. Zuweisung direkt am Produkt, dann am Elternprodukt (Vererbung auf Varianten)
2. Kategorie (Produkt direkt in der Kategorie vor einer Oberkategorie, wenn „Unterkategorien einbeziehen“ gesetzt ist)
3. Hersteller

Bei Gleichstand gewinnt das früher angelegte Set. Zuweisungen nach Kategorie/Hersteller sind nicht Teil von Export/Import (die IDs sind shop-spezifisch). Dynamische Produktgruppen und Eigenschaften werden nicht unterstützt.

## Preisformeln

Zahlen, `+ - * /`, Klammern, Vergleiche, `&& || !` und die Funktionen `min max round floor ceil abs if`.

| Ebene | Variablen |
|---|---|
| Option | `price`, `quantity`, `base`, `nested`, `option.<schlüssel>`, `product.<schlüssel>`, `field.<feldname>` |
| Zusatzfeld | wie Option, plus `value` (Checkbox 1/0, Zahl, Auswahl = `number` der Wahl, Text/Upload 1 wenn gefüllt) |
| Set | `base`, `options`, `price` |

Beispiele: `price * 0.9` (10 % Rabatt), `price + 12.5`, `if(quantity >= 3, price * 0.8, price)`.
Ohne Formel: Produktoption = Produktpreis, statische Option = 0; Gesamtpreis = Grundpreis + Summe der Optionen. Preise in Formeln gelten im Preismodus des Shops (Brutto-Shop: Brutto-Werte).

## Warenkorb und API
Das Haupt-Produkt bleibt eine normale Produktposition; die Auswahl liegt im Payload (`setConfiguratorSelection`). Die Positions-ID ist ein Fingerabdruck der Auswahl (gleiche Konfiguration = Menge wird erhöht). Der `SetCartProcessor` läuft nach dem Produkt-Processor, ersetzt den Einzelpreis durch den Set-Preis und entfernt ungültige oder nicht lieferbare Positionen mit Hinweis.

- `POST /store-api/set-configurator/{productId}/configure` – Vorschau (Prüfung, Preis, Zusammenfassung); `…/add` – in den Warenkorb
- Storefront-AJAX: `/set-configurator/{productId}/configure|add`, `/set-configurator/upload`
- Admin-API: `POST /api/_action/set-configurator/validate-formula`, `GET …/export`, `POST …/import`
- Body: `{ "selection": { "slots": { "<slotId>": [ { "optionId": "…", "quantity": 1, "productId": "<variantId>", "fields": { "<fieldId>": "…" }, "nested": { … } } ] } }, "quantity": 1 }`

## Anpassen
- Vorlagen je Slot: `src/Resources/views/storefront/set-configurator/slot/` (`select`, `radio`, `checkbox`, `cards`); alle Twig-Blöcke sind überschreibbar
- Anzeige im Warenkorb: Erweiterung von `component/line-item/element/label.html.twig` (Block `component_line_item_label`)
- **Bestell-Mails:** Die Auswahl steht im Positions-Payload. In den Mail-Vorlagen innerhalb der Positionsschleife ergänzen:

```twig
{% if lineItem.payload.setConfiguratorSummary is defined %}
    {% for row in lineItem.payload.setConfiguratorSummary %}
        - {{ row.slot }}: {{ row.quantity }}x {{ row.label }}{% for f in row.fields %} ({{ f.label }}: {{ f.value }}){% endfor %}
    {% endfor %}
{% endif %}
```

## Bekannte Einschränkungen
- Wird eine stornierte Bestellung wieder geöffnet, wird der Bestand der Optionsprodukte nicht erneut abgebucht
- Set-, Slot- und Optionsnamen sind nicht übersetzbar (Oberflächentexte de/en)
- Sortierung per Positionsfeld, kein Drag & Drop; keine Produktbilder bei Optionen

## Tests
```bash
composer install
vendor/bin/phpunit
```
Oder im Shop: `vendor/bin/phpunit -c custom/plugins/SetConfigurator/phpunit.shop.xml` (benötigt PHPUnit im Shop). Die Tests laufen ohne Datenbank (zuletzt: 68 Tests, PHP 8.2, Shopware 6.7.15).

## Lizenz
MIT, siehe [LICENSE](LICENSE). Programmierung: Markus Czarnetzki, [macdata.de](https://macdata.de).
