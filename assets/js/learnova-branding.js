/* Learnova product-name and visual-identity layer.
 * Safe to load on legacy pages: routes, database values, and school names stay intact.
 */
(() => {
    'use strict';

    const brandText = value => String(value || '').replace(/edusync/gi, 'Learnova');

    function updateMetadata() {
        if (/edusync/i.test(document.title)) {
            document.title = brandText(document.title);
        }

        document.querySelectorAll('meta[name="application-name"], meta[name="apple-mobile-web-app-title"], meta[property="og:site_name"]').forEach(meta => {
            meta.content = 'Learnova';
        });

        document.querySelectorAll('meta[name="description"], meta[property="og:title"], meta[name="twitter:title"]').forEach(meta => {
            meta.content = brandText(meta.content);
        });

        document.querySelectorAll('link[rel="manifest"]').forEach(link => {
            // Preserve the existing manifest URL; it is updated separately with Learnova branding.
            link.setAttribute('data-product-brand', 'Learnova');
        });
    }

    function updateVisibleBranding(root = document.body) {
        if (!root) return;

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                const parent = node.parentElement;
                if (!parent || /^(SCRIPT|STYLE|NOSCRIPT|TEXTAREA|INPUT|CODE|PRE)$/i.test(parent.tagName)) {
                    return NodeFilter.FILTER_REJECT;
                }
                return /edusync/i.test(node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });

        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(node => { node.nodeValue = brandText(node.nodeValue); });

        root.querySelectorAll?.('[title], [aria-label], [alt]').forEach(element => {
            ['title', 'aria-label', 'alt'].forEach(attribute => {
                const value = element.getAttribute(attribute);
                if (value && /edusync/i.test(value)) element.setAttribute(attribute, brandText(value));
            });
        });
    }

    function applyBrand() {
        updateMetadata();
        updateVisibleBranding();
        document.documentElement.dataset.productBrand = 'learnova';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyBrand, { once: true });
    } else {
        applyBrand();
    }

    // Also cover content rendered later by PHP-backed AJAX screens or client-side widgets.
    new MutationObserver(records => {
        records.forEach(record => {
            record.addedNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE && /edusync/i.test(node.nodeValue)) {
                    node.nodeValue = brandText(node.nodeValue);
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                    updateVisibleBranding(node);
                }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    const style = document.createElement('style');
    style.textContent = `
        :root { --learnova-primary: #17a2b8; --learnova-navy: #081f2c; --learnova-accent: #35d0ff; }
        [data-product-brand="learnova"] .learnova-brand { letter-spacing: .02em; }
    `;
    document.head.appendChild(style);
})();
