import React from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';

import { API_BASE_URL, COLORS } from '@/config';
import { htmlToText } from '@/utils/html';

type Props = {
  html: string;
  style?: object;
};

/**
 * Renders rich HTML content from the backend.
 *
 *  - On web : real HTML via a <div dangerouslySetInnerHTML>. Inline <img>
 *    tags use absolute URLs so they load from the API host.
 *  - On native : falls back to plain text (htmlToText). Images get a
 *    [Image: alt] placeholder for now.
 *
 * If/when we need rich rendering on native too, swap this for
 * `react-native-render-html` — same interface.
 */
export function RichContent({ html, style }: Props) {
  if (!html?.trim()) return null;

  if (Platform.OS === 'web') {
    // Rewrite relative URLs (`/uploads/...`) to absolute against the API host
    // so images load when the web app and API run on different ports.
    const rewritten = html.replace(/\bsrc=["'](\/[^"']+)["']/g, (_m, path) => `src="${API_BASE_URL}${path}"`);

    // On web, react-native-web compiles to real DOM; we drop down to a raw <div>
    // so we can use dangerouslySetInnerHTML for the article HTML.
    const Div = 'div' as unknown as React.ComponentType<{
      style?: object;
      dangerouslySetInnerHTML?: { __html: string };
    }>;
    return (
      <View style={style}>
        <Div style={webStyles} dangerouslySetInnerHTML={{ __html: rewritten }} />
      </View>
    );
  }

  return <Text style={[styles.fallback, style]}>{htmlToText(html)}</Text>;
}

const styles = StyleSheet.create({
  fallback: {
    fontSize: 15,
    color: COLORS.text,
    lineHeight: 23,
  },
});

const webStyles = {
  fontSize: 15,
  color: COLORS.text,
  lineHeight: 1.55,
  fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
} as const;
