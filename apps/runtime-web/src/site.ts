import type { AppDefinition } from '@studio/app-schema';
import type { DataHost, SubmitResult } from '@studio/renderer';

export interface SiteBootstrap {
  release: { version: number; hash: string };
  document: AppDefinition;
  path: string;
  /** Static export: no platform backend; images are files under /_assets. */
  static?: boolean;
  assetFiles?: Record<string, string>;
}

/** The release document is embedded by the server as an inert JSON data block. */
export function readBootstrap(doc: Document = document): SiteBootstrap | null {
  const el = doc.getElementById('__SITE__');
  if (!el?.textContent) return null;
  try {
    return JSON.parse(el.textContent) as SiteBootstrap;
  } catch {
    return null;
  }
}

export interface AppUser { id: string; name: string; email: string; role: 'member' | 'admin' }

export class RuntimeError extends Error {
  constructor(public status: number, public code: string, message: string, public details: Record<string, unknown> = {}) {
    super(message);
  }
}

/** Same-origin calls to the runtime API. The custom header is required for writes (CSRF guard). */
export async function call<T>(path: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
  const res = await fetch(`/api/runtime/v1${path}`, {
    method: init.method ?? 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Runtime-Request': '1', ...(init.body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  });
  const json = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) {
    const err = json?.error ?? {};
    const fields = json?.errors ?? err.details?.fields;
    throw new RuntimeError(res.status, err.code ?? (res.status === 422 ? 'validation_failed' : 'error'), err.message ?? json?.message ?? 'เกิดข้อผิดพลาด', fields ? { fields } : err.details ?? {});
  }
  return json as T;
}

export function fieldErrors(e: RuntimeError): Record<string, string> {
  const fields = (e.details.fields ?? {}) as Record<string, string | string[]>;
  return Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
}

export const liveDataHost: DataHost = {
  async submit(modelId, data, meta): Promise<SubmitResult> {
    try {
      await call(`/models/${encodeURIComponent(modelId)}/records`, { method: 'POST', body: { data, meta } });
      return { ok: true };
    } catch (e) {
      if (!(e instanceof RuntimeError)) return { ok: false, message: 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่' };
      if (e.code === 'app_permission_denied') return { ok: false, message: 'กรุณาเข้าสู่ระบบก่อนส่งข้อมูล' };
      if (e.status === 429) return { ok: false, message: 'ส่งข้อมูลบ่อยเกินไป กรุณารอสักครู่' };
      const errs = fieldErrors(e);
      if (errs['meta.elapsedMs'] || errs['meta.hp']) return { ok: false, message: 'ระบบป้องกันสแปมปฏิเสธการส่ง กรุณาลองใหม่' };
      return { ok: false, message: e.status === 422 ? 'กรุณาตรวจสอบข้อมูลที่กรอก' : e.message, fieldErrors: errs };
    }
  },
  async list(modelId, q) {
    const params = new URLSearchParams({ page: String(q.page), perPage: String(Math.min(q.perPage, 50)) });
    if (q.search) params.set('search', q.search);
    try {
      const res = await call<{ data: { id: string; data: Record<string, unknown> }[]; meta: { total: number; lastPage: number } }>(`/models/${encodeURIComponent(modelId)}/records?${params}`);
      return { rows: res.data, total: res.meta.total, lastPage: res.meta.lastPage };
    } catch (e) {
      if (e instanceof RuntimeError && e.code === 'app_permission_denied') throw new Error('เข้าสู่ระบบด้วยบัญชีที่มีสิทธิ์เพื่อดูข้อมูลนี้');
      throw e;
    }
  },
};

/** Accounts are offered only when some permission grants members/admins more than visitors. */
export function siteHasAccounts(doc: AppDefinition): boolean {
  return doc.permissions.some((p) => p.rules.member.length > 0 || p.rules.admin.length > 0);
}

export function resolveAsset(ref: string, files?: Record<string, string>): string | undefined {
  const m = /^asset:([0-9A-Za-z]{26})$/.exec(ref);
  if (!m) return undefined;
  if (files) return files[m[1]];
  return `/_assets/${m[1]}`;
}

/** Static export: forms and tables need the platform; show a clear notice instead of failing. */
export const staticDataHost: DataHost = {
  async submit() {
    return { ok: false, message: 'แบบฟอร์มนี้ใช้งานไม่ได้ในเว็บไซต์แบบ static (ต้องเผยแพร่บนแพลตฟอร์มหรือมีเซิร์ฟเวอร์ของตัวเอง)' };
  },
  async list() {
    throw new Error('ข้อมูลในตารางไม่แสดงในเว็บไซต์แบบ static');
  },
};
