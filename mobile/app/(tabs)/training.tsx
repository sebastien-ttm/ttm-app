import { EmptyState } from '@/components/Loading';

// Placeholder. Real implementation (PDF list + viewer) lands in next commit.
export default function TrainingScreen() {
  return (
    <EmptyState
      icon="🏃"
      title="Plans d'entraînement"
      message="L'écran complet (liste + lecteur PDF) arrive dans le prochain commit."
    />
  );
}
