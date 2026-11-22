/**
 * Minimal fetch client for the platform API (same origin via proxy).
 * - Sanctum SPA auth: session cookie + XSRF-TOKEN cookie echoed as X-XSRF-TOKEN.
 * - Errors are normalised to ApiError {status, code, message, details}.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown>;
  /** Full parsed response body (some endpoints return a report alongside a 4xx status). */
  readonly body: unknown;
  constructor(status: number, code: string, message: string, details: Record<string, unknown> = {}, body: unknown = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
    this.body = body;
  }

  /** Field errors from a 422 validation_failed response. */
  get fields(): Record<string, string[]> {
    return (this.details.fields as Record<string, string[]>) ?? {};
  }
}

let csrfReady: Promise<void> | null = null;

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

export function ensureCsrf(force = false): Promise<void> {
  if (!csrfReady || force) {
    csrfReady = fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined);
  }
  return csrfReady;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  headers?: Record<string, string>;
  signal?: AbortSignal;
}

export async function api<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  const method = opts.method ?? 'GET';
  const headers: Record<string, string> = { Accept: 'application/json', ...opts.headers };
  let body: BodyInit | undefined;
  if (opts.body instanceof FormData) {
    body = opts.body;
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(opts.body);
  }
  if (method !== 'GET') {
    await ensureCsrf();
    const token = readCookie('XSRF-TOKEN');
    if (token) headers['X-XSRF-TOKEN'] = token;
  }

  let res: Response;
  try {
    res = await fetch(`/api/v1${path}`, { method, headers, body, credentials: 'same-origin', signal: opts.signal });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError(0, 'network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ต');
  }

  if (res.status === 419 && method !== 'GET') {
    // CSRF token rotated (e.g. after login): refresh once and retry.
    await ensureCsrf(true);
    return api<T>(path, opts);
  }

  const text = await res.text();
  const json = text ? safeJson(text) : null;
  if (!res.ok) {
    const err = (json as { error?: { code?: string; message?: string; details?: Record<string, unknown> } } | null)?.error;
    throw new ApiError(res.status, err?.code ?? `http_${res.status}`, err?.message ?? 'เกิดข้อผิดพลาด กรุณาลองใหม่', err?.details ?? {}, json);
  }
  return json as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

export interface Envelope<T> {
  data: T;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
}

export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) return e.message;
  return 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
}
