import { useState } from 'react';
import { Link } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, FileSpreadsheet, Play, RotateCcw, Trash2 } from 'lucide-react';
import type { DataModel } from '@studio/app-schema';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import { Button } from '../ui/Button.tsx';
import { Dialog } from '../ui/Dialog.tsx';
import { toast } from '../ui/Toast.tsx';
import { EmptyState, Spinner, formatDate } from '../ui/misc.tsx';

type Direction = 'pull' | 'push' | 'both';
interface SyncJob { id: string; kind: Direction; trigger: string; status: string; attempts: number; stats: Record<string, Record<string, number>> | null; errors: Array<{ row: number; field: string; message: string }>; error: string | null; createdAt: string; finishedAt: string | null }
interface SheetSyncRow {
  id: string; modelId: string; environment: 'preview' | 'production'; spreadsheetTitle: string | null; sheetTitle: string; spreadsheetUrl: string;
  mapping: Array<{ fieldId: string; column: string }>; keyFieldId: string | null; direction: Direction; conflictPolicy: string;
  intervalMinutes: number; status: 'active' | 'paused' | 'disconnected'; lastSyncedAt: string | null; lastJob: SyncJob | null;
}

const DIRECTION: Record<Direction, string> = { pull: 'นำเข้าจากชีต → แอป', push: 'ส่งออกจากแอป → ชีต', both: 'สองทาง' };
const POLICY: Record<string, string> = { app_wins: 'ข้อมูลในแอปชนะ', sheet_wins: 'ข้อมูลในชีตชนะ', skip: 'ข้ามแถวที่ขัดแย้ง (แจ้งในประวัติ)' };
const JOB_STATUS: Record<string, string> = { queued: 'รอคิว', running: 'กำลังซิงก์', retrying: 'รอลองใหม่', succeeded: 'สำเร็จ', partial: 'สำเร็จบางส่วน', failed: 'ไม่สำเร็จ', dead: 'ล้มเหลว (หยุดลองใหม่)' };
const INTERVALS = [[0, 'ซิงก์เองเท่านั้น'], [15, 'ทุก 15 นาที'], [60, 'ทุกชั่วโมง'], [360, 'ทุก 6 ชั่วโมง'], [1440, 'วันละครั้ง']] as const;

function statsText(j: SyncJob): string {
  const p = j.stats?.pull;
  const s = j.stats?.push;
  const parts: string[] = [];
  if (p) parts.push(`นำเข้า: ใหม่ ${p.created} · แก้ ${p.updated} · เหมือนเดิม ${p.unchanged}${p.conflicts ? ` · ขัดแย้ง ${p.conflicts}` : ''}`);
  if (s) parts.push(`ส่งออก: เพิ่มแถว ${s.appended} · แก้ ${s.updated} · เหมือนเดิม ${s.unchanged}${s.skipped ? ` · ข้าม ${s.skipped}` : ''}`);
  return parts.join(' | ');
}

