/**
 * Semantic rules shared (by specification) with the PHP implementation in
 * apps/platform-api/app/Domain/Schema/SemanticValidator.php.
 * Any change here MUST be mirrored there; the parity fixtures in
 * packages/app-schema/fixtures enforce it in both test suites.
 */
export const LIMITS = {
  maxDepth: 32,
  maxComponents: 5000,
  maxPages: 100,
  maxUrlLength: 2048,
  maxDocumentBytes: 2_000_000,
} as const;

export const COLOR_TOKENS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted'] as const;
export const LENGTH_TOKENS = ['radius'] as const;

const LENGTH_UNIT = '-?(?:\\d{1,4}(?:\\.\\d{1,3})?)(?:px|rem|em|%|vh|vw)';
const LENGTH_ATOM = `(?:${LENGTH_UNIT}|0|auto)`;

export const PATTERNS = {
  length: new RegExp(`^(?:${LENGTH_ATOM}|\\$radius)$`),
  box: new RegExp(`^${LENGTH_ATOM}(?: ${LENGTH_ATOM}){0,3}$`),
  hexColor: /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/,
  colorToken: /^\$(primary|secondary|accent|background|surface|text|muted)$/,
  assetRef: /^asset:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/,
  httpsUrl: /^https:\/\/[A-Za-z0-9\-._~:/?#[\]@!$&'()*+,;=%]{1,2040}$/,
  hrefAnchor: /^#[A-Za-z0-9_-]{0,64}$/,
  hrefPath: /^\/(?!\/)[A-Za-z0-9\-._~/%?#=&+]{0,2000}$/,
  hrefHttp: /^https?:\/\/[A-Za-z0-9\-._~:/?#[\]@!$&'()*+,;=%]{1,2040}$/,
  hrefMailto: /^mailto:[A-Za-z0-9\-._~@%+?=&]{3,320}$/,
  hrefTel: /^tel:[0-9+\-() ]{3,32}$/,
  slug: /^[a-z][a-z0-9-]{0,63}$/,
  videoId: /^[A-Za-z0-9_-]{1,32}$/,
  // C0 control characters except TAB and LF are never allowed in text.
  forbiddenControl: /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/,
  newline: /[\r\n]/,
} as const;

export function isSafeHref(value: string): boolean {
  if (value.length === 0 || value.length > LIMITS.maxUrlLength) return false;
  return (
    PATTERNS.hrefAnchor.test(value) ||
    PATTERNS.hrefPath.test(value) ||
    PATTERNS.hrefHttp.test(value) ||
    PATTERNS.hrefMailto.test(value) ||
    PATTERNS.hrefTel.test(value)
  );
}

export function isSafeImageSource(value: string): boolean {
  return value === '' || PATTERNS.assetRef.test(value) || PATTERNS.httpsUrl.test(value);
}

/** Length in Unicode code points (matches PHP mb_strlen). */
export function codePointLength(value: string): number {
  let n = 0;
  for (const _ of value) n++;
  return n;
}
