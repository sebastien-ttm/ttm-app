import Ionicons from '@expo/vector-icons/Ionicons';
import { useMemo, useState } from 'react';
import { Image, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import type { TrainingGroupPosition } from '@/api/types';
import { COLORS, RADIUS } from '@/config';

/** Couleur du repère d'un encadrant, d'un adhérent. */
export const COACH_COLOR = COLORS.primary;
export const MEMBER_COLOR = COLORS.secondary;

const TILE = 256;
const MAP_HEIGHT = 280;
/** Marge (px) gardée autour des repères pour les voir en entier avec leur nom. */
const PAD_X = 56;
const PAD_TOP = 48;
const PAD_BOTTOM = 40;
const MIN_ZOOM = 3;
const MAX_ZOOM = 18;
/** Zoom d'un repère seul (ou de repères très proches) : de quoi reconnaître le quartier. */
const SINGLE_ZOOM = 16;

/** Position d'un point sur la « carte du monde » en pixels au zoom `z` (projection Web Mercator des tuiles OSM). */
function worldPx(latitude: number, longitude: number, z: number): { x: number; y: number } {
  const scale = TILE * 2 ** z;
  const sin = Math.sin((Math.max(-85.05, Math.min(85.05, latitude)) * Math.PI) / 180);
  return {
    x: ((longitude + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * scale,
  };
}

/** Plus fort zoom où tous les repères tiennent dans la carte (`width` × `MAP_HEIGHT`), avec leurs marges. */
function fitZoom(points: TrainingGroupPosition[], width: number): number {
  for (let z = SINGLE_ZOOM; z > MIN_ZOOM; z--) {
    const px = points.map((p) => worldPx(p.latitude, p.longitude, z));
    const spanX = Math.max(...px.map((p) => p.x)) - Math.min(...px.map((p) => p.x));
    const spanY = Math.max(...px.map((p) => p.y)) - Math.min(...px.map((p) => p.y));
    if (spanX <= width - 2 * PAD_X && spanY <= MAP_HEIGHT - PAD_TOP - PAD_BOTTOM) return z;
  }
  return MIN_ZOOM;
}

/**
 * Carte du groupe : les positions partagées par les encadrants (repère rouge) et les adhérents (repère bleu), chacune
 * avec le nom de la personne. Fond de carte OpenStreetMap assemblé à partir de ses tuiles (pas de bibliothèque
 * cartographique) ; la carte se cadre toute seule sur les positions, avec des boutons pour zoomer. Affichée sur le web
 * (comme l'ancienne carte intégrée) ; ailleurs, la liste des positions et le lien « Ouvrir la carte » suffisent.
 */
export function GroupMap({ positions, myId }: { positions: TrainingGroupPosition[]; myId?: number }) {
  const [width, setWidth] = useState(0);
  const [zoomShift, setZoomShift] = useState(0);

  const view = useMemo(() => {
    if (width === 0 || positions.length === 0) return null;
    const zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, fitZoom(positions, width) + zoomShift));
    const px = positions.map((p) => worldPx(p.latitude, p.longitude, zoom));
    // Centre : milieu des repères, légèrement remonté pour laisser la place des noms sous les repères.
    const cx = (Math.max(...px.map((p) => p.x)) + Math.min(...px.map((p) => p.x))) / 2;
    const cy = (Math.max(...px.map((p) => p.y)) + Math.min(...px.map((p) => p.y))) / 2 + (PAD_BOTTOM - PAD_TOP) / 2;
    const left = cx - width / 2;
    const top = cy - MAP_HEIGHT / 2;

    const count = 2 ** zoom;
    const tiles: { key: string; uri: string; x: number; y: number }[] = [];
    for (let ty = Math.floor(top / TILE); ty <= Math.floor((top + MAP_HEIGHT) / TILE); ty++) {
      if (ty < 0 || ty >= count) continue;
      for (let tx = Math.floor(left / TILE); tx <= Math.floor((left + width) / TILE); tx++) {
        const wrapped = ((tx % count) + count) % count;
        tiles.push({ key: `${zoom}/${tx}/${ty}`, uri: `https://tile.openstreetmap.org/${zoom}/${wrapped}/${ty}.png`, x: tx * TILE - left, y: ty * TILE - top });
      }
    }
    // Les adhérents d'abord, les encadrants par-dessus : leur repère reste visible s'ils sont au même endroit.
    const pins = positions
      .map((p, i) => ({ p, x: px[i].x - left, y: px[i].y - top }))
      .sort((a, b) => Number(a.p.isCoach) - Number(b.p.isCoach));

    return { zoom, tiles, pins };
  }, [positions, width, zoomShift]);

  if (Platform.OS !== 'web' || positions.length === 0) return null;

  return (
    <View style={styles.wrap}>
      <View style={styles.map} onLayout={(e) => setWidth(Math.round(e.nativeEvent.layout.width))}>
        {view?.tiles.map((t) => (
          <Image key={t.key} source={{ uri: t.uri }} style={[styles.tile, { left: t.x, top: t.y }]} />
        ))}

        {view?.pins.map(({ p, x, y }) => {
          const color = p.isCoach ? COACH_COLOR : MEMBER_COLOR;
          return (
            <View key={p.userId} style={[styles.pin, { left: x - 70, top: y - 34 }]}>
              <Ionicons name="location" size={34} color={color} style={styles.pinIcon} />
              <View style={[styles.label, { borderColor: color }]}>
                <Text style={[styles.labelText, { color }]} numberOfLines={1}>
                  {p.fullName}{p.userId === myId ? ' (vous)' : ''}
                </Text>
              </View>
            </View>
          );
        })}

        <View style={styles.zoom}>
          <Pressable
            onPress={() => setZoomShift((z) => z + 1)}
            disabled={view !== null && view.zoom >= MAX_ZOOM}
            accessibilityRole="button"
            accessibilityLabel="Zoomer sur la carte"
            style={({ pressed }) => [styles.zoomBtn, pressed && { opacity: 0.6 }]}
          >
            <Ionicons name="add" size={20} color={COLORS.text} />
          </Pressable>
          <Pressable
            onPress={() => setZoomShift((z) => z - 1)}
            disabled={view !== null && view.zoom <= MIN_ZOOM}
            accessibilityRole="button"
            accessibilityLabel="Dézoomer la carte"
            style={({ pressed }) => [styles.zoomBtn, pressed && { opacity: 0.6 }]}
          >
            <Ionicons name="remove" size={20} color={COLORS.text} />
          </Pressable>
        </View>

        <Text style={styles.attribution}>© Contributeurs OpenStreetMap</Text>
      </View>

      <View style={styles.legend}>
        <View style={styles.legendItem}>
          <View style={[styles.dot, { backgroundColor: COACH_COLOR }]} />
          <Text style={styles.legendText}>Encadrants</Text>
        </View>
        <View style={styles.legendItem}>
          <View style={[styles.dot, { backgroundColor: MEMBER_COLOR }]} />
          <Text style={styles.legendText}>Adhérents</Text>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: 6 },
  map: { height: MAP_HEIGHT, borderRadius: RADIUS.md, overflow: 'hidden', backgroundColor: '#e5e7eb', borderWidth: 1, borderColor: COLORS.border },
  tile: { position: 'absolute', width: TILE, height: TILE },
  // Largeur fixe pour centrer le repère et son nom sur le point (la pointe du repère est au bas de l'icône).
  pin: { position: 'absolute', width: 140, alignItems: 'center' },
  pinIcon: { textShadowColor: 'rgba(0,0,0,0.35)', textShadowRadius: 3, textShadowOffset: { width: 0, height: 1 } },
  label: {
    maxWidth: 140, paddingHorizontal: 6, paddingVertical: 2, borderRadius: 8, borderWidth: 1.5,
    backgroundColor: 'rgba(255,255,255,0.94)',
  },
  labelText: { fontSize: 11, fontWeight: '800' },
  zoom: { position: 'absolute', top: 8, right: 8, gap: 4 },
  zoomBtn: {
    width: 32, height: 32, borderRadius: 8, alignItems: 'center', justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.95)', borderWidth: 1, borderColor: COLORS.border,
  },
  attribution: {
    position: 'absolute', right: 0, bottom: 0, paddingHorizontal: 5, paddingVertical: 1, fontSize: 10, color: '#333',
    backgroundColor: 'rgba(255,255,255,0.75)',
  },
  legend: { flexDirection: 'row', gap: 14 },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  dot: { width: 10, height: 10, borderRadius: 5 },
  legendText: { fontSize: 12, color: COLORS.textMuted, fontWeight: '600' },
});
