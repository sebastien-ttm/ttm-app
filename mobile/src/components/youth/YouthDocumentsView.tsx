import Ionicons from '@expo/vector-icons/Ionicons';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { youthDocuments } from '@/api/resources';
import type { YouthDocument, YouthDocumentKind, YouthDocumentsDetail } from '@/api/types';
import { DateField } from '@/components/DateField';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { ContactButtons } from '@/components/groups/ContactButtons';
import { COLORS, RADIUS, SPACING } from '@/config';
import { openAttachment, withBearer } from '@/lib/openAttachment';
import { canPickFile, pickFile } from '@/lib/pickFile';
import { confirmAction } from '@/utils/confirm';
import { formatPhoneFr } from '@/utils/phone';

const KINDS: { kind: YouthDocumentKind; icon: string; title: string; help: string }[] = [
  {
    kind: 'health_form',
    icon: '🩺',
    title: 'Fiche sanitaire',
    help: 'Fiche sanitaire de liaison officielle (Cerfa n° 10008*02), remplie et signée par un parent : vaccins, allergies, traitements, personnes à prévenir.',
  },
  {
    kind: 'exit_authorization',
    icon: '🛂',
    title: 'Autorisation de sortie du territoire',
    help: 'Formulaire officiel AST (Cerfa n° 15646*01) signé par un parent, avec la copie de sa pièce d\'identité. Indiquez sa date de fin de validité.',
  },
];

function notify(message: string): void {
  if (Platform.OS === 'web') window.alert(message);
  else Alert.alert('Documents', message);
}

