// Das Stylesheet (scss/base.scss) lädt Shopware beim Theme-Kompilieren automatisch.
// Es darf hier NICHT importiert werden, sonst bricht der Webpack-Build ab.
import SetConfigurator from './plugin/set-configurator.plugin';

window.PluginManager.register('SetConfigurator', SetConfigurator, '[data-set-configurator]');
