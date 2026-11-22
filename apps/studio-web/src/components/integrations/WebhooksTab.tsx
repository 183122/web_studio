import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, RotateCcw, Trash2, Webhook } from 'lucide-react';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import { Button } from '../ui/Button.tsx';
import { Dialog } from '../ui/Dialog.tsx';
import { toast } from '../ui/Toast.tsx';
import { EmptyState, Spinner, formatDate } from '../ui/misc.tsx';

interface Endpoint { id: string; url: string; environment: 'preview' | 'production'; events: string[]; description: string; enabled: boolean; disabledReason: string | null; consecutiveFailures: number; createdAt: string; secret?: string }
interface Delivery { id: string; eventId: string; event: string; status: string; attempts: number; responseStatus: number | null; error: string | null; createdAt: string }

const EVENTS: Record<string, string> = { 'record.created': 'สร้างข้อมูล', 'record.updated': 'แก้ไขข้อมูล', 'record.deleted': 'ลบข้อมูล' };
const STATUS: Record<string, string> = { pending: 'รอส่ง', succeeded: 'สำเร็จ', failed: 'ล้มเหลว (จะลองใหม่)', dead: 'ล้มเหลว (หยุดลองใหม่)' };

/** M25: outbound webhooks for data events of this project. */
export function WebhooksTab({ projectId, env, canEdit }: { projectId: string; env: 'preview' | 'production'; canEdit: boolean }) {
  const qc = useQueryClient();
  const key = ['webhooks', projectId];
  const list = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<Endpoint[]>>(`/projects/${projectId}/webhooks`)).data });
  const [url, setUrl] = useState('');
  const [events, setEvents] = useState<string[]>(['record.created']);
  const [secret, setSecret] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [logFor, setLogFor] = useState<Endpoint | null>(null);
  const create = useMutation({
    mutationFn: async () => (await api<Envelope<Endpoint>>(`/projects/${projectId}/webhooks`, { method: 'POST', body: { url, events, environment: env } })).data,
    onSuccess: (e) => { setSecret(e.secret ?? null); setUrl(''); setError(''); qc.invalidateQueries({ queryKey: key }); },
    onError: (e) => setError(errorMessage(e)),
  });
  const act = (fn: () => Promise<unknown>) => fn().catch((e) => toast.error(errorMessage(e))).finally(() => qc.invalidateQueries({ queryKey: key }));
  const mine = (list.data ?? []).filter((e) => e.environment === env);

  return (
    <section aria-label="Webhooks" className="space-y-4 text-sm">
      <p className="text-slate-500">ส่งเหตุการณ์ของข้อมูล{env === 'production' ? 'จริง' : 'ทดลอง'}ไปยังระบบอื่นแบบ HTTPS POST พร้อมลายเซ็น <code>X-Studio-Signature</code> (HMAC-SHA256) — ปลายทางต้องเป็นที่อยู่สาธารณะ (ห้ามเครือข่ายภายใน)</p>
      {canEdit ? (
        <form className="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700" onSubmit={(e) => { e.preventDefault(); create.mutate(); }}>
          <label className="block"><span className="field-label">URL ปลายทาง</span>
            <input className="field-input" value={url} onChange={(e) => setUrl(e.target.value.trim())} placeholder="https://example.com/hooks/studio" aria-invalid={!!error} />
          </label>
          <fieldset className="flex flex-wrap gap-3"><legend className="field-label">เหตุการณ์</legend>
            {Object.entries(EVENTS).map(([k, v]) => (
              <label key={k} className="flex items-center gap-1.5"><input type="checkbox" checked={events.includes(k)} onChange={(e) => setEvents((c) => (e.target.checked ? [...c, k] : c.filter((x) => x !== k)))} /> {v}</label>
            ))}
          </fieldset>
          {error ? <p role="alert" className="text-red-700">{error}</p> : null}
          <Button type="submit" size="sm" loading={create.isPending} disabled={!url || events.length === 0}><Webhook className="h-4 w-4" /> เพิ่ม webhook</Button>
        </form>
      ) : null}
      {secret ? (
        <div role="status" className="rounded-xl bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-100">
          <p className="font-medium">Signing secret (แสดงครั้งเดียว — เก็บไว้ในระบบปลายทาง)</p>
          <div className="mt-1 flex items-center gap-2"><code data-testid="webhook-secret" className="break-all">{secret}</code>
            <Button size="sm" variant="ghost" aria-label="คัดลอก secret" onClick={() => navigator.clipboard?.writeText(secret).then(() => toast.success('คัดลอกแล้ว'))}><Copy className="h-4 w-4" /></Button></div>
        </div>
      ) : null}
      {list.isLoading ? <Spinner /> : mine.length === 0 ? <EmptyState icon={<Webhook className="h-10 w-10" />} title="ยังไม่มี webhook" /> : (
        <ul className="space-y-2">
          {mine.map((e) => (
            <li key={e.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
              <div className="flex flex-wrap items-center gap-2">
                <code className="break-all">{e.url}</code>
                <span className={`rounded-full px-2 text-xs ${e.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'}`}>{e.enabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}</span>
                <span className="text-xs text-slate-500">{e.events.map((x) => EVENTS[x]).join(', ')}</span>
                <div className="ml-auto flex gap-1">
                  <Button size="sm" variant="ghost" onClick={() => setLogFor(e)}>ประวัติการส่ง</Button>
                  {canEdit ? <Button size="sm" variant="ghost" onClick={() => act(() => api(`/projects/${projectId}/webhooks/${e.id}`, { method: 'PATCH', body: { enabled: !e.enabled } }))}>{e.enabled ? 'ปิด' : 'เปิด'}</Button> : null}
                  {canEdit ? <Button size="sm" variant="ghost" aria-label="ลบ webhook" onClick={() => window.confirm('ลบ webhook นี้?') && act(() => api(`/projects/${projectId}/webhooks/${e.id}`, { method: 'DELETE' }))}><Trash2 className="h-4 w-4" /></Button> : null}
                </div>
              </div>
              {e.disabledReason ? <p className="text-xs text-red-700">{e.disabledReason}</p> : null}
            </li>
          ))}
        </ul>
      )}
      {logFor ? <DeliveriesDialog projectId={projectId} endpoint={logFor} canEdit={canEdit} onClose={() => setLogFor(null)} /> : null}
    </section>
  );
}

