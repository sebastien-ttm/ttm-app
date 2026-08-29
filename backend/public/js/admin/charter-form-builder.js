/**
 * Éditeur visuel pour le schéma JSON du formulaire de charte.
 *
 * S'attache à toute textarea qui porte l'attribut `data-charter-builder`.
 * Cache la textarea, monte une UI de builder au-dessus, et sync
 * bidirectionnellement le JSON dans la textarea (Symfony Form la lit
 * telle quelle à la soumission).
 *
 * Un toggle « Mode avancé » réaffiche la textarea pour édition JSON directe.
 * Vanilla JS, aucune dépendance.
 */
(function () {
  'use strict';

  const TYPES = [
    { value: 'text',     label: 'Texte court' },
    { value: 'textarea', label: 'Texte long (multiligne)' },
    { value: 'number',   label: 'Nombre' },
    { value: 'date',     label: 'Date' },
    { value: 'checkbox', label: 'Case à cocher' },
    { value: 'select',   label: 'Menu déroulant' },
    { value: 'radio',    label: 'Boutons radio' },
  ];

  const AUDIENCES = [
    { value: 'all',          label: 'Tous les adhérents (défaut)' },
    { value: 'parent_jeune', label: 'Uniquement Parent ou Jeune' },
    { value: 'senior',       label: 'Uniquement Sénior (U25 inclus)' },
  ];

  const ID_PATTERN = /^[a-z][a-z0-9_]*$/;

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('textarea[data-charter-builder]').forEach(mount);
  });

  function mount(textarea) {
    if (textarea.dataset.cfbMounted) return;
    textarea.dataset.cfbMounted = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'cfb-wrapper';
    textarea.parentNode.insertBefore(wrapper, textarea);

    // Cache la textarea originale — sera réaffichée par le toggle avancé
    textarea.style.display = 'none';

    const state = new State(textarea);
    const builder = new BuilderUI(wrapper, state);
    builder.render();

    // Toggle « Mode avancé (JSON) »
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'cfb-toggle';
    toggle.textContent = '⚙ Éditer le JSON brut';
    toggle.addEventListener('click', () => {
      const hidden = textarea.style.display === 'none';
      textarea.style.display = hidden ? 'block' : 'none';
      toggle.textContent = hidden ? '↑ Masquer le JSON' : '⚙ Éditer le JSON brut';
    });
    // Quand l'admin édite le JSON à la main, on re-parse pour maj le builder
    textarea.addEventListener('blur', () => {
      state.fields = state.parse();
      builder.render();
    });
    wrapper.appendChild(toggle);
  }

  class State {
    constructor(textarea) {
      this.textarea = textarea;
      this.fields = this.parse();
    }
    parse() {
      const raw = this.textarea.value.trim();
      if (raw === '') return [];
      try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
      } catch {
        return [];
      }
    }
    sync() {
      this.textarea.value = this.fields.length
        ? JSON.stringify(this.fields, null, 2)
        : '';
      // Dispatch event pour d'éventuels observateurs (Symfony Form etc.)
      this.textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  class BuilderUI {
    constructor(container, state) {
      this.container = container;
      this.state = state;
    }

    render() {
      // Purge sauf les enfants « fixes » qu'on ajoute après (le toggle)
      Array.from(this.container.children).forEach((c) => {
        if (!c.classList.contains('cfb-toggle')) c.remove();
      });

      // Toolbar : compteur + bouton d'ajout
      const toolbar = document.createElement('div');
      toolbar.className = 'cfb-toolbar';

      const count = document.createElement('span');
      count.className = 'cfb-count';
      const n = this.state.fields.length;
      count.textContent = n === 0
        ? 'Aucun engagement (charte « simple bouton J\'accepte »)'
        : (n === 1 ? '1 engagement' : n + ' engagements');
      toolbar.appendChild(count);

      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'btn btn-primary cfb-add-btn';
      addBtn.innerHTML = '<i class="fa fa-plus"></i>&nbsp;Ajouter un engagement';
      addBtn.addEventListener('click', () => {
        this.state.fields.push(this.newField());
        this.state.sync();
        this.render();
      });
      toolbar.appendChild(addBtn);
      this.container.prepend(toolbar);

      // Cards de champs
      const list = document.createElement('div');
      list.className = 'cfb-list';
      this.state.fields.forEach((f, i) => list.appendChild(this.renderField(f, i)));
      this.container.insertBefore(list, this.container.querySelector('.cfb-toggle'));
    }

    newField() {
      // Case à cocher obligatoire — c'est le seul type supporté désormais.
      // ID unique : engagement_1, engagement_2, …
      const existing = new Set(this.state.fields.map((f) => f.id));
      let n = this.state.fields.length + 1;
      while (existing.has('engagement_' + n)) n++;
      return {
        id: 'engagement_' + n,
        label: 'J\'accepte…',
        type: 'checkbox',
        description: '',
        required: true,
      };
    }

    renderField(field, idx) {
      const card = document.createElement('div');
      card.className = 'cfb-field';

      // === Header : uniquement audience + actions (le type/required
      // sont désormais constants : « case à cocher obligatoire »).
      const header = document.createElement('div');
      header.className = 'cfb-field-header';

      const typeBadge = document.createElement('span');
      typeBadge.className = 'cfb-field-type';
      typeBadge.textContent = '☑ Engagement';
      header.appendChild(typeBadge);

      if (field.audience && field.audience !== 'all') {
        const aud = document.createElement('span');
        aud.className = 'cfb-audience-badge';
        aud.textContent = field.audience === 'parent_jeune'
          ? '👨‍👩‍👧 Parent/Jeune'
          : '🎽 Sénior';
        header.appendChild(aud);
      }

      const actions = document.createElement('div');
      actions.className = 'cfb-field-actions';
      actions.appendChild(this.iconBtn('↑', 'Monter', idx === 0, () => this.move(idx, -1)));
      actions.appendChild(this.iconBtn('↓', 'Descendre', idx === this.state.fields.length - 1, () => this.move(idx, 1)));
      actions.appendChild(this.iconBtn('✕', 'Supprimer', false, () => this.remove(idx, field), true));
      header.appendChild(actions);
      card.appendChild(header);

      // === Grille des inputs
      const body = document.createElement('div');
      body.className = 'cfb-field-body';

      // Normalisation silencieuse des schémas legacy : la charte est
      // maintenant checkbox-only, toujours obligatoire, sans texte d'aide.
      field.type = 'checkbox';
      field.required = true;
      delete field.help;
      delete field.options;

      body.appendChild(this.rowInput(
        'Phrase d\'acceptation *',
        field.label || '',
        (v) => { field.label = v; },
        { placeholder: 'Ex : Je m\'engage à respecter les horaires' },
      ));

      // La description est stockée en HTML léger (rendue via RichContent
      // côté mobile). Un bouton facilite l'insertion de liens sans avoir
      // à connaître la syntaxe <a href="…">.
      body.appendChild(this.rowTextareaWithLinkHelper(
        'Description / explication (optionnelle)',
        field.description || '',
        (v) => {
          if (v && v.trim() !== '') field.description = v;
          else delete field.description;
        },
        { placeholder: 'Expliquez à quoi l\'adhérent s\'engage exactement, pourquoi c\'est important…' },
      ));

      body.appendChild(this.rowInput('Identifiant technique *', field.id || '', (v) => {
        field.id = v;
      }, {
        placeholder: 'lettres minuscules, chiffres, _',
        pattern: ID_PATTERN,
        help: 'Doit commencer par une lettre. Utilisé comme clé dans les réponses stockées.',
      }));

      // Normalise l'alias rétro-compat 'other' → 'senior' pour l'affichage
      const currentAudience = (field.audience === 'other') ? 'senior' : (field.audience || 'all');
      body.appendChild(this.rowSelect(
        'Visible par',
        currentAudience,
        AUDIENCES,
        (v) => {
          if (v === 'all') delete field.audience;
          else field.audience = v;
          this.state.sync();
          this.render(); // maj du badge audience
        },
      ));

      card.appendChild(body);
      return card;
    }

    renderOptions(field) {
      const box = document.createElement('div');
      box.className = 'cfb-options';

      const label = document.createElement('div');
      label.className = 'cfb-options-label';
      label.textContent = 'Options proposées à l\'utilisateur';
      box.appendChild(label);

      if (!Array.isArray(field.options)) field.options = [];

      field.options.forEach((opt, optIdx) => {
        const row = document.createElement('div');
        row.className = 'cfb-option-row';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control cfb-input';
        input.value = opt;
        input.placeholder = 'Ex : ' + (optIdx === 0 ? 'S' : optIdx === 1 ? 'M' : 'L');
        input.addEventListener('input', () => {
          field.options[optIdx] = input.value;
          this.state.sync();
        });
        row.appendChild(input);

        row.appendChild(this.iconBtn('✕', 'Supprimer l\'option', false, () => {
          field.options.splice(optIdx, 1);
          this.state.sync();
          this.render();
        }, true));

        box.appendChild(row);
      });

      const addOpt = document.createElement('button');
      addOpt.type = 'button';
      addOpt.className = 'btn btn-secondary btn-sm cfb-add-option';
      addOpt.innerHTML = '<i class="fa fa-plus"></i>&nbsp;Ajouter une option';
      addOpt.addEventListener('click', () => {
        field.options.push('');
        this.state.sync();
        this.render();
      });
      box.appendChild(addOpt);

      return box;
    }

    // ---- Helpers de construction ----

    rowInput(label, value, onChange, opts = {}) {
      const wrap = document.createElement('div');
      wrap.className = 'cfb-row';

      const lab = document.createElement('label');
      lab.className = 'cfb-input-label';
      lab.textContent = label;
      wrap.appendChild(lab);

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control cfb-input';
      input.value = value;
      if (opts.placeholder) input.placeholder = opts.placeholder;
      input.addEventListener('input', () => {
        const v = input.value;
        if (opts.pattern && v !== '' && !opts.pattern.test(v)) {
          input.classList.add('cfb-invalid');
        } else {
          input.classList.remove('cfb-invalid');
        }
        onChange(v);
        this.state.sync();
      });
      wrap.appendChild(input);

      if (opts.help) {
        const help = document.createElement('small');
        help.className = 'cfb-input-help';
        help.textContent = opts.help;
        wrap.appendChild(help);
      }

      return wrap;
    }

    /**
     * Textarea + bouton « 🔗 Ajouter un lien » : demande texte + URL, insère
     * l'HTML `<a href="URL" target="_blank" rel="noopener">TEXT</a>` à la
     * position du curseur (ou remplace la sélection courante).
     */
    rowTextareaWithLinkHelper(label, value, onChange, opts = {}) {
      const wrap = document.createElement('div');
      wrap.className = 'cfb-row';

      const labRow = document.createElement('div');
      labRow.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:4px;';

      const lab = document.createElement('label');
      lab.className = 'cfb-input-label';
      lab.textContent = label;
      lab.style.margin = '0';
      labRow.appendChild(lab);

      const linkBtn = document.createElement('button');
      linkBtn.type = 'button';
      linkBtn.className = 'btn btn-sm btn-secondary';
      linkBtn.style.cssText = 'padding:2px 8px; font-size:12px;';
      linkBtn.textContent = '🔗 Ajouter un lien';
      labRow.appendChild(linkBtn);

      wrap.appendChild(labRow);

      const ta = document.createElement('textarea');
      ta.className = 'form-control cfb-input';
      ta.rows = 3;
      ta.value = value;
      if (opts.placeholder) ta.placeholder = opts.placeholder;
      ta.addEventListener('input', () => {
        onChange(ta.value);
        this.state.sync();
      });
      wrap.appendChild(ta);

      linkBtn.addEventListener('click', () => {
        const selStart = ta.selectionStart ?? ta.value.length;
        const selEnd = ta.selectionEnd ?? selStart;
        const selectedText = ta.value.substring(selStart, selEnd);
        const label = window.prompt('Texte du lien affiché :', selectedText || 'en savoir plus');
        if (label === null || label === '') return;
        let url = window.prompt('URL cible (https://…) :', 'https://');
        if (url === null || url === '') return;
        // Sécurité basique : refuse les schemes javascript: / data:
        if (/^\s*(javascript|data|vbscript):/i.test(url)) {
          window.alert('URL non autorisée.');
          return;
        }
        // Si l'admin oublie le protocole, on préfixe en https://
        if (!/^[a-z][a-z0-9+.\-]*:\/\//i.test(url) && !url.startsWith('mailto:') && !url.startsWith('tel:')) {
          url = 'https://' + url.replace(/^\/+/, '');
        }
        const escapedUrl = url.replace(/"/g, '&quot;');
        const escapedLabel = label.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const snippet = '<a href="' + escapedUrl + '" target="_blank" rel="noopener">' + escapedLabel + '</a>';
        ta.value = ta.value.substring(0, selStart) + snippet + ta.value.substring(selEnd);
        // Replace le curseur juste après le snippet inséré
        const cursor = selStart + snippet.length;
        ta.focus();
        ta.setSelectionRange(cursor, cursor);
        onChange(ta.value);
        this.state.sync();
      });

      return wrap;
    }

    rowTextarea(label, value, onChange, opts = {}) {
      const wrap = document.createElement('div');
      wrap.className = 'cfb-row';

      const lab = document.createElement('label');
      lab.className = 'cfb-input-label';
      lab.textContent = label;
      wrap.appendChild(lab);

      const ta = document.createElement('textarea');
      ta.className = 'form-control cfb-input';
      ta.rows = 3;
      ta.value = value;
      if (opts.placeholder) ta.placeholder = opts.placeholder;
      ta.addEventListener('input', () => {
        onChange(ta.value);
        this.state.sync();
      });
      wrap.appendChild(ta);
      return wrap;
    }

    rowSelect(label, value, options, onChange) {
      const wrap = document.createElement('div');
      wrap.className = 'cfb-row';

      const lab = document.createElement('label');
      lab.className = 'cfb-input-label';
      lab.textContent = label;
      wrap.appendChild(lab);

      const select = document.createElement('select');
      select.className = 'form-control cfb-input';
      options.forEach((o) => {
        const opt = document.createElement('option');
        opt.value = o.value;
        opt.textContent = o.label;
        select.appendChild(opt);
      });
      select.value = value;
      select.addEventListener('change', () => onChange(select.value));
      wrap.appendChild(select);

      return wrap;
    }

    rowCheckbox(label, checked, onChange) {
      const wrap = document.createElement('label');
      wrap.className = 'cfb-checkbox-row';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = checked;
      input.addEventListener('change', () => { onChange(input.checked); this.state.sync(); });
      wrap.appendChild(input);
      const span = document.createElement('span');
      span.textContent = label;
      wrap.appendChild(span);
      return wrap;
    }

    iconBtn(text, title, disabled, onClick, danger = false) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cfb-btn-icon' + (danger ? ' cfb-btn-danger' : '');
      btn.title = title;
      btn.textContent = text;
      btn.disabled = disabled;
      btn.addEventListener('click', onClick);
      return btn;
    }

    move(idx, dir) {
      const to = idx + dir;
      if (to < 0 || to >= this.state.fields.length) return;
      const [item] = this.state.fields.splice(idx, 1);
      this.state.fields.splice(to, 0, item);
      this.state.sync();
      this.render();
    }

    remove(idx, field) {
      if (!confirm('Supprimer le champ « ' + (field.label || field.id) + ' » ?')) return;
      this.state.fields.splice(idx, 1);
      this.state.sync();
      this.render();
    }
  }
})();
