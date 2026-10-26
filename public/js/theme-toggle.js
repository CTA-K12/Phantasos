document.addEventListener('themeChange', (ev) => {
    const root = document.querySelector('html');
    root.dataset.theme = ev.detail;
});

const toggleTemplate = document.createElement('template');
toggleTemplate.innerHTML = `
    <style>
        :host {
            --toggle-foregound-color: #000000;
            --toggle-background-color: #ffffff;
        }

        path {
            fill: var(--toggle-foreground-color);
        }

        circle {
            fill: var(--toggle-background-color);
        }
    </style>
    <svg id="icon" height="1em" viewBox="0 0 756 756" xmlns="http://www.w3.org/2000/svg">
        <circle r="378" cx="378" cy="378" />
        <path transform="translate(128, 128)" d="M448 256c0-106-86-192-192-192V448c106 0 192-86 192-192zM0 256a256 256 0 1 1 512 0A256 256 0 1 1 0 256z"/>
    </svg>
`;

class ThemeToggle extends HTMLElement {
  constructor() {
    super();
  }

  connectedCallback() {
    this.attachShadow({ mode: 'open' });
    this.shadowRoot.appendChild(toggleTemplate.content.cloneNode(true));

    this.currentTheme = localStorage.getItem('theme-toggle');
    if (!this.currentTheme) {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            this.currentTheme = 'dark';
        } else {
            this.currentTheme = 'light';
        }

        localStorage.setItem('theme-toggle', this.currentTheme);
    } else {
        this.sendThemeChangeEvent();
    }

    this.shadowRoot.querySelector('#icon').addEventListener('click', () => {
        this.currentTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('theme-toggle', this.currentTheme);

        this.sendThemeChangeEvent();
    });
  }

  sendThemeChangeEvent() {
    const changeThemeEvent = new CustomEvent('themeChange', {
        bubbles: true,
        cancelable: true,
        composed: true,
        detail: this.currentTheme
    });

    this.dispatchEvent(changeThemeEvent);
  }
}

customElements.define("theme-toggle", ThemeToggle);
