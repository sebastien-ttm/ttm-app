/**
 * Emoji picker admin — s'attache à tout <input data-emoji-picker="1">.
 *
 * Utilise le web component <emoji-picker> (emoji-picker-element) chargé
 * via CDN dans DashboardController::configureAssets(). Ajoute un bouton
 * « 😀 » à côté de l'input : ouvre un panneau flottant avec barre de
 * recherche et catégories ; au clic sur un emoji, remplit l'input et
 * ferme le panneau.
 *
 * Auto-attache au chargement + observe le DOM (utile pour les forms
 * chargés dynamiquement par EasyAdmin, ex : modales embed).
 */
(function () {
  'use strict';

  const ATTR = 'data-emoji-picker';
  const READY_FLAG = 'data-emoji-picker-ready';

  function attach(input) {
    if (input.getAttribute(READY_FLAG) === '1') return;
    input.setAttribute(READY_FLAG, '1');

    // Wrapper autour de l'input pour positionner le bouton et le panneau.
    const wrap = document.createElement('span');
    wrap.className = 'ea-emoji-picker-wrap';
    wrap.style.position = 'relative';
    wrap.style.display = 'inline-flex';
    wrap.style.alignItems = 'center';
    wrap.style.gap = '6px';
    wrap.style.width = '100%';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.style.flex = '1 1 auto';

    // Bouton pour ouvrir le picker.
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-secondary btn-sm';
    btn.title = 'Choisir un emoji';
    btn.setAttribute('aria-label', 'Choisir un emoji');
    btn.textContent = '😀';
    btn.style.padding = '4px 10px';
    btn.style.fontSize = '18px';
    btn.style.lineHeight = '1';
    wrap.appendChild(btn);

    // Panneau flottant contenant le picker (créé à la demande).
    let panel = null;
    let picker = null;

    function ensurePanel() {
      if (panel !== null) return;
      panel = document.createElement('div');
      panel.className = 'ea-emoji-picker-panel';
      panel.style.position = 'absolute';
      panel.style.zIndex = '10000';
      panel.style.top = '100%';
      panel.style.right = '0';
      panel.style.marginTop = '6px';
      panel.style.boxShadow = '0 8px 24px rgba(0,0,0,.18)';
      panel.style.borderRadius = '10px';
      panel.style.overflow = 'hidden';
      panel.style.display = 'none';

      picker = document.createElement('emoji-picker');
      // Web component customisable via CSS custom properties.
      picker.style.setProperty('--num-columns', '8');
      picker.style.setProperty('--emoji-size', '1.2rem');
      picker.style.setProperty('--background', 'white');
      picker.style.setProperty('--border-color', '#e5e7eb');
      picker.setAttribute('locale', 'fr');
      // Source de données emoji (~150 KB) — on force jsdelivr comme
      // pour le composant lui-même, pour éviter de dépendre d'un CDN
      // par défaut (unpkg) qui n'est pas nécessairement autorisé.
      picker.setAttribute('data-source', 'https://cdn.jsdelivr.net/npm/emoji-picker-element-data@1/fr/emojibase/data.json');
      picker.addEventListener('emoji-click', (e) => {
        // e.detail = { unicode: '💡', annotation: 'ampoule', ... }
        const emoji = (e.detail && e.detail.unicode) || '';
        if (emoji === '') return;
        input.value = emoji;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        hide();
      });
      panel.appendChild(picker);
      wrap.appendChild(panel);
    }

    function show() {
      ensurePanel();
      panel.style.display = 'block';
      // Ferme au premier clic hors du panneau.
      setTimeout(() => {
        document.addEventListener('mousedown', outsideClose, { capture: true });
      }, 0);
    }

    function hide() {
      if (panel !== null) panel.style.display = 'none';
      document.removeEventListener('mousedown', outsideClose, { capture: true });
    }

    function outsideClose(e) {
      if (!wrap.contains(e.target)) hide();
    }

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      if (panel !== null && panel.style.display === 'block') hide();
      else show();
    });
  }

  function scan(root) {
    (root || document).querySelectorAll('input[' + ATTR + '="1"]').forEach(attach);
  }

  function bootstrap() {
    scan(document);
    // Observe le DOM pour les inputs ajoutés plus tard (EA n'en injecte
    // pas dynamiquement dans le CRUD article, mais on garde le filet).
    if (typeof MutationObserver === 'function') {
      const mo = new MutationObserver((mutations) => {
        for (const m of mutations) {
          m.addedNodes.forEach((n) => {
            if (n.nodeType === 1) scan(n);
          });
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
