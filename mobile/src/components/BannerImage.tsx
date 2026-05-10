import { useEffect, useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';

import { banner as bannerApi } from '@/api/resources';
import type { Banner } from '@/api/types';
import { COLORS } from '@/config';

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
        // Silent — banner is decorative
      } finally {
        if (!cancelled) setLoaded(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (!loaded) return null;
  if (!data?.imageUrl) return null;

  return (
    <View style={styles.container}>
      <Image source={{ uri: data.imageUrl }} style={styles.image} resizeMode="cover" />
      {data.title && (
        <View style={styles.titleOverlay}>
          <Text style={styles.title} numberOfLines={2}>
            {data.title}
          </Text>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: '100%',
    height: 140,
    backgroundColor: COLORS.black,
    overflow: 'hidden',
  },
  image: { width: '100%', height: '100%' },
  titleOverlay: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: 'rgba(0,0,0,0.55)',
  },
  title: { color: '#fff', fontWeight: '700', fontSize: 13 },
});
