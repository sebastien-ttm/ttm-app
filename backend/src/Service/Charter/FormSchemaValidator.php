<?php

namespace App\Service\Charter;

/**
 * Valide un schéma de champs de formulaire (côté admin)
 * et un payload de réponses (côté adhérent).
 */
class FormSchemaValidator
{
    private const ALLOWED_TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'select', 'radio'];
    // 'other' est un alias rétro-compat de 'senior' — accepté à l'entrée.
    private const ALLOWED_AUDIENCES = ['all', 'parent_jeune', 'senior', 'other'];

    /**
     * Filtre les champs applicables à un adhérent selon leur audience :
     *  - 'all' (défaut si absent) → visible par tout le monde
     *  - 'parent_jeune'           → uniquement Parent ou Jeune
     *  - 'senior' (ou 'other')    → uniquement les Sénior
     *
     * @param list<array<string, mixed>>|null $schema
     * @param list<string>                    $profiles  slugs des profils user
     * @return list<array<string, mixed>>
     */
    public function filterForUser(?array $schema, array $profiles): array
    {
        if ($schema === null || $schema === []) {
            return [];
        }
        $isParentOrJeune = in_array('parent', $profiles, true) || in_array('jeune', $profiles, true);
        $isSenior = in_array('senior', $profiles, true);
        return array_values(array_filter($schema, static function (array $f) use ($isParentOrJeune, $isSenior): bool {
            $aud = $f['audience'] ?? 'all';
            return match ($aud) {
                'parent_jeune' => $isParentOrJeune,
                'senior', 'other' => $isSenior,
                default => true, // 'all' + valeur inconnue → visible
            };
        }));
    }

    /**
     * Vérifie qu'un schéma est bien formé. Retourne la liste des erreurs (vide si OK).
     *
     * @param mixed $schema
     * @return list<string>
     */
    public function validateSchema(mixed $schema): array
    {
        $errors = [];

        if ($schema === null || $schema === []) {
            return []; // OK (pas de formulaire)
        }
        if (!is_array($schema) || !array_is_list($schema)) {
            return ['Le schéma doit être un tableau JSON.'];
        }

        $seenIds = [];
        foreach ($schema as $i => $field) {
            $prefix = sprintf('Champ #%d', $i + 1);
            if (!is_array($field)) {
                $errors[] = "$prefix : doit être un objet.";
                continue;
            }
            foreach (['id', 'label', 'type'] as $key) {
                if (!isset($field[$key]) || !is_string($field[$key]) || trim($field[$key]) === '') {
                    $errors[] = "$prefix : clé \"$key\" requise (string non vide).";
                }
            }
            if (isset($field['id']) && is_string($field['id'])) {
                $id = $field['id'];
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                    $errors[] = "$prefix : id \"$id\" invalide (lettres minuscules, chiffres, underscore, commencer par lettre).";
                }
                if (isset($seenIds[$id])) {
                    $errors[] = "$prefix : id \"$id\" dupliqué.";
                }
                $seenIds[$id] = true;
            }
            if (isset($field['type']) && !in_array($field['type'], self::ALLOWED_TYPES, true)) {
                $errors[] = "$prefix : type \"{$field['type']}\" non supporté. Types valides : ".implode(', ', self::ALLOWED_TYPES);
            }
            if (in_array($field['type'] ?? '', ['select', 'radio'], true)) {
                if (!isset($field['options']) || !is_array($field['options']) || count($field['options']) < 1) {
                    $errors[] = "$prefix : un champ \"select\"/\"radio\" doit avoir au moins une option.";
                }
            }
            if (isset($field['audience']) && !in_array($field['audience'], self::ALLOWED_AUDIENCES, true)) {
                $errors[] = "$prefix : audience \"{$field['audience']}\" invalide. Valeurs : ".implode(', ', self::ALLOWED_AUDIENCES);
            }
        }
        return $errors;
    }

    /**
     * Valide une soumission par rapport à un schéma. Retourne la liste des erreurs (vide si OK).
     *
     * @param list<array<string, mixed>>|null $schema
     * @param mixed $answers
     * @return list<string>
     */
    public function validateAnswers(?array $schema, mixed $answers): array
    {
        $errors = [];

        if (!is_array($answers)) {
            return ['Réponses invalides (format attendu : objet clé/valeur).'];
        }
        if ($schema === null || $schema === []) {
            return []; // pas de formulaire à valider
        }

        foreach ($schema as $field) {
            $id = $field['id'] ?? null;
            $label = $field['label'] ?? $id;
            $type = $field['type'] ?? 'text';
            $required = !empty($field['required']);
            $value = $answers[$id] ?? null;

            // Cas spécial case à cocher requise : doit être VRAIE, pas
            // juste « répondue ». Un engagement obligatoire non coché
            // (valeur false, 0, '0', 'false', null…) bloque l'acceptation.
            if ($type === 'checkbox' && $required) {
                $truthy = in_array($value, [true, 1, '1', 'true'], true);
                if (!$truthy) {
                    $errors[] = "« $label » : vous devez cocher cet engagement.";
                }
                continue;
            }

            $isEmpty = $value === null || $value === '' || $value === [];
            if ($required && $isEmpty) {
                $errors[] = "« $label » est obligatoire.";
                continue;
            }
            if ($isEmpty) {
                continue; // optionnel et vide
            }

            switch ($type) {
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[] = "« $label » doit être un nombre.";
                    }
                    break;
                case 'checkbox':
                    if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false', true, false], true)) {
                        $errors[] = "« $label » doit être un booléen.";
                    }
                    break;
                case 'date':
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[] = "« $label » doit être une date au format YYYY-MM-DD.";
                    }
                    break;
                case 'select':
                case 'radio':
                    $options = $field['options'] ?? [];
                    if (!in_array($value, $options, true)) {
                        $errors[] = "« $label » : valeur \"$value\" non autorisée.";
                    }
                    break;
                case 'text':
                case 'textarea':
                default:
                    if (!is_string($value)) {
                        $errors[] = "« $label » doit être du texte.";
                    }
            }
        }
        return $errors;
    }
}
