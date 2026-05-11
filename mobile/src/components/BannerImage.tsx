import { useEffect, useState } from 'react';
import { Image, Platform, StyleSheet, Text, View } from 'react-native';

import { banner as bannerApi } from '@/api/resources';
import type { Banner } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

export function BannerImage() {
  const [data, setData] = useState<Banner | null>(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const resp = await bannerApi.active();
        if (!cancelled) setData(resp.data);
      } catch {
        /* silent — banner is decorative */
      } finally {
        if (!cancelled) setLoaded(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (!loaded || !data?.imageUrl) return null;

  return (
    <View style={styles.wrap}>
      <View style={styles.card}>
        <Image source={{ uri: data.imageUrl }} style={styles.image} resizeMode="cover" />
        <View pointerEvents="none" style={styles.fade} />
        {data.title && (
          <View style={styles.titleArea}>
            <View style={styles.accent} />
            <Text style={styles.title} numberOfLines={2}>
              {data.title}
            </Text>
          </View>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    paddingHorizontal: SPACING.md,
    paddingTop: SPACING.md,
  },
  card: {
    width: '100%',
    height: 160,
    borderRadius: RADIUS.lg,
    overflow: 'hidden',
    backgroundColor: COLORS.black,
    position: 'relative',
  },
  image: { width: '100%', height: '100%' },
  fade: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: '55%',
    // Native fallback: semi-transparent dark layer
    backgroundColor: 'rgba(0,0,0,0.45)',
    // Web : nicer linear gradient
    ...(Platform.OS === 'web'
      ? {
          backgroundColor: 'transparent',
          // eslint-disable-next-line @typescript-eslint/ban-ts-comment
          // @ts-ignore web-only CSS property
          backgroundImage: 'linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.75) 100%)',
        }
      : {}),
  },
  titleArea: {
    position: 'absolute',
    left: SPACING.md,
    right: SPACING.md,
    bottom: SPACING.md,
    flexDirection: 'row',
    alignItems: 'center',
  },
  accent: {
    width: 4,
    height: 28,
    borderRadius: 2,
    backgroundColor: COLORS.primary,
    marginRight: SPACING.sm,
  },
  title: {
    color: '#fff',
    fontWeight: '800',
    fontSize: 16,
    flex: 1,
    letterSpacing: 0.2,
    // subtle text shadow for legibility over photos
    textShadowColor: 'rgba(0,0,0,0.6)',
    textShadowOffset: { width: 0, height: 1 },
    textShadowRadius: 4,
  },
});
