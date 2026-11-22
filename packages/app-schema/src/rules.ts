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

// ---------------- Phase 2: record values ----------------

export const RESERVED_FIELD_NAMES = ['id', 'created_at', 'updated_at', 'version'] as const;

export const DATA_PATTERNS = {
  date: /^\d{4}-\d{2}-\d{2}$/,
  datetime: /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/,
  email: /^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}$/,
  phone: /^[0-9+\-() ]{3,32}$/,
  emailTarget: /^field:[A-Za-z][A-Za-z0-9_-]{0,63}$/,
  placeholder: /\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/g,
} as const;

function isRealDate(value: string): boolean {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d && y >= 1900 && y <= 2200;
}

/**
 * Validates one (already normalised) record value against its field. Mirror:
 * apps/platform-api/app/Domain/Data/FieldValue.php. Empty values: null.
 */
export function validateFieldValue(
  field: { type: string; required: boolean; options?: string[]; maxLength?: number; min?: number; max?: number },
  value: unknown,
): string | null {
  if (value === null || value === undefined) return field.required ? 'required' : null;
  switch (field.type) {
    case 'text':
    case 'longtext': {
      if (typeof value !== 'string') return 'must be text';
      const max = field.maxLength ?? (field.type === 'text' ? 255 : 10000);
      if (codePointLength(value) > max) return `must be at most ${max} characters`;
      if (PATTERNS.forbiddenControl.test(value)) return 'contains control characters';
      if (field.type === 'text' && PATTERNS.newline.test(value)) return 'must be a single line';
      return null;
    }
    case 'number':
    case 'integer':
      if (typeof value !== 'number' || !Number.isFinite(value)) return 'must be a number';
      if (field.type === 'integer' && !Number.isInteger(value)) return 'must be a whole number';
      if (field.min !== undefined && value < field.min) return `must be at least ${field.min}`;
      if (field.max !== undefined && value > field.max) return `must be at most ${field.max}`;
      return null;
    case 'boolean':
      return typeof value === 'boolean' ? null : 'must be true or false';
    case 'date':
      return typeof value === 'string' && DATA_PATTERNS.date.test(value) && isRealDate(value) ? null : 'must be a date (YYYY-MM-DD)';
    case 'datetime':
      return typeof value === 'string' && DATA_PATTERNS.datetime.test(value) && isRealDate(value) ? null : 'must be a date and time';
    case 'email':
      return typeof value === 'string' && value.length <= 254 && DATA_PATTERNS.email.test(value) ? null : 'must be an e-mail address';
    case 'phone':
      return typeof value === 'string' && DATA_PATTERNS.phone.test(value) ? null : 'must be a phone number';
    case 'url':
      return typeof value === 'string' && /^https?:\/\//.test(value) && isSafeHref(value) ? null : 'must be an http(s) URL';
    case 'select':
      return typeof value === 'string' && (field.options ?? []).includes(value) ? null : 'is not one of the options';
    default:
      return 'has an unsupported type';
  }
}