function DeliveriesDialog({ projectId, endpoint, canEdit, onClose }: { projectId: string; endpoint: Endpoint; canEdit: boolean; onClose: () => void }) {
  const list = useQuery({ queryKey: ['deliveries', endpoint.id], queryFn: async () => (await api<Envelope<Delivery[]>>(`/projects/${projectId}/webhooks/${endpoint.id}/deliveries`)).data, refetchInterval: 3000 });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title="ประวัติการส่ง webhook" description={endpoint.url} wide>
      {list.isLoading ? <Spinner /> : (list.data ?? []).length === 0 ? <p className="text-sm text-slate-500">ยังไม่มีการส่ง</p> : (
        <table className="w-full text-left text-xs">
          <thead className="text-slate-500"><tr><th className="py-1">เวลา</th><th>เหตุการณ์</th><th>สถานะ</th><th>HTTP</th><th>ครั้ง</th><th /></tr></thead>
          <tbody>
            {list.data!.map((d) => (
              <tr key={d.id} className="border-t border-slate-100 dark:border-slate-800">
                <td className="py-1">{formatDate(d.createdAt)}</td><td>{EVENTS[d.event] ?? d.event}</td>
                <td title={d.error ?? ''} className={d.status === 'succeeded' ? 'text-emerald-700' : ['failed', 'dead'].includes(d.status) ? 'text-red-700' : ''}>{STATUS[d.status] ?? d.status}</td>
                <td>{d.responseStatus ?? '—'}</td><td>{d.attempts}</td>
                <td>{canEdit && ['failed', 'dead'].includes(d.status) ? <Button size="sm" variant="ghost" onClick={() => api(`/projects/${projectId}/webhook-deliveries/${d.id}/redeliver`, { method: 'POST' }).then(() => { toast.success('ส่งใหม่แล้ว'); list.refetch(); }, (e) => toast.error(errorMessage(e)))}><RotateCcw className="h-3.5 w-3.5" /> ส่งใหม่</Button> : null}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Dialog>
  );
}
