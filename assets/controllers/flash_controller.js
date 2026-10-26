import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.timeout = setTimeout(() => {
            this.element.classList.remove('show');
            setTimeout(() => this.element.remove(), 150);
        }, 5000);
    }

    disconnect() {
        if (this.timeout) {
            clearTimeout(this.timeout);
        }
    }
}
