/**
 * Éditeur visuel pour le schéma JSON des sondages.
 *
 * S'attache à toute textarea qui porte l'attribut `data-survey-builder`.
 * Cache la textarea, monte une UI de builder au-dessus, et sync
 * bidirectionnellement le JSON dans la textarea (Symfony Form la lit
 * telle quelle à la soumission).
 *
 * Un toggle « Mode avancé » réaffiche la textarea pour édition JSON directe.
 * Réutilise les classes CSS `cfb-*` du builder charte (charter-form-builder.css)
 * pour partager l'apparence.
 *
 * Types supportés (miroir de App\Enum\SurveyQuestionType) :
 *   - short_text     : réponse libre courte
 *   - long_text      : réponse libre longue (textarea)
 *   - single_choice  : radios (options)
 *   - multi_choice   : cases à cocher (options)
 *
 * Format d'une question :
 *   { id, label, type, required?, help?, options? }
 */
(function () {
  'use strict';

  const TYPES = [
    { value: 'short_text',    label: 'Texte court' },
    { value: 'long_text',     label: 'Texte long' },
    { value: 'single_choice', label: 'Choix unique' },
    { value: 'multi_choice',  label: 'Choix multiple' },
  ];

  const TYPE_ICON = {
    short_text: '📝',
    long_text: '🖋️',
    single_choice: '🔘',
    multi_choice: '☑️',
  };

  const ID_PATTERN = /^[a-z][a-z0-9_]*$/;

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('textarea[data-survey-builder]').forEach(mount);
  });

  function mount(textarea) {
    if (textarea.dataset.sfbMounted) return;
    textarea.dataset.sfbMounted = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'cfb-wrapper';
    textarea.parentNode.insertBefore(wrapper, textarea);

    textarea.style.display = 'none';

    const state = new State(textarea);
    const builder = new BuilderUI(wrapper, state);
    builder.render();

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'cfb-toggle';
    toggle.textContent = '⚙ Éditer le JSON brut';
    toggle.addEventListener('click', () => {
      const hidden = textarea.style.display === 'none';
      textarea.style.display = hidden ? 'block' : 'none';
      toggle.textContent = hidden ? '↑ Masquer le JSON' : '⚙ Éditer le JSON brut';
    });
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
      this.textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  class BuilderUI {
    constructor(container, state) {
      this.container = container;
      this.state = state;
    }

    render() {
      // Purge sauf le toggle (ajouté après render au moment du mount)
      Array.from(this.container.children).forEach((c) => {
        if (!c.classList.contains('cfb-toggle')) c.remove();
      });

      const toolbar = document.createElement('div');
      toolbar.className = 'cfb-toolbar';

      const count = document.createElement('span');
      count.className = 'cfb-count';
      const n = this.state.fields.length;
      count.textContent = n === 0
        ? 'Aucune question'
        : (n === 1 ? '1 question' : n + ' questions');
      toolbar.appendChild(count);

      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'btn btn-primary cfb-add-btn';
      addBtn.innerHTML = '<i class="fa fa-plus"></i>&nbsp;Ajouter une question';
      addBtn.addEventListener('click', () => {
        this.state.fields.push(this.newField());
        this.state.sync();
        this.render();
      });
      toolbar.appendChild(addBtn);
      this.container.prepend(toolbar);

      const list = document.createElement('div');
      list.className = 'cfb-list';
      this.state.fields.forEach((f, i) => list.appendChild(this.renderField(f, i)));
      this.container.insertBefore(list, this.container.querySelector('.cfb-toggle'));
    }

    newField() {
      const existing = new Set(this.state.fields.map((f) => f.id));
      let n = this.state.fields.length + 1;
      while (existing.has('question_' + n)) n++;
      return {
        id: 'question_' + n,
        label: 'Nouvelle question',
        type: 'short_text',
        required: false,
      };
    }

    renderField(field, idx) {
      const card = document.createElement('div');
      card.className = 'cfb-field';

      const header = document.createElement('div');
      header.className = 'cfb-field-header';

      const typeInfo = TYPES.find((t) => t.value === field.type) || TYPES[0];
      const typeBadge = document.createElement('span');
      typeBadge.className = 'cfb-field-type';
      typeBadge.textContent = (TYPE_ICON[field.type] || '❓') + ' ' + typeInfo.label;
      header.appendChild(typeBadge);

      if (field.required) {
        const req = document.createElement('span');
        req.className = 'cfb-audience-badge';
        req.textContent = '⚑ Obligatoire';
        header.appendChild(req);
      }

      const actions = document.createElement('div');
      actions.className = 'cfb-field-actions';
      actions.appendChild(this.iconBtn('↑', 'Monter', idx === 0, () => this.move(idx, -1)));
      actions.appendChild(this.iconBtn('↓', 'Descendre', idx === this.state.fields.length - 1, () => this.move(idx, 1)));
      actions.appendChild(this.iconBtn('✕', 'Supprimer', false, () => this.remove(idx, field), true));
      header.appendChild(actions);
      card.appendChild(header);

      const body = document.createElement('div');
      body.className = 'cfb-field-body';

      body.appendChild(this.rowInput(
        'Libellé de la question *',
        field.label || '',
        (v) => { field.label = v; },
        { placeholder: 'Ex : Comment évalues-tu l\'encadrement ?' },
      ));

      body.appendChild(this.rowSelect(
        'Type de réponse',
        field.type || 'short_text',
        TYPES,
        (v) => {
          field.type = v;
          // Nettoyage : options pertinentes uniquement pour les choix
          if (v === 'single_choice' || v === 'multi_choice') {
            if (!Array.isArray(field.options) || field.options.length === 0) {
              field.options = ['Option 1', 'Option 2'];
            }
          } else {
            delete field.options;
          }
          this.state.sync();
          this.render();
        },
      ));

      body.appendChild(this.rowInput(
        'Aide (optionnelle)',
        field.help || '',
        (v) => {
          const t = (v || '').trim();
          if (t === '') delete field.help;
          else field.help = t;
        },
        {
          placeholder: 'Ex : 1 = pas satisfait, 5 = très satisfait',
          help: 'Petit texte gris affiché sous le libellé pour préciser le sens de la réponse attendue.',
        },
      ));

      body.appendChild(this.rowInput(
        'Identifiant technique *',
        field.id || '',
        (v) => { field.id = v; },
        {
          placeholder: 'lettres minuscules, chiffres, _',
          pattern: ID_PATTERN,
          help: 'Doit commencer par une lettre. Utilisé comme clé dans les réponses stockées et les exports CSV.',
        },
      ));

      body.appendChild(this.rowCheckbox(
        'Réponse obligatoire',
        !!field.required,
        (checked) => {
          if (checked) field.required = true;
          else delete field.required;
          this.state.sync();
          this.render();
        },
      ));

      // Options pour choix unique / multiple
      if (field.type === 'single_choice' || field.type === 'multi_choice') {
        body.appendChild(this.renderOptions(field));
      }

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
        input.placeholder = 'Option ' + (optIdx + 1);
        input.addEventListener('input', () => {
          field.options[optIdx] = input.value;
          this.state.sync();
        });
        row.appendChild(input);

        row.appendChild(this.iconBtn('✕', 'Supprimer l\'option', field.options.length <= 1, () => {
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

    // ---- Helpers ----

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
      if (!confirm('Supprimer la question « ' + (field.label || field.id) + ' » ?')) return;
      this.state.fields.splice(idx, 1);
      this.state.sync();
      this.render();
    }
  }
})();
