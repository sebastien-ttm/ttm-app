/**
 * Couleurs des pôles (thèmes) de l'ordre du jour du Comité directeur : une teinte par pôle, pour les repérer d'un coup
 * d'œil dans le formulaire (pastilles), la liste des points et les actions en cours. Le libellé reste toujours affiché
 * à côté : la couleur aide, elle ne porte pas seule l'information.
 *
 * Clés = valeurs de l'enum BoardPole côté serveur.
 */
export type PoleTone = {
  /** Couleur franche : bordure, pastille choisie (texte blanc dessus). */
  accent: string;
  /** Fond clair, pour les pastilles non choisies et les étiquettes. */
  soft: string;
  /** Texte foncé lisible sur `soft`. */
  ink: string;
};

const POLE_TONES: Record<string, PoleTone> = {
  partenaires: { accent: '#2563eb', soft: '#dbeafe', ink: '#1e40af' },
  subventions: { accent: '#059669', soft: '#d1fae5', ink: '#065f46' },
  organisations: { accent: '#ea580c', soft: '#ffedd5', ink: '#9a3412' },
  social: { accent: '#db2777', soft: '#fce7f3', ink: '#9d174d' },
  rh: { accent: '#7c3aed', soft: '#ede9fe', ink: '#5b21b6' },
  stage: { accent: '#0d9488', soft: '#ccfbf1', ink: '#115e59' },
  tenues: { accent: '#dc2626', soft: '#fee2e2', ink: '#991b1b' },
  entrainements: { accent: '#0284c7', soft: '#e0f2fe', ink: '#075985' },
  debutants: { accent: '#65a30d', soft: '#ecfccb', ink: '#3f6212' },
  equipes: { accent: '#4f46e5', soft: '#e0e7ff', ink: '#3730a3' },
  longue_distance: { accent: '#d97706', soft: '#fef3c7', ink: '#92400e' },
  communication_externe: { accent: '#c026d3', soft: '#fae8ff', ink: '#86198f' },
  communication_interne: { accent: '#0891b2', soft: '#cffafe', ink: '#155e75' },
  adhesions: { accent: '#16a34a', soft: '#dcfce7', ink: '#166534' },
  tresorerie: { accent: '#ca8a04', soft: '#fef9c3', ink: '#854d0e' },
  outils_numeriques: { accent: '#475569', soft: '#e2e8f0', ink: '#1e293b' },
  divers: { accent: '#78716c', soft: '#f5f5f4', ink: '#44403c' },
};

/** Gris neutre pour un pôle inconnu de cette version de l'appli (ajouté côté serveur depuis). */
const NEUTRAL: PoleTone = { accent: '#94a3b8', soft: '#f1f5f9', ink: '#475569' };

export function poleTone(pole: string | null | undefined): PoleTone {
  return (pole ? POLE_TONES[pole] : undefined) ?? NEUTRAL;
}