function dateLabel(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * Un document (fiche sanitaire ou autorisation de sortie du territoire) : état (déposé, expiré, manquant), ouverture en un
 * appui, envoi / remplacement (photo ou PDF) avec date de fin de validité facultative, suppression.
 */
function DocumentCard({ youthId, kind, icon, title, help, document, onChanged }: {
  youthId: number;
  kind: YouthDocumentKind;
  icon: string;
  title: string;
  help: string;
  document: YouthDocument | null;
  onChanged: () => void;
}) {
  const [validUntil, setValidUntil] = useState<string | null>(document?.validUntil ?? null);
  const [busy, setBusy] = useState(false);
  const missing = document === null;

  async function upload() {
    if (!canPickFile) {
      notify('Le dépôt d\'un document se fait depuis l\'appli web (navigateur du téléphone).');
      return;
    }
    const file = await pickFile('application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp');
    if (file === null) return;
    setBusy(true);
    try {
      await youthDocuments.upload(youthId, kind, file, file.name, validUntil);
      onChanged();
    } catch (e) {
      notify(e instanceof ApiError ? e.message : 'Envoi impossible. Réessayez.');
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!(await confirmAction('Supprimer ce document ?', `« ${document?.name ?? title} » sera supprimé.`, 'Supprimer'))) return;
    setBusy(true);
    try {
      await youthDocuments.remove(youthId, kind);
      onChanged();
    } catch (e) {
      notify(e instanceof ApiError ? e.message : 'Suppression impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={[styles.card, missing ? styles.cardMissing : document.expired ? styles.cardExpired : styles.cardOk]}>
      <View style={styles.cardHead}>
        <Text style={styles.cardIcon}>{icon}</Text>
        <View style={{ flex: 1 }}>
          <Text style={styles.cardTitle}>{title}</Text>
          <Text style={[styles.status, missing ? styles.statusMissing : document.expired ? styles.statusExpired : styles.statusOk]}>
            {missing
              ? 'Manquante'
              : document.expired
                ? `Expirée depuis le ${dateLabel(document.validUntil as string)} — à renouveler`
                : `Déposée le ${dateLabel(document.uploadedAt)}${document.validUntil ? ` · valable jusqu'au ${dateLabel(document.validUntil)}` : ''}`}
          </Text>
          {document && (
            <Text style={styles.meta} numberOfLines={1}>
              {document.name} · {document.humanSize}{document.uploadedBy ? ` · par ${document.uploadedBy}` : ''}
            </Text>
          )}
        </View>
      </View>

      {document && (
        <Pressable
          onPress={() => void openAttachment((token) => withBearer(document.url, token))}
          accessibilityRole="button"
          style={({ pressed }) => [styles.openBtn, pressed && { opacity: 0.8 }]}
        >
          <Ionicons name="open-outline" size={18} color="#fff" />
          <Text style={styles.openLabel}>Ouvrir le document</Text>
        </Pressable>
      )}

      <Text style={styles.help}>{help}</Text>

      <Text style={styles.fieldLabel}>Valable jusqu'au (facultatif)</Text>
      <DateField initialIso={document?.validUntil ?? undefined} onChange={setValidUntil} disabled={busy} />

      <View style={styles.actions}>
        {document && (
          <Pressable onPress={() => void remove()} disabled={busy} style={({ pressed }) => [styles.ghostBtn, pressed && { opacity: 0.7 }]}>
            <Ionicons name="trash-outline" size={16} color={COLORS.error} />
            <Text style={[styles.ghostLabel, { color: COLORS.error }]}>Supprimer</Text>
          </Pressable>
        )}
        <Pressable
          onPress={() => void upload()}
          disabled={busy}
          style={({ pressed }) => [styles.uploadBtn, (busy || pressed) && { opacity: 0.8 }]}
        >
          {busy ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="cloud-upload" size={18} color="#fff" />}
          <Text style={styles.uploadLabel}>{missing ? 'Ajouter' : 'Remplacer'}</Text>
        </Pressable>
      </View>
    </View>
  );
}

/**
 * Documents officiels d'un jeune : fiche sanitaire et autorisation de sortie du territoire. Le jeune, ses parents et le
 * staff peuvent les ouvrir et les déposer ; le staff y trouve aussi de quoi joindre le jeune et son contact d'urgence.
 */
export function YouthDocumentsView({ youthId }: { youthId: number }) {
  const [detail, setDetail] = useState<YouthDocumentsDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setDetail(await youthDocuments.get(youthId));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, [youthId]);

  useEffect(() => { void load(); }, [load]);

  if (detail === null) {
    return error ? <ErrorState message={error} onRetry={() => void load()} /> : <FullScreenLoading />;
  }

  const { owner, documents, contact } = detail;
  const missingCount = KINDS.filter((k) => documents[k.kind] === null || documents[k.kind]?.expired).length;

  return (
    <ScrollView contentContainerStyle={styles.content}>
      <View style={styles.header}>
        <MemberAvatar prenom={owner.prenom} nom={owner.nom} avatarUrl={owner.avatarUrl} size={56} />
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{owner.prenom} {owner.nom.toUpperCase()}</Text>
          <Text style={styles.sub}>
            {owner.age !== null ? `${owner.age} ans · ` : ''}
            {missingCount === 0 ? 'Documents à jour' : `${missingCount} document${missingCount > 1 ? 's' : ''} manquant${missingCount > 1 ? 's' : ''} ou à renouveler`}
          </Text>
        </View>
      </View>

      {contact && (contact.telephone || contact.telephoneUrgence) && (
        <View style={styles.contact}>
          <View style={{ flex: 1, gap: 2 }}>
            <Text style={styles.contactTitle}>Joindre</Text>
            {contact.telephone && (
              <Text style={styles.contactLine}>
                {formatPhoneFr(contact.telephone)}{contact.telephoneOf ? ` · parent : ${contact.telephoneOf}` : ''}
              </Text>
            )}
            {contact.telephoneUrgence && <Text style={styles.contactUrgence}>Urgence : {formatPhoneFr(contact.telephoneUrgence)}</Text>}
          </View>
          <ContactButtons
            name={`${owner.prenom} ${owner.nom}`}
            telephone={contact.telephone}
            telephoneOf={contact.telephoneOf}
            telephoneUrgence={contact.telephoneUrgence}
          />
        </View>
      )}

      {KINDS.map((k) => (
        <DocumentCard
          key={k.kind + ':' + (documents[k.kind]?.id ?? 'none')}
          youthId={youthId}
          kind={k.kind}
          icon={k.icon}
          title={k.title}
          help={k.help}
          document={documents[k.kind]}
          onChanged={() => void load()}
        />
      ))}

      <Text style={styles.privacy}>
        Ces documents contiennent des données de santé et d'identité : ils ne sont visibles que par le jeune, ses parents,
        les entraîneurs / encadrants du club et les administrateurs.
      </Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, gap: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  header: {
    flexDirection: 'row', alignItems: 'center', gap: 12, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  name: { fontSize: 18, fontWeight: '800', color: COLORS.text },
  sub: { fontSize: 13, color: COLORS.textMuted },
  contact: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  contactTitle: { fontSize: 12, fontWeight: '800', color: COLORS.textMuted, textTransform: 'uppercase' },
  contactLine: { fontSize: 14, color: COLORS.secondaryDark, fontVariant: ['tabular-nums'] },
  contactUrgence: { fontSize: 14, color: COLORS.error, fontWeight: '700', fontVariant: ['tabular-nums'] },
  card: { padding: SPACING.md, borderRadius: RADIUS.md, borderWidth: 1, borderLeftWidth: 4, backgroundColor: COLORS.surface, gap: 8 },
  cardOk: { borderColor: COLORS.border, borderLeftColor: COLORS.success },
  cardMissing: { borderColor: COLORS.border, borderLeftColor: COLORS.error },
  cardExpired: { borderColor: COLORS.border, borderLeftColor: COLORS.warning },
  cardHead: { flexDirection: 'row', gap: 10 },
  cardIcon: { fontSize: 24 },
  cardTitle: { fontSize: 16, fontWeight: '800', color: COLORS.text },
  status: { fontSize: 13, fontWeight: '700' },
  statusOk: { color: COLORS.success },
  statusMissing: { color: COLORS.error },
  statusExpired: { color: '#b45309' },
  meta: { fontSize: 12, color: COLORS.textMuted },
  help: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17 },
  fieldLabel: { fontSize: 12, fontWeight: '700', color: COLORS.text, marginTop: 2 },
  openBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 12,
    borderRadius: RADIUS.md, backgroundColor: COLORS.secondaryDark,
  },
  openLabel: { color: '#fff', fontWeight: '800', fontSize: 15 },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10, marginTop: 4 },
  ghostBtn: {
    flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 14, paddingVertical: 10,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  ghostLabel: { fontSize: 14, fontWeight: '700' },
  uploadBtn: {
    flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 18, paddingVertical: 10,
    borderRadius: RADIUS.md, backgroundColor: COLORS.primary,
  },
  uploadLabel: { color: '#fff', fontWeight: '800', fontSize: 14 },
  privacy: { fontSize: 11, color: COLORS.textMuted, textAlign: 'center', lineHeight: 16, marginTop: SPACING.sm },
});
