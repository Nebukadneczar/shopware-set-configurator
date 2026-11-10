const { ApiService } = Shopware.Classes;

/**
 * Admin-API des Set-Konfigurators (Formelprüfung).
 */
class SetConfiguratorApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/set-configurator') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'setConfiguratorApiService';
    }

    /**
     * @param {string} formula
     * @returns {Promise<{valid: boolean, error: string|null, variables: string[]}>}
     */
    validateFormula(formula) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/validate-formula`, { formula }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    /**
     * Lädt alle Sets als JSON-Datei herunter.
     */
    exportSets() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/export`, { headers: this.getBasicHeaders(), responseType: 'blob' })
            .then((response) => {
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.href = url;
                link.download = `produktsets-${new Date().toISOString().slice(0, 10)}.json`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
            });
    }

    /**
     * @param {object} data Inhalt einer Exportdatei
     * @param {boolean} dryRun true = nur prüfen
     * @returns {Promise<{ok: boolean, errors: string[], created: number, replaced: number, reassigned: number}>}
     */
    importSets(data, dryRun) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/import`, { data, dryRun }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response))
            .catch((error) => {
                // 422 liefert die Fehlerliste im Body
                if (error?.response?.data?.errors) {
                    return error.response.data;
                }
                throw error;
            });
    }
}

Shopware.Service().register('setConfiguratorApiService', () => {
    return new SetConfiguratorApiService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService'),
    );
});
