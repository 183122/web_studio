import type { DataHost, SubmitResult } from '@studio/renderer';
import { api, ApiError } from './api.ts';
import type { ListMeta, RecordRow } from './types.ts';

export type PreviewRole = 'public' | 'member' | 'admin';

/** DataHost for the private preview: talks to the preview-runtime API acting as the chosen app role. */
export function previewDataHost(projectId: string, role: PreviewRole): DataHost {
  const headers = { 'X-Preview-Role': role };
  return {
    async submit(modelId, data, meta): Promise<SubmitResult> {
      try {
        await api(`/projects/${projectId}/preview-runtime/${modelId}/records`, { method: 'POST', body: { data, meta }, headers });
        return { ok: true };
      } catch (e) {
        if (e instanceof ApiError) {
          if (e.code === 'app_permission_denied') return { ok: false, message: 'บทบาทนี้ไม่มีสิทธิ์ส่งข้อมูล' };
          if (e.status === 429) return { ok: false, message: 'ส่งข้อมูลบ่อยเกินไป กรุณารอสักครู่' };
          const fields = (e.details.fields ?? {}) as Record<string, string | string[]>;
          if (fields['meta.elapsedMs'] || fields['meta.hp']) return { ok: false, message: 'ระบบป้องกันสแปมปฏิเสธการส่ง กรุณาลองใหม่' };
          const fieldErrors = Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
          return { ok: false, message: e.message, fieldErrors };
        }
        return { ok: false, message: 'ส่งข้อมูลไม่สำเร็จ' };
      }
    },
    async list(modelId, q) {
      const params = new URLSearchParams({ page: String(q.page), perPage: String(q.perPage) });
      if (q.search) params.set('search', q.search);
      try {
        const res = await api<{ data: RecordRow[]; meta: ListMeta }>(`/projects/${projectId}/preview-runtime/${modelId}/records?${params}`, { headers });
        return { rows: res.data, total: res.meta.total, lastPage: res.meta.lastPage };
      } catch (e) {
        if (e instanceof ApiError && e.code === 'app_permission_denied') throw new Error('บทบาทนี้ไม่มีสิทธิ์ดูข้อมูลในตารางนี้');
        throw e;
      }
    },
  };
}
