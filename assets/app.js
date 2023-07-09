import './styles/app.scss';

// Alpine
import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

// Alpine
import hljs from 'highlight.js';

import 'highlight.js/styles/github-dark-dimmed.css';

document.addEventListener('DOMContentLoaded', (event) => {
    document.querySelectorAll('pre code').forEach((el) => {
        console.log("test");
        hljs.highlightElement(el, );
    });
});

