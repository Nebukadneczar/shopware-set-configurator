import './page/set-configurator-list';
import './page/set-configurator-detail';

Shopware.Module.register('set-configurator', {
    type: 'plugin',
    name: 'set-configurator',
    title: 'set-configurator.general.title',
    description: 'set-configurator.general.description',
    color: '#0b5ed7',
    icon: 'regular-products',

    routes: {
        index: {
            component: 'set-configurator-list',
            path: 'index',
            meta: { privilege: 'set_configurator.viewer' },
        },
        create: {
            component: 'set-configurator-detail',
            path: 'create',
            meta: { parentPath: 'set.configurator.index', privilege: 'set_configurator.creator' },
        },
        detail: {
            component: 'set-configurator-detail',
            path: 'detail/:id',
            meta: { parentPath: 'set.configurator.index', privilege: 'set_configurator.viewer' },
            props: { default: (route) => ({ setId: route.params.id }) },
        },
    },

    navigation: [
        {
            id: 'set-configurator',
            label: 'set-configurator.general.title',
            color: '#0b5ed7',
            icon: 'regular-products',
            path: 'set.configurator.index',
            parent: 'sw-catalogue',
            position: 80,
            privilege: 'set_configurator.viewer',
        },
    ],
});
