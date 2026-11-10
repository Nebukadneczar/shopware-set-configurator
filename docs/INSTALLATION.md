# Installation und erster Test

Bitte zuerst auf einem **Testsystem** und mit **Datenbanksicherung**.

## 1. Installieren

**Aus dem Release-ZIP** (enthält die gebauten Admin- und Storefront-Dateien):
1. Admin → Erweiterungen → Meine Erweiterungen → „Erweiterung hochladen“ → ZIP wählen.
2. Installieren und aktivieren (bei einem Update: „Aktualisieren“).
3. Im Shop-Verzeichnis:
   ```bash
   bin/console assets:install
   bin/console theme:compile
   bin/console cache:clear
   ```

**Aus dem Quellcode** (Entwickler):
```bash
# Ordner nach custom/plugins/SetConfigurator kopieren, dann:
bin/console plugin:refresh
bin/console plugin:install --activate SetConfigurator
bin/console cache:clear
bin/build-administration.sh
bin/build-storefront.sh
bin/console cache:clear
```
Der Build braucht Node.js. Wenn der Server keinen Node hat, im Entwicklungssystem bauen und die Verzeichnisse `src/Resources/public` und `src/Resources/app/storefront/dist` in das Plugin des Zielshops kopieren; dort dann `assets:install`, `theme:compile` und `cache:clear`.

Nach dem Aktivieren erscheint unter **Kataloge** der Eintrag **Produktsets**. Bei Problemen: Browser-Konsole (F12) – das Plugin meldet sich dort mit `[Set Configurator] Administration geladen`.

## 2. Beispiel-Set importieren
1. `docs/beispiel-set.json` öffnen und `TESTPRODUKT-1`, `-2`, `-3` durch echte Produktnummern ersetzen.
2. Produktsets → **Importieren** → Datei wählen → Prüfung bestätigen.
3. Die Produktseite von `TESTPRODUKT-1` öffnen: Der Konfigurator erscheint vor dem Kaufformular.

## 3. Prüfliste

| # | Prüfung | Erwartung |
|---|---|---|
| 1 | Admin: Kataloge → Produktsets | Liste mit den importierten Sets |
| 2 | Formel `price *` eintippen | Fehlermeldung unter dem Feld; `price * 0.9` ohne Meldung |
| 3 | Produktseite des zugewiesenen Produkts | Konfigurator sichtbar, normales Kaufformular ausgeblendet |
| 4 | Box „Klein“ (vorgewählt) | Gesamtpreis = Produktpreis + 4,90 |
| 5 | Box „Groß“ + „Mit Gravur“ | Gesamtpreis = Produktpreis + 12,50 + 4,90; Feld „Gravurtext“ erscheint |
| 6 | Inhalt: dritte Wahl (max. 2) | weitere Karten sind gesperrt |
| 7 | Pflichtfeld „Verpackung“ leer | Hinweis, Knopf gesperrt |
| 8 | „Set in den Warenkorb“ | Meldung, Position im Warenkorb mit Auswahl unter dem Namen |
| 9 | Gleiches Set nochmals | Menge der Position steigt |
| 10 | Bestellung abschließen | Auswahl in Bestellbestätigung und Admin-Bestellung sichtbar; Lager der Optionsprodukte verringert |
| 11 | Bestellung stornieren | Lager wieder zurückgebucht (nur einmal) |
| 12 | Import derselben Datei erneut | Prüfung: „0 neu, 2 ersetzt“ |
| 13 | Set einer Kategorie/einem Hersteller zuweisen | Konfigurator erscheint bei Produkten dieser Kategorie/dieses Herstellers |

## 4. Deinstallieren
`bin/console plugin:uninstall SetConfigurator` löscht die Set-Daten; mit `--keep-user-data` bleiben sie erhalten.
