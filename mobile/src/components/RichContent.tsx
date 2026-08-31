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
      className?: string;
    }>;
    return (
      <View style={style}>
        <Div className="ttm-rich" style={webStyles} dangerouslySetInnerHTML={{ __html: rewritten }} />
        {/* eslint-disable-next-line react-native/no-raw-text */}
        <Div
          dangerouslySetInnerHTML={{
            __html: `<style>
              .ttm-rich img { max-width: 100%; height: auto; border-radius: 6px; }
              .ttm-rich p { margin: 0 0 12px 0; }
              .ttm-rich h2 { font-size: 20px; font-weight: 700; margin: 16px 0 8px; }
              .ttm-rich h3 { font-size: 17px; font-weight: 700; margin: 14px 0 6px; }
              .ttm-rich ul, .ttm-rich ol { padding-left: 22px; margin: 0 0 12px 0; }
              .ttm-rich a { color: #1d4ed8; text-decoration: underline; }
              .ttm-rich a:hover { color: #1e40af; }
              /* Boutons insérés depuis TinyMCE (a.ttm-btn) — variantes primary/secondary/outline. */
              .ttm-rich a.ttm-btn { display: inline-block; padding: 10px 18px; border-radius: 8px;
                text-decoration: none; font-weight: 600; color: #fff; margin: 6px 4px 6px 0;
                transition: background-color 0.15s ease, color 0.15s ease; }
              .ttm-rich a.ttm-btn:hover { color: #fff; }
              .ttm-rich a.ttm-btn.ttm-btn-primary { background: #0d2148; }
              .ttm-rich a.ttm-btn.ttm-btn-primary:hover { background: #1e3a8a; }
              .ttm-rich a.ttm-btn.ttm-btn-secondary { background: #D32F2F; }
              .ttm-rich a.ttm-btn.ttm-btn-secondary:hover { background: #B71C1C; }
              .ttm-rich a.ttm-btn.ttm-btn-outline { background: transparent; color: #0d2148;
                border: 2px solid #0d2148; padding: 8px 16px; }
              .ttm-rich a.ttm-btn.ttm-btn-outline:hover { background: #0d2148; color: #fff; }
            </style>`,
          }}
        />
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
