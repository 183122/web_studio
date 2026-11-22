import type { CSSProperties } from 'react';
import type { Breakpoint, ResponsiveStyles } from '@studio/component-registry';
import type { DesignTokens } from '@studio/app-schema';

const FONT_STACKS: Record<string, string> = {
  Kanit: "'Kanit', 'Noto Sans Thai', system-ui, sans-serif",
  Sarabun: "'Sarabun', 'Noto Sans Thai', system-ui, sans-serif",
  Prompt: "'Prompt', 'Noto Sans Thai', system-ui, sans-serif",
  'Noto Sans Thai': "'Noto Sans Thai', system-ui, sans-serif",
  system: "system-ui, -apple-system, 'Segoe UI', sans-serif",
  serif: "Georgia, 'Times New Roman', serif",
  mono: "ui-monospace, 'SFMono-Regular', Menlo, monospace",
};

const SHADOWS: Record<string, string> = {
  none: 'none',
  sm: '0 1px 2px rgba(15, 23, 42, 0.06), 0 1px 3px rgba(15, 23, 42, 0.1)',
  md: '0 4px 12px rgba(15, 23, 42, 0.1)',
  lg: '0 12px 32px rgba(15, 23, 42, 0.16)',
};

/** Desktop-first cascade: base → tablet → mobile. */
export function resolveStyles(styles: ResponsiveStyles, breakpoint: Breakpoint): Record<string, string | number> {
  const out: Record<string, string | number> = { ...(styles.base ?? {}) };
  if (breakpoint === 'tablet' || breakpoint === 'mobile') Object.assign(out, styles.tablet ?? {});
  if (breakpoint === 'mobile') Object.assign(out, styles.mobile ?? {});
  return out;
}

export function fontStack(name: string): string {
  return FONT_STACKS[name] ?? FONT_STACKS.system;
}

export function resolveToken(value: string | number, tokens: DesignTokens): string | number {
  if (typeof value !== 'string' || !value.startsWith('$')) return value;
  const key = value.slice(1);
  if (key === 'radius') return tokens.radius;
  if (key === 'heading') return fontStack(tokens.fonts.heading);
  if (key === 'body') return fontStack(tokens.fonts.body);
  return (tokens.colors as Record<string, string>)[key] ?? 'inherit';
}

/**
 * Converts an allowlisted, validated style map into React CSS properties.
 * Unknown keys are dropped (defence in depth: validation already rejects them).
 */
export function toCss(map: Record<string, string | number>, tokens: DesignTokens): CSSProperties {
  const css: Record<string, string | number> = {};
  for (const [key, raw] of Object.entries(map)) {
    const value = resolveToken(raw, tokens);
    switch (key) {
      case 'columns':
        css.gridTemplateColumns = `repeat(${Math.max(1, Math.min(12, Number(value) || 1))}, minmax(0, 1fr))`;
        break;
      case 'boxShadow':
        css.boxShadow = SHADOWS[String(value)] ?? 'none';
        break;
      case 'fontFamily':
        css.fontFamily = typeof raw === 'string' && raw.startsWith('$') ? String(value) : fontStack(String(value));
        break;
      case 'borderWidth':
        css.borderWidth = value;
        css.borderStyle = 'solid';
        break;
      case 'display': case 'flexDirection': case 'flexWrap': case 'alignItems': case 'justifyContent':
      case 'gap': case 'padding': case 'margin': case 'width': case 'maxWidth': case 'minHeight': case 'height':
      case 'color': case 'backgroundColor': case 'borderColor': case 'borderRadius': case 'fontSize':
      case 'fontWeight': case 'lineHeight': case 'letterSpacing': case 'textAlign': case 'textTransform':
      case 'objectFit': case 'opacity':
        css[key] = value;
        break;
      default:
        break;
    }
  }
  return css as CSSProperties;
}
