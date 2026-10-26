import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'download'];
    static values = { scope: { type: String, default: 'my_students' } };

    apply() {
        const formData = new FormData(this.formTarget);
        const params = new URLSearchParams(formData);
        params.set('scope', this.scopeValue);

        document.querySelectorAll('[data-controller~="chart"]').forEach(el => {
            el.dispatchEvent(new CustomEvent('chart:refresh', {
                detail: { params: params.toString() },
                bubbles: false,
            }));
        });

        if (this.hasDownloadTarget) {
            const base = this.downloadTarget.dataset.baseUrl;
            this.downloadTarget.href = base + '?' + params.toString();
        }
    }

    setScope(event) {
        this.scopeValue = event.params.scope;
        this.apply();
    }

    // Used on pages with server-rendered content (e.g. student show).
    // Submits the filter form as a GET request; Turbo handles it as a navigation.
    navigate() {
        this.formTarget.requestSubmit();
    }

    scopeValueChanged() {
        this.element.querySelectorAll('[data-filter-scope-param]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filterScopeParam === this.scopeValue);
        });
    }
}
