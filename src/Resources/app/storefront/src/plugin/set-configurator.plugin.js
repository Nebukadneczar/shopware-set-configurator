import Plugin from 'src/plugin-system/plugin.class';

/**
 * Set-Konfigurator auf der Produktseite.
 *
 * Liest die Auswahl aus dem DOM (Slots → Optionen → Variante, Menge, Zusatzfelder, verschachtelte Sets),
 * lässt sie vom Server prüfen und berechnen (Live-Preis) und legt sie in den Warenkorb.
 * Der Server prüft alles erneut; dieses Skript ist nur Bedienkomfort.
 */
export default class SetConfigurator extends Plugin {
    static options = {
        configureUrl: '',
        addUrl: '',
        uploadUrl: '',
        cartUrl: '',
        currency: 'EUR',
        messages: {},
        debounce: 250,
    };

    init() {
        this._root = this.el.querySelector(':scope > [data-sc-set]');
        this._qtyInput = this.el.querySelector('[data-sc-host-qty]');
        this._totalEl = this.el.querySelector('[data-sc-total]');
        this._messagesEl = this.el.querySelector('[data-sc-messages]');
        this._addBtn = this.el.querySelector('[data-sc-add]');
        this._successEl = this.el.querySelector('[data-sc-success]');
        this._timer = null;
        this._requestId = 0;
        this._valid = false;

        if (!this._root) {
            return;
        }

        this._hideNativeBuyForm();
        this._registerEvents();
        this._syncAll();
        this._refresh();
    }

    // ------------------------------------------------------------ Ereignisse

    _registerEvents() {
        this.el.addEventListener('change', (e) => this._onChange(e));
        this.el.addEventListener('input', (e) => {
            if (e.target.matches('input[type="number"], input[type="text"]')) {
                this._schedule();
            }
        });
        this._addBtn.addEventListener('click', () => this._add());
    }

    _onChange(event) {
        const target = event.target;
        if (target.matches('[data-sc-upload]')) {
            this._upload(target);
            return;
        }
        this._syncAll();
        this._schedule();
    }

    _schedule() {
        this._successEl.hidden = true;
        window.clearTimeout(this._timer);
        this._timer = window.setTimeout(() => this._refresh(), this.options.debounce);
    }

    // ------------------------------------------------------------ Oberfläche

    /** Blendet das normale Kauf-Formular aus, das Set hat seinen eigenen Warenkorb-Knopf. */
    _hideNativeBuyForm() {
        const form = document.getElementById('productDetailPageBuyProductForm');
        if (!form) {
            return;
        }
        if (form.contains(this.el)) {
            form.querySelectorAll('.buy-widget-container').forEach((el) => {
                if (!el.contains(this.el)) {
                    el.hidden = true;
                }
            });
        } else {
            form.hidden = true;
        }
    }

    /** Zeigt Details gewählter Optionen und erzwingt das Maximum je Slot. */
    _syncAll() {
        this.el.querySelectorAll('[data-sc-slot]').forEach((slot) => {
            const selected = this._selectedIds(slot);
            const max = slot.dataset.max === '' ? null : parseInt(slot.dataset.max, 10);

            this._options(slot).forEach((option) => {
                const id = option.dataset.optionId;
                const body = this._body(option);
                if (body) {
                    body.hidden = !selected.includes(id);
                }
                const pick = this._pick(option);
                if (pick && pick.type === 'checkbox' && max !== null) {
                    pick.disabled = !pick.checked && selected.length >= max;
                }
            });
        });
    }

    // ------------------------------------------------------------ Auswahl lesen

    _options(slot) {
        return Array.from(slot.querySelectorAll(':scope > .sc-slot-body > .sc-options > .sc-option[data-option-id]'));
    }

    _body(option) {
        return option.querySelector(':scope > .sc-option-body');
    }

    _pick(option) {
        return option.querySelector(':scope > .sc-option-head input[data-sc-pick]');
    }

    _selectedIds(slot) {
        const select = slot.querySelector(':scope > .sc-slot-body > [data-sc-select]');
        if (select) {
            return select.value ? [select.value] : [];
        }
        return this._options(slot)
            .filter((option) => {
                const pick = this._pick(option);
                return pick && pick.checked;
            })
            .map((option) => option.dataset.optionId);
    }

    /** Elemente, die direkt zu diesem Options-Körper gehören (nicht zu verschachtelten Sets). */
    _own(body, selector) {
        return Array.from(body.querySelectorAll(selector)).filter((el) => el.closest('.sc-option-body') === body);
    }

    _collectSet(setEl) {
        const slots = {};
        setEl.querySelectorAll(':scope > .sc-slots > .sc-slot').forEach((slot) => {
            const entries = this._selectedIds(slot).map((id) => {
                const option = this._options(slot).find((o) => o.dataset.optionId === id);
                return this._collectEntry(option, id);
            });
            slots[slot.dataset.slotId] = entries;
        });
        return { slots };
    }

