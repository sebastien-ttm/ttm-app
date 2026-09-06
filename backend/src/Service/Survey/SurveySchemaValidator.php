<?php

namespace App\Service\Survey;

use App\Enum\SurveyQuestionType;

/**
 * Valide un schéma de sondage (côté admin) et un payload de réponses
 * (côté adhérent). Distinct de FormSchemaValidator (charte) :
 *  - types limités aux 4 formes demandées
 *  - support natif du choix multiple (list<string>)
 *  - pas de logique d'audience par question (celle-ci est portée par
 *    l'entité Survey elle-même, via AudienceAwareTrait).
 */
class SurveySchemaValidator
{
    /**
     * Vérifie qu'un schéma de sondage est bien formé.
     * Retourne la liste des erreurs (vide si OK).
     *
     * @param mixed $schema
     * @return list<string>
     */
    public function validateSchema(mixed $schema): array
    {
        $errors = [];
        if ($schema === null || $schema === []) {
            return [];
        }
        if (!is_array($schema) || !array_is_list($schema)) {
            return ['Le schéma doit être un tableau JSON.'];
        }

        $allowedTypes = array_map(fn (SurveyQuestionType $t) => $t->value, SurveyQuestionType::cases());
        $seenIds = [];
        foreach ($schema as $i => $q) {
            $prefix = sprintf('Section #%d', $i + 1);
            if (!is_array($q)) {
                $errors[] = "$prefix : doit être un objet.";
                continue;
            }
            foreach (['id', 'label', 'type'] as $key) {
                if (!isset($q[$key]) || !is_string($q[$key]) || trim($q[$key]) === '') {
                    $errors[] = "$prefix : clé \"$key\" requise (string non vide).";
                }
            }
            if (isset($q['id']) && is_string($q['id'])) {
                $id = $q['id'];
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                    $errors[] = "$prefix : id \"$id\" invalide (lettres minuscules, chiffres, underscore, commencer par lettre).";
                }
                if (isset($seenIds[$id])) {
                    $errors[] = "$prefix : id \"$id\" dupliqué.";
                }
                $seenIds[$id] = true;
            }
            if (isset($q['type']) && !in_array($q['type'], $allowedTypes, true)) {
                $errors[] = "$prefix : type \"{$q['type']}\" non supporté. Types valides : ".implode(', ', $allowedTypes);
            }
            $t = isset($q['type']) ? SurveyQuestionType::tryFrom((string) $q['type']) : null;
            if ($t?->needsOptions()) {
                $opts = $q['options'] ?? null;
                if (!is_array($opts) || array_filter($opts, 'is_string') !== $opts || count($opts) < 1) {
                    $errors[] = "$prefix : un champ « ".$t->label()." » doit avoir au moins une option (tableau de strings).";
                }
            }
        }
        return $errors;
    }

    /**
     * Valide une soumission par rapport au schéma.
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
            return [];
        }

        foreach ($schema as $q) {
            $id = $q['id'] ?? null;
            $label = $q['label'] ?? $id;
            $type = SurveyQuestionType::tryFrom((string) ($q['type'] ?? ''));
            $required = !empty($q['required']);
            if (!is_string($id) || $type === null) continue;

            $value = $answers[$id] ?? null;
            $isEmpty = $value === null || $value === '' || $value === [];
            if ($required && $isEmpty) {
                $errors[] = "« $label » est obligatoire.";
                continue;
            }
            if ($isEmpty) continue;

            switch ($type) {
                case SurveyQuestionType::ShortText:
                case SurveyQuestionType::LongText:
                    if (!is_string($value)) {
                        $errors[] = "« $label » doit être du texte.";
                    } elseif ($type === SurveyQuestionType::ShortText && mb_strlen($value) > 500) {
                        $errors[] = "« $label » trop long (500 caractères max).";
                    } elseif ($type === SurveyQuestionType::LongText && mb_strlen($value) > 5000) {
                        $errors[] = "« $label » trop long (5000 caractères max).";
                    }
                    break;
                case SurveyQuestionType::SingleChoice:
                    $opts = $q['options'] ?? [];
                    if (!in_array($value, $opts, true)) {
                        $errors[] = "« $label » : valeur non autorisée.";
                    }
                    break;
                case SurveyQuestionType::MultiChoice:
                    $opts = $q['options'] ?? [];
                    if (!is_array($value) || !array_is_list($value)) {
                        $errors[] = "« $label » : doit être une liste de valeurs.";
                        break;
                    }
                    foreach ($value as $v) {
                        if (!in_array($v, $opts, true)) {
                            $errors[] = "« $label » : valeur « $v » non autorisée.";
                            break;
                        }
                    }
                    break;
            }
        }
        return $errors;
    }

    /**
     * Ne retient que les clés du schéma applicable (rejette les inputs
     * parasites), et coerce les valeurs au bon type pour stockage propre.
     *
     * @param list<array<string, mixed>>|null $schema
     * @param array<string, mixed>            $answers
     * @return array<string, mixed>
     */
    public function normalize(?array $schema, array $answers): array
    {
        if ($schema === null || $schema === []) return [];
        $out = [];
        foreach ($schema as $q) {
            $id = $q['id'] ?? null;
            if (!is_string($id) || !array_key_exists($id, $answers)) continue;
            $type = SurveyQuestionType::tryFrom((string) ($q['type'] ?? ''));
            if ($type === null) continue;
            $value = $answers[$id];
            if ($type === SurveyQuestionType::MultiChoice) {
                $out[$id] = is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
            } else {
                $out[$id] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $out;
    }
}
