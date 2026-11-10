// Rechte für das Modul "Produktsets". Abgesichert, damit ein Fehler hier nie das Modul selbst verhindert.
try {
    Shopware.Service('privileges').addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'catalogues',
        key: 'set_configurator',
        roles: {
            viewer: {
                privileges: [
                    'set_configurator:read',
                    'set_configurator_slot:read',
                    'set_configurator_option:read',
                    'set_configurator_option_field:read',
                    'set_configurator_product:read',
                    'set_configurator_target:read',
                    'product:read',
                    'category:read',
                    'product_manufacturer:read',
                ],
                dependencies: [],
            },
            editor: {
                privileges: [
                    'set_configurator:update',
                    'set_configurator_slot:create',
                    'set_configurator_slot:update',
                    'set_configurator_option:create',
                    'set_configurator_option:update',
                    'set_configurator_option_field:create',
                    'set_configurator_option_field:update',
                    'set_configurator_product:create',
                    'set_configurator_product:update',
                    'set_configurator_target:create',
                    'set_configurator_target:update',
                ],
                dependencies: ['set_configurator.viewer'],
            },
            creator: {
                privileges: ['set_configurator:create'],
                dependencies: ['set_configurator.viewer', 'set_configurator.editor'],
            },
            deleter: {
                privileges: [
                    'set_configurator:delete',
                    'set_configurator_slot:delete',
                    'set_configurator_option:delete',
                    'set_configurator_option_field:delete',
                    'set_configurator_product:delete',
                    'set_configurator_target:delete',
                ],
                dependencies: ['set_configurator.viewer'],
            },
        },
    });
} catch (error) {
    // eslint-disable-next-line no-console
    console.error('[Set Configurator] Rechte konnten nicht registriert werden:', error);
}