    _collectEntry(option, id) {
        const entry = { optionId: id };
        const body = this._body(option);
        if (!body) {
            return entry;
        }

        const variant = this._own(body, '[data-sc-variant]')[0];
        if (variant && variant.value) {
            entry.productId = variant.value;
        }

        const qty = this._own(body, '[data-sc-qty]')[0];
        if (qty && qty.value !== '') {
            entry.quantity = Number(qty.value);
        }

        const fields = {};
        this._own(body, '[data-sc-field]').forEach((el) => {
            const type = el.dataset.fieldType;
            const value = type === 'checkbox' ? el.checked : el.value;
            if (value !== '' && value !== false) {
                fields[el.dataset.scField] = value;
            }
        });
        entry.fields = fields;

        const nested = body.querySelector(':scope > .sc-nested > [data-sc-set]');
        if (nested) {
            entry.nested = this._collectSet(nested);
        }

        return entry;
    }

    _payload() {
        const quantity = Math.max(1, parseInt(this._qtyInput.value, 10) || 1);
        return { selection: this._collectSet(this._root), quantity };
    }

    // ------------------------------------------------------------ Server

    async _post(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = {};
        }
        return { status: response.status, data };
    }

    async _refresh() {
        const id = ++this._requestId;
        this._addBtn.disabled = true;

        let result;
        try {
            result = await this._post(this.options.configureUrl, this._payload());
        } catch (e) {
            this._showMessages([this._msg('generic')]);
            return;
        }
        if (id !== this._requestId) {
            return; // veraltete Antwort
        }

        this._render(result.data);
    }

    _render(data) {
        this._valid = Boolean(data.valid);
        this._totalEl.textContent = data.price ? this._money(data.price.total) : '–';
        this._addBtn.disabled = !this._valid;
        this._showMessages(this._messagesFor(data));
    }

    async _add() {
        if (!this._valid) {
            return;
        }
        this._addBtn.disabled = true;
        let result;
        try {
            result = await this._post(this.options.addUrl, this._payload());
        } catch (e) {
            this._showMessages([this._msg('generic')]);
            return;
        }

        if (result.status === 200) {
            this._successEl.hidden = false;
            this._successEl.querySelector('[data-sc-success-text]').textContent = this._msg('added');
            this._showMessages([]);
            this._refreshCartWidgets();
            this._addBtn.disabled = false;
            return;
        }

        if (result.status === 409) {
            this._showMessages([this._msg('shortage')]);
        } else {
            this._render(result.data);
        }
    }

    /** Aktualisiert die Warenkorb-Anzeige im Header, falls das Standard-Plugin vorhanden ist. */
    _refreshCartWidgets() {
        try {
            window.PluginManager.getPluginInstances('CartWidget').forEach((instance) => instance.fetch());
        } catch (e) {
            // Anzeige aktualisiert sich beim nächsten Seitenaufruf.
        }
    }

    async _upload(input) {
        const hidden = input.parentElement.querySelector('[data-sc-field]');
        hidden.value = '';
        if (!input.files.length) {
            this._schedule();
            return;
        }

        const form = new FormData();
        form.append('file', input.files[0]);
        try {
            const response = await fetch(this.options.uploadUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: form,
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (response.ok && data.mediaId) {
                hidden.value = data.mediaId;
            } else {
                input.value = '';
                this._showMessages([this._msg('uploadFailed')]);
                return;
            }
        } catch (e) {
            input.value = '';
            this._showMessages([this._msg('uploadFailed')]);
            return;
        }
        this._schedule();
    }

    // ------------------------------------------------------------ Texte

    _messagesFor(data) {
        const out = [];
        (data.violations || []).forEach((v) => {
            const text = this._msg(v.code);
            if (!out.includes(text)) {
                out.push(text);
            }
        });
        (data.shortages || []).forEach((s) => {
            out.push(`${this._msg('shortage')} ${s.name} (${s.available})`);
        });
        return out;
    }

    _msg(key) {
        return (this.options.messages && this.options.messages[key]) || (this.options.messages && this.options.messages.generic) || '';
    }

    _showMessages(messages) {
        this._messagesEl.textContent = '';
        messages.forEach((text) => {
            const li = document.createElement('li');
            li.textContent = text; // textContent: keine HTML-Einschleusung
            this._messagesEl.appendChild(li);
        });
    }

    _money(value) {
        const locale = document.documentElement.lang || 'de-DE';
        try {
            return new Intl.NumberFormat(locale, { style: 'currency', currency: this.options.currency }).format(value);
        } catch (e) {
            return value.toFixed(2);
        }
    }
}