/** M23 UI: Google Sheets syncs for one model. */
export function SheetsTab({ projectId, model, env, canEdit }: { projectId: string; model: DataModel; env: 'preview' | 'production'; canEdit: boolean }) {
  const qc = useQueryClient();
  const key = ['sheet-syncs', projectId];
  const syncs = useQuery({
    queryKey: key,
    queryFn: async () => (await api<Envelope<SheetSyncRow[]>>(`/projects/${projectId}/sheet-syncs`)).data,
    refetchInterval: (q) => (q.state.data?.some((s) => ['queued', 'running', 'retrying'].includes(s.lastJob?.status ?? '')) ? 2000 : false),
  });
  const [creating, setCreating] = useState(false);
  const [historyFor, setHistoryFor] = useState<SheetSyncRow | null>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: key });
  const act = (fn: () => Promise<unknown>, ok?: string) => fn().then(() => ok && toast.success(ok), (e) => toast.error(errorMessage(e))).finally(refresh);
  const mine = (syncs.data ?? []).filter((s) => s.modelId === model.id);

  return (
    <section aria-label="Google Sheets">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <p className="text-sm text-slate-500">ฐานข้อมูลของแอปเป็นข้อมูลหลักเสมอ — Google Sheets ใช้เป็นช่องทางนำเข้า/รายงาน · ต้องเชื่อมต่อ Google ที่ <Link className="text-brand-700 underline" to="/app/integrations">หน้าการเชื่อมต่อ</Link></p>
        {canEdit ? <Button size="sm" className="ml-auto" onClick={() => setCreating(true)}><FileSpreadsheet className="h-4 w-4" /> เชื่อมกับ Google Sheet</Button> : null}
      </div>
      {syncs.isLoading ? <Spinner /> : mine.length === 0 ? (
        <EmptyState icon={<FileSpreadsheet className="h-10 w-10" />} title="ยังไม่มีการซิงก์กับ Google Sheets" description={`เชื่อมตาราง ${model.label} กับแท็บใน Google Sheet เพื่อนำเข้าหรือส่งออกข้อมูล`} />
      ) : (
        <ul className="space-y-3">
          {mine.map((s) => (
            <li key={s.id} className="rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700" data-testid="sheet-sync">
              <div className="flex flex-wrap items-center gap-2">
                <a href={s.spreadsheetUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-medium text-brand-700 underline">{s.spreadsheetTitle ?? 'Google Sheet'} · {s.sheetTitle} <ExternalLink className="h-3.5 w-3.5" /></a>
                <span className="rounded-full bg-slate-100 px-2 text-xs dark:bg-navy-800">{DIRECTION[s.direction]}</span>
                <span className="rounded-full bg-slate-100 px-2 text-xs dark:bg-navy-800">{s.environment === 'production' ? 'ข้อมูลจริง' : 'ข้อมูลทดลอง'}</span>
                {s.status !== 'active' ? <span className="rounded-full bg-amber-100 px-2 text-xs text-amber-800">{s.status === 'disconnected' ? 'การเชื่อมต่อ Google ถูกยกเลิก' : 'หยุดชั่วคราว'}</span> : null}
                {canEdit ? (
                  <div className="ml-auto flex flex-wrap gap-1">
                    {s.direction !== 'push' ? <Button size="sm" variant="ghost" onClick={async () => {
                      try {
                        const r = (await api<Envelope<{ rows: number; created: number; updated: number; unchanged: number; conflicts: number; errors: SyncJob['errors'] }>>(`/projects/${projectId}/sheet-syncs/${s.id}/dry-run`, { method: 'POST' })).data;
                        toast.info(`ทดลองนำเข้า: ${r.rows} แถว · ใหม่ ${r.created} · แก้ ${r.updated} · เหมือนเดิม ${r.unchanged}${r.errors.length ? ` · ผิดพลาด ${r.errors.length} (แถว ${r.errors.slice(0, 3).map((e) => e.row).join(', ')})` : ''}`);
                      } catch (e) { toast.error(errorMessage(e)); }
                    }}>ทดลอง (dry run)</Button> : null}
                    <Button size="sm" disabled={s.status !== 'active'} onClick={() => act(() => api(`/projects/${projectId}/sheet-syncs/${s.id}/run`, { method: 'POST' }), 'เริ่มซิงก์แล้ว')}><Play className="h-4 w-4" /> ซิงก์ตอนนี้</Button>
                    <Button size="sm" variant="ghost" onClick={() => act(() => api(`/projects/${projectId}/sheet-syncs/${s.id}`, { method: 'PATCH', body: { status: s.status === 'active' ? 'paused' : 'active' } }))}>{s.status === 'active' ? 'หยุดชั่วคราว' : 'เปิดใช้งาน'}</Button>
                    <Button size="sm" variant="ghost" aria-label="ลบการซิงก์" onClick={() => window.confirm('ลบการซิงก์นี้? (ข้อมูลในแอปและในชีตไม่ถูกลบ)') && act(() => api(`/projects/${projectId}/sheet-syncs/${s.id}`, { method: 'DELETE' }), 'ลบแล้ว')}><Trash2 className="h-4 w-4" /></Button>
                  </div>
                ) : null}
              </div>
              <p className="mt-1 text-xs text-slate-500">
                จับคู่: {s.mapping.map((m) => `${model.fields.find((f) => f.id === m.fieldId)?.label ?? m.fieldId} ↔ ${m.column}`).join(', ')}
                {s.keyFieldId ? ` · คีย์: ${model.fields.find((f) => f.id === s.keyFieldId)?.label}` : ''}
                {s.direction === 'both' ? ` · เมื่อขัดแย้ง: ${POLICY[s.conflictPolicy]}` : ''}
                {' · '}{INTERVALS.find(([m]) => m === s.intervalMinutes)?.[1]}
              </p>
              {s.lastJob ? (
                <p className="mt-1 text-xs" aria-live="polite">
                  ล่าสุด: <span data-testid="sync-job-status" className={['failed', 'dead'].includes(s.lastJob.status) ? 'text-red-700' : s.lastJob.status === 'partial' ? 'text-amber-700' : 'text-emerald-700'}>{JOB_STATUS[s.lastJob.status] ?? s.lastJob.status}</span>
                  {' '}{formatDate(s.lastJob.createdAt)} {statsText(s.lastJob)} {s.lastJob.error ? `— ${s.lastJob.error}` : ''}
                  <button type="button" className="ml-2 text-brand-700 underline" onClick={() => setHistoryFor(s)}>ประวัติ</button>
                </p>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {creating ? <CreateSyncDialog projectId={projectId} model={model} env={env} onClose={() => setCreating(false)} onCreated={refresh} /> : null}
      {historyFor ? <HistoryDialog projectId={projectId} sync={historyFor} canEdit={canEdit} onClose={() => setHistoryFor(null)} onRetried={refresh} /> : null}
    </section>
  );
}

function HistoryDialog({ projectId, sync, canEdit, onClose, onRetried }: { projectId: string; sync: SheetSyncRow; canEdit: boolean; onClose: () => void; onRetried: () => void }) {
  const jobs = useQuery({ queryKey: ['sync-jobs', sync.id], queryFn: async () => (await api<Envelope<SyncJob[]>>(`/projects/${projectId}/sheet-syncs/${sync.id}/jobs`)).data, refetchInterval: 3000 });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={`ประวัติการซิงก์ · ${sync.sheetTitle}`} wide>
      {jobs.isLoading ? <Spinner /> : (
        <ul className="space-y-2 text-sm">
          {(jobs.data ?? []).map((j) => (
            <li key={j.id} className="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
              <div className="flex flex-wrap items-center gap-2">
                <b>{JOB_STATUS[j.status] ?? j.status}</b>
                <span className="text-xs text-slate-500">{formatDate(j.createdAt)} · {j.trigger === 'schedule' ? 'ตามเวลา' : j.trigger === 'retry' ? 'ลองใหม่' : 'สั่งเอง'} · ครั้งที่ {j.attempts}</span>
                {canEdit && ['failed', 'dead', 'partial'].includes(j.status) ? (
                  <Button size="sm" variant="ghost" className="ml-auto" onClick={() => api(`/projects/${projectId}/sync-jobs/${j.id}/retry`, { method: 'POST' }).then(() => { toast.success('สั่งลองใหม่แล้ว'); onRetried(); jobs.refetch(); }, (e) => toast.error(errorMessage(e)))}>
                    <RotateCcw className="h-4 w-4" /> ลองใหม่
                  </Button>
                ) : null}
              </div>
              <p className="text-xs">{statsText(j)}</p>
              {j.error ? <p className="text-xs text-red-700">{j.error}</p> : null}
              {j.errors.length > 0 ? (
                <details className="mt-1 text-xs"><summary className="cursor-pointer">แถวที่นำเข้าไม่ได้ ({j.errors.length})</summary>
                  <ul className="mt-1 list-disc pl-5">{j.errors.slice(0, 50).map((e, i) => <li key={i}>แถว {e.row} · {e.field}: {e.message}</li>)}</ul>
                </details>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </Dialog>
  );
}

function CreateSyncDialog({ projectId, model, env, onClose, onCreated }: { projectId: string; model: DataModel; env: 'preview' | 'production'; onClose: () => void; onCreated: () => void }) {
  const [url, setUrl] = useState('');
  const [info, setInfo] = useState<{ spreadsheetId: string; title: string; tabs: Array<{ title: string }> } | null>(null);
  const [tab, setTab] = useState('');
  const [preview, setPreview] = useState<{ header: string[]; rows: string[][]; totalRows: number } | null>(null);
  const [direction, setDirection] = useState<Direction>('pull');
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [keyField, setKeyField] = useState<string>('');
  const [policy, setPolicy] = useState('app_wins');
  const [interval, setIntervalMinutes] = useState(0);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError('');
    try { await fn(); } catch (e) { setError(errorMessage(e)); } finally { setBusy(false); }
  };
  const inspect = () => run(async () => {
    const d = (await api<Envelope<NonNullable<typeof info>>>(`/projects/${projectId}/sheets/inspect`, { method: 'POST', body: { url } })).data;
    setInfo(d);
    setTab(d.tabs[0]?.title ?? '');
    setPreview(null);
  });
  const loadPreview = (t: string) => run(async () => {
    const d = (await api<Envelope<NonNullable<typeof preview>>>(`/projects/${projectId}/sheets/preview`, { method: 'POST', body: { spreadsheetId: info!.spreadsheetId, sheetTitle: t } })).data;
    setPreview(d);
    // Suggest columns by matching labels/names; empty sheets get the field labels (created on push).
    const guess: Record<string, string> = {};
    for (const f of model.fields) guess[f.id] = d.header.find((h) => [f.label, f.name].includes(h.trim())) ?? (d.header.length === 0 ? f.label : '');
    setMapping(guess);
    setKeyField(model.fields.find((f) => f.unique)?.id ?? '');
    if (d.header.length === 0) setDirection('push');
  });
  const save = () => run(async () => {
    const m = Object.entries(mapping).filter(([, c]) => c.trim() !== '').map(([fieldId, column]) => ({ fieldId, column: column.trim() }));
    await api(`/projects/${projectId}/sheet-syncs`, { method: 'POST', body: {
      modelId: model.id, environment: env, spreadsheetId: info!.spreadsheetId, spreadsheetTitle: info!.title, sheetTitle: tab,
      mapping: m, keyFieldId: direction !== 'push' && keyField ? keyField : null, direction, conflictPolicy: policy, intervalMinutes: interval,
    } });
    toast.success('สร้างการซิงก์แล้ว');
    onCreated();
    onClose();
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={`เชื่อม ${model.label} กับ Google Sheet`} description={`ใช้กับ${env === 'production' ? 'ข้อมูลจริง' : 'ข้อมูลทดลอง'} (เปลี่ยนได้ที่ปุ่มด้านบนของหน้า)`} wide>
      <div className="space-y-4 text-sm">
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); void inspect(); }}>
          <label className="min-w-0 flex-1"><span className="field-label">ลิงก์ Google Sheet</span>
            <input className="field-input" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://docs.google.com/spreadsheets/d/…" />
          </label>
          <Button type="submit" variant="secondary" loading={busy && !info} disabled={!url}>ตรวจสอบ</Button>
        </form>
        {info ? (
          <div className="flex flex-wrap items-end gap-2">
            <p className="w-full">สเปรดชีต: <b>{info.title}</b></p>
            <label><span className="field-label">แท็บ</span>
              <select className="field-input w-auto" value={tab} onChange={(e) => { setTab(e.target.value); setPreview(null); }}>
                {info.tabs.map((t) => <option key={t.title} value={t.title}>{t.title}</option>)}
              </select>
            </label>
            <Button variant="secondary" onClick={() => loadPreview(tab)} disabled={!tab} loading={busy && !!info && !preview}>ดูตัวอย่างคอลัมน์</Button>
          </div>
        ) : null}
        {preview ? (
          <>
            <div className="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
              {preview.header.length === 0 ? <p className="p-3 text-slate-500">แท็บนี้ว่าง — การส่งออกจะสร้างหัวคอลัมน์ให้</p> : (
                <table className="w-full text-xs"><thead className="bg-slate-50 dark:bg-navy-800"><tr>{preview.header.map((h, i) => <th key={i} className="px-2 py-1 text-left">{h}</th>)}</tr></thead>
                  <tbody>{preview.rows.map((r, i) => <tr key={i} className="border-t border-slate-100 dark:border-slate-800">{preview.header.map((_, j) => <td key={j} className="px-2 py-1">{r[j] ?? ''}</td>)}</tr>)}</tbody></table>
              )}
              <p className="px-2 py-1 text-xs text-slate-500">ทั้งหมด {preview.totalRows} แถว (แสดง 5 แถวแรก)</p>
            </div>
            <fieldset className="grid gap-2 sm:grid-cols-3">
              <legend className="field-label">ทิศทาง</legend>
              {(Object.keys(DIRECTION) as Direction[]).map((d) => (
                <label key={d} className="flex items-center gap-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700"><input type="radio" name="direction" checked={direction === d} onChange={() => setDirection(d)} /> {DIRECTION[d]}</label>
              ))}
            </fieldset>
            <div>
              <p className="field-label">จับคู่ฟิลด์กับคอลัมน์</p>
              <div className="grid gap-2 sm:grid-cols-2">
                {model.fields.map((f) => (
                  <label key={f.id} className="flex items-center gap-2">
                    <span className="w-32 shrink-0 truncate">{f.label}{f.required ? ' *' : ''}</span>
                    {preview.header.length > 0 && direction === 'pull' ? (
                      <select aria-label={`คอลัมน์สำหรับ ${f.label}`} className="field-input" value={mapping[f.id] ?? ''} onChange={(e) => setMapping((m) => ({ ...m, [f.id]: e.target.value }))}>
                        <option value="">— ไม่จับคู่ —</option>
                        {preview.header.filter((h) => h && h !== '_studio_id').map((h) => <option key={h} value={h}>{h}</option>)}
                      </select>
                    ) : (
                      <input aria-label={`คอลัมน์สำหรับ ${f.label}`} className="field-input" value={mapping[f.id] ?? ''} onChange={(e) => setMapping((m) => ({ ...m, [f.id]: e.target.value }))} placeholder="ชื่อคอลัมน์ (เว้นว่าง = ไม่ซิงก์)" />
                    )}
                  </label>
                ))}
              </div>
              <p className="mt-1 text-xs text-slate-500">การส่งออกจะเพิ่มคอลัมน์ <code>_studio_id</code> เพื่อจับคู่แถวกับข้อมูลในแอป · คอลัมน์อื่นในชีตไม่ถูกแก้ไข</p>
            </div>
            {direction !== 'push' ? (
              <label className="block"><span className="field-label">ฟิลด์คีย์สำหรับจับคู่แถวเดิม (ป้องกันข้อมูลซ้ำ)</span>
                <select className="field-input w-auto" value={keyField} onChange={(e) => setKeyField(e.target.value)}>
                  <option value="">— ไม่มี (ใช้ _studio_id เท่านั้น) —</option>
                  {model.fields.filter((f) => mapping[f.id]).map((f) => <option key={f.id} value={f.id}>{f.label}</option>)}
                </select>
              </label>
            ) : null}
            <div className="flex flex-wrap gap-4">
              {direction === 'both' ? (
                <label><span className="field-label">เมื่อแก้ไขทั้งสองฝั่ง</span>
                  <select className="field-input w-auto" value={policy} onChange={(e) => setPolicy(e.target.value)}>{Object.entries(POLICY).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
                </label>
              ) : null}
              <label><span className="field-label">ความถี่</span>
                <select className="field-input w-auto" value={interval} onChange={(e) => setIntervalMinutes(Number(e.target.value))}>{INTERVALS.map(([m, l]) => <option key={m} value={m}>{l}</option>)}</select>
              </label>
            </div>
          </>
        ) : null}
        {error ? <p role="alert" className="text-red-700">{error}</p> : null}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>ยกเลิก</Button>
          <Button onClick={save} disabled={!preview || busy}>บันทึกการซิงก์</Button>
        </div>
      </div>
    </Dialog>
  );
}


