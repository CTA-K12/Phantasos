document.addEventListener('openSidebar', (ev) => {
    const aside = document.querySelector('aside');
    aside.classList.toggle('open');
});

const sidebarToggleTemplate = document.createElement('template');
sidebarToggleTemplate.innerHTML = `
    <style>
        :host {
            --toggle-foregound-color: #000000;
            --toggle-background-color: #ffffff;
        }

        path {
            fill: var(--toggle-foreground-color);
        }
    </style>
    <svg xmlns="http://www.w3.org/2000/svg" height="1.5em" viewBox="0 0 480 480">
        <path d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM0 256c0-17.7 14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32c-17.7 0-32-14.3-32-32zM448 416c0 17.7-14.3 32-32 32H32c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32z"/>
    </svg>
`;

class SidebarToggle extends HTMLElement {
  constructor() {
    super();
  }

  connectedCallback() {
    this.attachShadow({ mode: 'open' });
    this.shadowRoot.appendChild(sidebarToggleTemplate.content.cloneNode(true));

    this.open = localStorage.getItem('sidebar-toggle-open');
    if (this.open && this.open !== 'false') {
        this.sendOpenSidebarEvent();
    } else {
        this.open = false;
    }

    this.shadowRoot.querySelector('svg').addEventListener('click', () => {
        this.open = this.open ? false : true;
        localStorage.setItem('sidebar-toggle-open', this.open);

        this.sendOpenSidebarEvent();
    });
  }

  sendOpenSidebarEvent() {
    const openSidebarEvent = new CustomEvent('openSidebar', {
        bubbles: true,
        cancelable: true,
        composed: true,
        detail: this.open
    });

    this.dispatchEvent(openSidebarEvent);
  }
}

customElements.define("sidebar-toggle", SidebarToggle);
