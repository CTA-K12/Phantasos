import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';
import { Collapse } from 'bootstrap';
import { createIcons, icons } from 'lucide';

// Render Lucide icons on initial load and after Turbo navigations
function initIcons() {
    createIcons({ icons });
}

document.addEventListener('DOMContentLoaded', initIcons);
document.addEventListener('turbo:load', initIcons);
document.addEventListener('turbo:frame-load', initIcons);

// Sidebar accordion — only one group open at a time
function initSidebarAccordion() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || sidebar.dataset.accordionInit) return;
    sidebar.dataset.accordionInit = '1';

    sidebar.addEventListener('show.bs.collapse', (e) => {
        sidebar.querySelectorAll('.collapse.show').forEach((open) => {
            if (open !== e.target) {
                Collapse.getInstance(open)?.hide();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initSidebarAccordion);
document.addEventListener('turbo:load', initSidebarAccordion);
