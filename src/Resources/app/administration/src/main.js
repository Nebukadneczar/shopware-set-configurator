import './service/set-configurator-api.service';
// Das Modul wird vor den Rechten geladen: ein Fehler in den Rechten darf das Menü nicht verhindern.
import './module/set-configurator';
import './acl';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

// Kontrollmeldung: in der Browser-Konsole (F12) sichtbar, wenn dieses Skript geladen wurde.
// eslint-disable-next-line no-console
console.info('[Set Configurator] Administration geladen (Version 0.7.0)');
