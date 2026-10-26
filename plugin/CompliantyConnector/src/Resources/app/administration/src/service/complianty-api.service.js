const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class CompliantyApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, null, 'application/json');
        this.name = 'compliantyApi';
    }

    status() {
        return this.httpClient
            .get('/_action/complianty/status', { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    connect(token) {
        return this.httpClient
            .post('/_action/complianty/connect', { token }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    startScan() {
        return this.httpClient
            .post('/_action/complianty/scan', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    scanStatus() {
        return this.httpClient
            .get('/_action/complianty/scan-status', { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    syncDse() {
        return this.httpClient
            .post('/_action/complianty/dse/sync', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    disconnect() {
        return this.httpClient
            .post('/_action/complianty/disconnect', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Application.addServiceProvider('compliantyApi', (container) => {
    const initContainer = Application.getContainer('init');

    return new CompliantyApiService(initContainer.httpClient, container.loginService);
});
