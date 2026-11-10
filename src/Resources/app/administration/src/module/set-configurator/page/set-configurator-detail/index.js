import template from './set-configurator-detail.html.twig';
import './set-configurator-detail.scss';

const { Criteria } = Shopware.Data;
const { Mixin } = Shopware;

Shopware.Component.register('set-configurator-detail', {
    template,

    inject: ['repositoryFactory', 'setConfiguratorApiService'],

    mixins: [Mixin.getByName('notification')],

    props: {
        setId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            set: null,
            isLoading: false,
            isSaving: false,
            formulaErrors: {},
            formulaTimers: {},
            newProductId: null,
            newTarget: {
                type: 'category',
                categoryId: null,
                manufacturerId: null,
                includeChildren: true,
            },
            slotTemplates: ['select', 'radio', 'checkbox', 'cards'],
            optionTypes: ['product', 'static'],
            fieldTypes: ['checkbox', 'select', 'text', 'number', 'upload'],
        };
    },

    computed: {
        isCreate() {
            return !this.setId;
        },

        setRepository() {
            return this.repositoryFactory.create('set_configurator');
        },

        assignmentRepository() {
            return this.repositoryFactory.create('set_configurator_product');
        },

        // Auswahllisten für mt-select (Shopware 6.7 kennt bei sw-select-field keine <option>-Einträge mehr).
        slotTemplateOptions() {
            return this.slotTemplates.map((t) => ({ value: t, label: this.$tc(`set-configurator.templates.${t}`) }));
        },

        optionTypeOptions() {
            return this.optionTypes.map((t) => ({ value: t, label: this.$tc(`set-configurator.optionTypes.${t}`) }));
        },

        fieldTypeOptions() {
            return this.fieldTypes.map((t) => ({ value: t, label: this.$tc(`set-configurator.fieldTypes.${t}`) }));
        },

        targetTypeOptions() {
            return ['category', 'manufacturer'].map((t) => ({ value: t, label: this.$tc(`set-configurator.targetTypes.${t}`) }));
        },

        targetRepository() {
            return this.repositoryFactory.create('set_configurator_target');
        },

        canAddTarget() {
            return this.newTarget.type === 'category'
                ? !!this.newTarget.categoryId
                : !!this.newTarget.manufacturerId;
        },

        setCriteria() {
            const criteria = new Criteria();
            criteria.addAssociation('targets.category');
            criteria.addAssociation('targets.manufacturer');
            criteria.addAssociation('slots.options.fields');
            criteria.addAssociation('slots.options.product');
            criteria.addAssociation('assignments.product');
            criteria.getAssociation('slots').addSorting(Criteria.sort('position', 'ASC'));
            criteria.getAssociation('slots.options').addSorting(Criteria.sort('position', 'ASC'));
            criteria.getAssociation('slots.options.fields').addSorting(Criteria.sort('position', 'ASC'));
            return criteria;
        },

        productCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addAssociation('options.group');
            return criteria;
        },

        context() {
            return Shopware.Context.api;
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            this.isLoading = true;

            if (this.isCreate) {
                this.set = this.setRepository.create(this.context);
                this.set.active = true;
                this.isLoading = false;
                return Promise.resolve();
            }

            return this.setRepository.get(this.setId, this.context, this.setCriteria).then((set) => {
                this.set = set;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        // ---------------------------------------------------------------- Speichern

        save() {
            this.isSaving = true;

            return this.setRepository.save(this.set, this.context).then(() => {
                this.createNotificationSuccess({ message: this.$tc('set-configurator.detail.saved') });
                if (this.isCreate) {
                    this.$router.push({ name: 'set.configurator.detail', params: { id: this.set.id } });
                    return null;
                }
                return this.load();
            }).catch((error) => {
                this.createNotificationError({ message: this.errorMessage(error) });
            }).finally(() => {
                this.isSaving = false;
            });
        },

        errorMessage(error) {
            const errors = error?.response?.data?.errors;
            if (Array.isArray(errors) && errors.length) {
                return errors.map((e) => e.detail || e.title).join('\n');
            }
            return this.$tc('set-configurator.detail.saveError');
        },

        // ---------------------------------------------------------------- Slots, Optionen, Felder

        childRepository(collection) {
            return this.repositoryFactory.create(collection.entity, collection.source);
        },

        addSlot() {
            const slot = this.childRepository(this.set.slots).create(this.context);
            slot.name = '';
            slot.template = 'select';
            slot.minSelect = 0;
            slot.maxSelect = null;
            slot.position = this.set.slots.length;
            this.set.slots.add(slot);
        },

        removeSlot(slot) {
            this.set.slots.remove(slot.id);
        },

        addOption(slot) {
            const option = this.childRepository(slot.options).create(this.context);
            option.type = 'product';
            option.quantityMin = 1;
            option.quantityMax = 1;
            option.preselected = false;
            option.productVersionId = this.context.liveVersionId;
            option.position = slot.options.length;
            slot.options.add(option);
        },

        removeOption(slot, option) {
            slot.options.remove(option.id);
        },

        onOptionType(option, type) {
            option.type = type;
            if (type === 'static') {
                option.productId = null;
                option.product = null;
            }
        },

        addField(option) {
            const field = this.childRepository(option.fields).create(this.context);
            field.type = 'text';
            field.name = '';
            field.label = '';
            field.required = false;
            field.position = option.fields.length;
            option.fields.add(field);
        },

        removeField(option, field) {
            option.fields.remove(field.id);
        },

        // ---------------------------------------------------------------- Textfelder für Listen

        /** Auswahlfeld-Werte: je Zeile "wert|Beschriftung|Zahl" (Zahl = Wert für Preisformeln). */
        choicesText(field) {
            return (field.choices || [])
                .map((c) => [c.value, c.label ?? '', c.number ?? ''].join('|'))
                .join('\n');
        },

        setChoices(field, text) {
            const rows = [];
            String(text).split('\n').forEach((line) => {
                const parts = line.split('|');
                const value = (parts[0] || '').trim();
                if (!value) {
                    return;
                }
                const row = { value, label: (parts[1] || '').trim() || value };
                const num = (parts[2] || '').trim().replace(',', '.');
                if (num !== '' && !Number.isNaN(Number(num))) {
                    row.number = Number(num);
                }
                rows.push(row);
            });
            field.choices = rows.length ? rows : null;
        },

        /** Eigene Werte der Option: je Zeile "schluessel=zahl" (in Formeln als option.schluessel). */
        customValuesText(option) {
            return Object.entries(option.customValues || {}).map(([k, v]) => `${k}=${v}`).join('\n');
        },

        setCustomValues(option, text) {
            const values = {};
            String(text).split('\n').forEach((line) => {
                const [k, v] = line.split('=');
                const key = (k || '').trim();
                const num = Number(String(v ?? '').trim().replace(',', '.'));
                if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(key) && !Number.isNaN(num)) {
                    values[key] = num;
                }
            });
            option.customValues = Object.keys(values).length ? values : null;
        },

        // ---------------------------------------------------------------- Formeln

        onFormula(entity, value) {
            entity.priceFormula = value || null;
            this.scheduleFormulaCheck(entity.id, value);
        },

        scheduleFormulaCheck(key, value) {
            window.clearTimeout(this.formulaTimers[key]);
            this.formulaTimers[key] = window.setTimeout(() => {
                this.setConfiguratorApiService.validateFormula(value || '').then((result) => {
                    this.formulaErrors = { ...this.formulaErrors, [key]: result.valid ? null : result.error };
                }).catch(() => {
                    this.formulaErrors = { ...this.formulaErrors, [key]: null };
                });
            }, 400);
        },

        // ---------------------------------------------------------------- Produktzuweisung

        assignProduct(productId) {
            if (!productId || this.isCreate) {
                return Promise.resolve();
            }

            const assignment = this.assignmentRepository.create(this.context);
            assignment.setId = this.set.id;
            assignment.productId = productId;
            assignment.productVersionId = this.context.liveVersionId;

            return this.assignmentRepository.save(assignment, this.context).then(() => {
                this.newProductId = null;
                return this.load();
            }).catch((error) => {
                this.createNotificationError({ message: this.errorMessage(error) });
            });
        },

        removeAssignment(assignment) {
            return this.assignmentRepository.delete(assignment.id, this.context).then(() => this.load());
        },

        // ---------------------------------------------------------------- Zuweisung nach Kategorie / Hersteller

        addTarget() {
            if (!this.canAddTarget || this.isCreate) {
                return Promise.resolve();
            }

            const target = this.targetRepository.create(this.context);
            target.setId = this.set.id;
            target.type = this.newTarget.type;
            if (this.newTarget.type === 'category') {
                target.categoryId = this.newTarget.categoryId;
                target.categoryVersionId = this.context.liveVersionId;
                target.includeChildren = !!this.newTarget.includeChildren;
            } else {
                target.manufacturerId = this.newTarget.manufacturerId;
                target.manufacturerVersionId = this.context.liveVersionId;
                target.includeChildren = false;
            }

            return this.targetRepository.save(target, this.context).then(() => {
                this.newTarget.categoryId = null;
                this.newTarget.manufacturerId = null;
                return this.load();
            }).catch((error) => {
                this.createNotificationError({ message: this.errorMessage(error) });
            });
        },

        removeTarget(target) {
            return this.targetRepository.delete(target.id, this.context).then(() => this.load());
        },

        targetLabel(target) {
            if (target.type === 'category') {
                const name = target.category?.translated?.name || target.category?.name || target.categoryId;
                const suffix = target.includeChildren ? ` (${this.$tc('set-configurator.detail.withChildren')})` : '';
                return `${this.$tc('set-configurator.targetTypes.category')}: ${name}${suffix}`;
            }
            const name = target.manufacturer?.translated?.name || target.manufacturer?.name || target.manufacturerId;
            return `${this.$tc('set-configurator.targetTypes.manufacturer')}: ${name}`;
        },

        productLabel(assignment) {
            const p = assignment.product;
            if (!p) {
                return assignment.productId;
            }
            return `${p.productNumber} – ${p.translated?.name || p.name || ''}`;
        },
    },
});
