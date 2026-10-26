import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['cardView', 'tableView', 'cardBtn', 'tableBtn'];

    showCards() {
        this.cardViewTarget.classList.remove('d-none');
        this.tableViewTarget.classList.add('d-none');
        this.cardBtnTarget.classList.add('active');
        this.tableBtnTarget.classList.remove('active');
        localStorage.setItem('studentViewMode', 'cards');
    }

    showTable() {
        this.cardViewTarget.classList.add('d-none');
        this.tableViewTarget.classList.remove('d-none');
        this.cardBtnTarget.classList.remove('active');
        this.tableBtnTarget.classList.add('active');
        localStorage.setItem('studentViewMode', 'table');
    }

    connect() {
        const saved = localStorage.getItem('studentViewMode');
        if (saved === 'table') {
            this.showTable();
        }
    }
}
