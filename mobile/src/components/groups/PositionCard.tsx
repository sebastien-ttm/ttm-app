import Ionicons from '@expo/vector-icons/Ionicons';
import { createElement, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import type { TrainingGroupPosition } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { embedMapUrl, formatPoint, mapUrl } from '@/lib/geo';
import { formatRelativeFr } from '@/utils/html';

/** Force un nouveau rendu toutes les `ms` (l'âge d'une position s'affiche même si rien n'a changé côté serveur). */
function useTick(ms: number): void {
  const [, setTick] = useState(0);
  useEffect(() => {
    const timer = setInterval(() => setTick((t) => t + 1), ms);
    return () => clearInterval(timer);
  }, [ms]);
}

/**
 * Position d'un encadrant, partagée à sa demande : carte OpenStreetMap (sur le web, avec le repère au bon endroit),
 * âge de la position, et lien pour l'ouvrir dans l'appli de cartes. `own` : c'est MA position — des boutons, sur la
 * carte, pour l'actualiser (une nouvelle localisation, envoyée tout de suite) ou la retirer.
 */
export function PositionCard({ position, own }: {
  position: TrainingGroupPosition;
  own?: { busy: boolean; onUpdate: () => void; onRemove: () => void };
}) {
  useTick(10_000);
  const { latitude, longitude } = position;

  return (
    <View style={styles.card}>
      <View style={styles.head}>
        <Ionicons name="location" size={18} color={COLORS.primary} />
        <Text style={styles.name} numberOfLines={1}>{position.fullName}</Text>
        <Text style={styles.age}>{formatRelativeFr(position.updatedAt)}</Text>
      </View>

      {Platform.OS === 'web' && createElement('iframe', {
        key: `${latitude.toFixed(5)},${longitude.toFixed(5)}`,
        src: embedMapUrl(latitude, longitude),
        title: `Position de ${position.fullName}`,
        loading: 'lazy',
        style: { width: '100%', height: 190, border: 0, borderRadius: 8, background: '#e5e7eb' },
      })}

      <View style={styles.foot}>
        <Text style={styles.coords}>
          {formatPoint(latitude, longitude)}{position.accuracy !== null ? ` · ±${position.accuracy} m` : ''}
        </Text>
        <Pressable
          onPress={() => void Linking.openURL(mapUrl(latitude, longitude))}
          accessibilityRole="link"
          accessibilityLabel={`Ouvrir la position de ${position.fullName} dans la carte`}
          style={({ pressed }) => [styles.open, pressed && { opacity: 0.7 }]}
        >
          <Ionicons name="navigate" size={14} color={COLORS.secondaryDark} />
          <Text style={styles.openLabel}>Ouvrir la carte</Text>
        </Pressable>
      </View>

      {own && (
        <View style={styles.ownActions}>
          <Pressable
            onPress={own.onUpdate}
            disabled={own.busy}
            accessibilityRole="button"
            style={({ pressed }) => [styles.update, (pressed || own.busy) && { opacity: 0.8 }]}
          >
            {own.busy ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="refresh" size={16} color="#fff" />}
            <Text style={styles.updateLabel}>{own.busy ? 'Localisation en cours…' : 'Actualiser ma position'}</Text>
          </Pressable>
          <Pressable
            onPress={own.onRemove}
            disabled={own.busy}
            accessibilityRole="button"
            accessibilityLabel="Retirer ma position"
            style={({ pressed }) => [styles.remove, (pressed || own.busy) && { opacity: 0.6 }]}
          >
            <Text style={styles.removeLabel}>Retirer</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  ownActions: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 2 },
  update: {
    flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, minHeight: 42,
    borderRadius: RADIUS.md, backgroundColor: COLORS.primary, paddingHorizontal: 12,
  },
  updateLabel: { fontSize: 14, fontWeight: '800', color: '#fff' },
  remove: { paddingHorizontal: 12, paddingVertical: 10 },
  removeLabel: { fontSize: 13, fontWeight: '700', color: COLORS.error },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    padding: SPACING.md,
    gap: 8,
  },
  head: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  name: { flex: 1, fontSize: 14, fontWeight: '700', color: COLORS.text },
  age: { fontSize: 12, color: COLORS.textMuted },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  coords: { flex: 1, fontSize: 12, color: COLORS.textMuted, fontVariant: ['tabular-nums'] },
  open: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  openLabel: { fontSize: 13, fontWeight: '700', color: COLORS.secondaryDark },
});
