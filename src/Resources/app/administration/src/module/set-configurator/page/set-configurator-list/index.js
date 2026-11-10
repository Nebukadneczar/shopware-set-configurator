import template from './set-configurator-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('set-configurator-list', {
    template,

    inject: ['repositoryFactory', 'setConfiguratorApiService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            items: null,
            isLoading: true,
            importData: null,
            importCheck: null,
            isImporting: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('set_configurator');
        },

        columns() {
            return [
                { property: 'name', label: 'set-configurator.list.name', routerLink: 'set.configurator.detail', primary: true, allowResize: true },
                { property: 'active', label: 'set-configurator.list.active', inlineEdit: 'boolean', allowResize: true },
                { property: 'createdAt', label: 'set-configurator.list.created', allowResize: true },
            ];
        },
    },

    created() {
        this.loadItems();
    },

    methods: {
        onExport() {
            return this.setConfiguratorApiService.exportSets().catch(() => {
                this.createNotificationError({ message: this.$tc('set-configurator.transfer.exportError') });
            });
        },

        onFileSelected(event) {
            const file = event.target.files && event.target.files[0];
            event.target.value = '';
            if (!file) {
                return Promise.resolve();
            }

            return file.text().then((text) => {
                this.importData = JSON.parse(text);
                // Erst nur prüfen (Trockenlauf), dann nach Bestätigung importieren.
                return this.setConfiguratorApiService.importSets(this.importData, true);
            }).then((check) => {
                this.importCheck = check;
            }).catch(() => {
                this.importData = null;
                this.createNotificationError({ message: this.$tc('set-configurator.transfer.invalidFile') });
            });
        },

        closeImport() {
            this.importData = null;
            this.importCheck = null;
        },

        confirmImport() {
            this.isImporting = true;

            return this.setConfiguratorApiService.importSets(this.importData, false).then((result) => {
                if (result.ok) {
                    this.createNotificationSuccess({ message: this.$tc('set-configurator.transfer.done') });
                    this.closeImport();
                    return this.loadItems();
                }
                this.importCheck = result;
                return null;
            }).catch(() => {
                this.createNotificationError({ message: this.$tc('set-configurator.transfer.importError') });
            }).finally(() => {
                this.isImporting = false;
            });
        },

        loadItems() {
            this.isLoading = true;
            const criteria = new Criteria(1, 50);
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return this.repository.search(criteria, Shopware.Context.api).then((result) => {
                this.items = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },
    },
});
