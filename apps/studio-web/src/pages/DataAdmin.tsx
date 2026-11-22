import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Tabs } from 'radix-ui';
import { ArrowLeft, Download, KeyRound, Plus, Trash2, Upload, Database, History, Workflow as WorkflowIcon, Copy, Users as UsersIcon, FileSpreadsheet as SheetIcon, Webhook as WebhookIcon } from 'lucide-react';
import type { DataModel } from '@studio/app-schema';
import { api, ApiError, errorMessage, type Envelope } from '../lib/api.ts';
import { useProject, type SchemaHead } from '../lib/queries.ts';
import type { ApiKey, ListMeta, RecordRow, WorkflowRunRow } from '../lib/types.ts';
import { Button } from '../components/ui/Button.tsx';
import { Dialog } from '../components/ui/Dialog.tsx';
import { Field } from '../components/ui/Field.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { EmptyState, ErrorState, FullPageSpinner, Spinner, ThemeToggle, formatDate } from '../components/ui/misc.tsx';
import { FieldInput, displayValue } from '../components/data/FieldInput.tsx';
import { SheetsTab } from '../components/integrations/SheetsTab.tsx';
import { WebhooksTab } from '../components/integrations/WebhooksTab.tsx';
import { Attachments } from '../components/integrations/Attachments.tsx';

const FIELD_ERRORS: Record<string, string> = {
  required: 'จำเป็นต้องกรอก',
  'already exists': 'ค่านี้มีอยู่แล้ว (ต้องไม่ซ้ำ)',
  'unknown field': 'ไม่มีฟิลด์นี้',
};

/** Generated admin (M17): CRUD over the project's preview data, CSV, audit, API keys, workflow runs. */
export default function DataAdmin() {
  const { projectId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const project = useProject(projectId);
  // Always fetch the latest saved definition (the editor may have changed models in another tab).
  const schema = useQuery({ queryKey: ['schema-latest', projectId], queryFn: async () => (await api<Envelope<SchemaHead>>(`/projects/${projectId}/schema`)).data, refetchOnWindowFocus: true });
  const models = schema.data?.document.dataModels ?? [];
  const model = models.find((m) => m.id === params.get('model')) ?? models[0];
  const canEdit = project.data?.role !== 'viewer';
  const env: DataEnv = params.get('env') === 'production' ? 'production' : 'preview';
  const setEnv = (e: DataEnv) => setParams({ ...Object.fromEntries(params), env: e });

  if (project.error || schema.error) return <div className="p-8"><ErrorState message={errorMessage(project.error ?? schema.error)} /></div>;
  if (!project.data || !schema.data) return <FullPageSpinner />;

  return (
    <div className="flex min-h-full flex-col">
      <header className="flex h-14 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-navy-900">
        <Link to={`/app/projects/${projectId}`} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm hover:bg-slate-100 dark:hover:bg-navy-800"><ArrowLeft className="h-4 w-4" /> ตัวแก้ไข</Link>
        <h1 className="truncate font-semibold">{project.data.name} · ข้อมูล</h1>
        <div role="radiogroup" aria-label="สภาพแวดล้อมข้อมูล" className="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-navy-800">
          {([['preview', 'ข้อมูลทดลอง (preview)'], ['production', 'ข้อมูลจริง (เว็บไซต์ที่เผยแพร่)']] as const).map(([id, label]) => (
            <button key={id} type="button" role="radio" aria-checked={env === id} onClick={() => setEnv(id)}
              className={`rounded-md px-2.5 py-1 ${env === id ? (id === 'production' ? 'bg-emerald-600 text-white' : 'bg-amber-500 text-white') : 'text-slate-600 dark:text-slate-300'}`}>{label}</button>
          ))}
        </div>
        <span className="ml-auto" />
        <ThemeToggle />
      </header>
      {models.length === 0 ? (
        <div className="p-8">
          <EmptyState icon={<Database className="h-10 w-10" />} title="ยังไม่มีตารางข้อมูล" description='สร้างตารางในตัวแก้ไข → แผง "ฐานข้อมูล" แล้วกลับมาจัดการข้อมูลที่นี่' />
        </div>
      ) : (
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6">
          <div role="tablist" aria-label="ตารางข้อมูล" className="mb-4 flex flex-wrap gap-2">
            {models.map((m) => (
              <button key={m.id} role="tab" type="button" aria-selected={m.id === model?.id} onClick={() => setParams({ model: m.id, env })}
                className={`rounded-lg px-3 py-1.5 text-sm ${m.id === model?.id ? 'bg-brand-700 text-white' : 'border border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-navy-800'}`}>
                {m.label} <span className="opacity-70">({m.name})</span>
              </button>
            ))}
          </div>
          {model ? (
            <Tabs.Root defaultValue="records">
              <Tabs.List className="mb-4 flex gap-1 border-b border-slate-200 text-sm dark:border-slate-800">
                {[['records', 'ข้อมูล', Database], ['audit', 'ประวัติการแก้ไข', History], ['runs', 'Workflow runs', WorkflowIcon], ['keys', 'API keys', KeyRound], ['users', 'ผู้ใช้แอป', UsersIcon], ['sheets', 'Google Sheets', SheetIcon], ['webhooks', 'Webhooks', WebhookIcon]].map(([v, label, Icon]) => {
                  const I = Icon as typeof Database;
                  return (
                    <Tabs.Trigger key={v as string} value={v as string} className="inline-flex items-center gap-1.5 px-3 py-2 data-[state=active]:border-b-2 data-[state=active]:border-brand-600 data-[state=active]:font-medium">
                      <I className="h-4 w-4" /> {label as string}
                    </Tabs.Trigger>
                  );
                })}
              </Tabs.List>
              <Tabs.Content value="records"><RecordsTab key={`${model.id}-${env}`} projectId={projectId} model={model} canEdit={canEdit} env={env} /></Tabs.Content>
              <Tabs.Content value="audit"><AuditTab projectId={projectId} model={model} env={env} /></Tabs.Content>
              <Tabs.Content value="runs"><RunsTab projectId={projectId} /></Tabs.Content>
              <Tabs.Content value="sheets"><SheetsTab key={`${model.id}-${env}`} projectId={projectId} model={model} env={env} canEdit={canEdit} /></Tabs.Content>
              <Tabs.Content value="webhooks"><WebhooksTab key={env} projectId={projectId} env={env} canEdit={canEdit} /></Tabs.Content>
              <Tabs.Content value="users"><AppUsersTab projectId={projectId} canEdit={canEdit} /></Tabs.Content>
              <Tabs.Content value="keys"><KeysTab projectId={projectId} canEdit={canEdit} models={models} /></Tabs.Content>
            </Tabs.Root>
          ) : null}
        </div>
      )}
    </div>
  );
}

function useDebounced<T>(v: T, ms = 300): T {
  const [d, setD] = useState(v);
  useEffect(() => {
    const t = setTimeout(() => setD(v), ms);
    return () => clearTimeout(t);
  }, [v, ms]);
  return d;
}

type DataEnv = 'preview' | 'production';
/** Appends ?env=production for the live site's data; preview is the server default. */
const withEnv = (url: string, env: DataEnv) => (env === 'production' ? `${url}${url.includes('?') ? '&' : '?'}env=production` : url);

function RecordsTab({ projectId, model, canEdit, env }: { projectId: string; model: DataModel; canEdit: boolean; env: DataEnv }) {
  const qc = useQueryClient();
  const base = `/projects/${projectId}/data/${model.id}`;
  const [searchInput, setSearchInput] = useState('');
  const search = useDebounced(searchInput.trim());
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [sort, setSort] = useState<{ field: string; dir: 'asc' | 'desc' }>({ field: 'created_at', dir: 'desc' });
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [editing, setEditing] = useState<RecordRow | 'new' | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const filterable = model.fields.filter((f) => f.type === 'select' || f.type === 'boolean');
  const columns = model.fields.slice(0, 8);

  const key = ['records', projectId, model.id, { search, filters, sort, page, env }];
  const list = useQuery({
    queryKey: key,
    queryFn: async () => {
      const q = new URLSearchParams({ page: String(page), perPage: '20', sort: sort.field, dir: sort.dir });
      if (search) q.set('search', search);
      for (const [k, v] of Object.entries(filters)) if (v) q.set(`filter[${k}]`, v);
      return api<{ data: RecordRow[]; meta: ListMeta }>(withEnv(`${base}/records?${q}`, env));
    },
    placeholderData: keepPreviousData,
  });
  const refresh = () => qc.invalidateQueries({ queryKey: ['records', projectId, model.id] });
  useEffect(() => setSelected(new Set()), [search, filters, page, sort]);

  const rows = list.data?.data ?? [];
  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));

  return (
    <section aria-label={`ข้อมูลใน ${model.label}`}>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <input type="search" className="field-input h-9 w-64" placeholder="ค้นหา…" aria-label="ค้นหาข้อมูล" value={searchInput} onChange={(e) => { setSearchInput(e.target.value); setPage(1); }} />
        {filterable.map((f) => (
          <label key={f.id} className="flex items-center gap-1 text-sm">
            <span className="text-slate-500">{f.label}</span>
            <select className="field-input h-9 w-auto" value={filters[f.id] ?? ''} onChange={(e) => { setFilters((s) => ({ ...s, [f.id]: e.target.value })); setPage(1); }}>
              <option value="">ทั้งหมด</option>
              {(f.type === 'boolean' ? ['true', 'false'] : f.options ?? []).map((o) => <option key={o} value={o}>{f.type === 'boolean' ? (o === 'true' ? 'ใช่' : 'ไม่ใช่') : o}</option>)}
            </select>
          </label>
        ))}
        <span className="ml-auto" />
        {canEdit && selected.size > 0 ? (
          <Button variant="danger" size="sm" onClick={async () => {
            if (!window.confirm(`ลบ ${selected.size} รายการ?`)) return;
            try {
              const r = await api<Envelope<{ deleted: number }>>(withEnv(`${base}/records/bulk-delete`, env), { method: 'POST', body: { ids: [...selected] } });
              toast.success(`ลบ ${r.data.deleted} รายการแล้ว`);
              refresh();
            } catch (e) {
              toast.error(errorMessage(e));
            }
          }}><Trash2 className="h-4 w-4" /> ลบที่เลือก ({selected.size})</Button>
        ) : null}
        <a href={withEnv(`/api/v1${base}/export`, env)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-300 px-3 text-sm hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-navy-800"><Download className="h-4 w-4" /> ส่งออก CSV</a>
        {canEdit ? <Button variant="secondary" size="sm" onClick={() => setImportOpen(true)}><Upload className="h-4 w-4" /> นำเข้า CSV</Button> : null}
        {canEdit ? <Button size="sm" onClick={() => setEditing('new')}><Plus className="h-4 w-4" /> เพิ่มข้อมูล</Button> : null}
      </div>

      {list.isLoading ? <Spinner /> : list.error ? <ErrorState message={errorMessage(list.error)} onRetry={() => list.refetch()} /> : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-navy-900">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left dark:bg-navy-800">
              <tr>
                {canEdit ? <th className="w-10 px-3 py-2"><input type="checkbox" aria-label="เลือกทั้งหมด" checked={allSelected} onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)))} /></th> : null}
                {columns.map((c) => (
                  <th key={c.id} scope="col" className="px-3 py-2 font-medium" aria-sort={sort.field === c.id ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                    <button type="button" className="hover:underline" onClick={() => setSort((s) => ({ field: c.id, dir: s.field === c.id && s.dir === 'asc' ? 'desc' : 'asc' }))}>
                      {c.label}{sort.field === c.id ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : ''}
                    </button>
                  </th>
                ))}
                <th scope="col" className="px-3 py-2 font-medium">
                  <button type="button" className="hover:underline" onClick={() => setSort((s) => ({ field: 'created_at', dir: s.field === 'created_at' && s.dir === 'desc' ? 'asc' : 'desc' }))}>สร้างเมื่อ</button>
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={columns.length + 2} className="px-3 py-10 text-center text-slate-500">{search || Object.values(filters).some(Boolean) ? 'ไม่พบข้อมูลที่ตรงเงื่อนไข' : 'ยังไม่มีข้อมูล'}</td></tr>
              ) : rows.map((r) => (
                <tr key={r.id} className="border-t border-slate-100 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-navy-800/60">
                  {canEdit ? <td className="px-3 py-2"><input type="checkbox" aria-label={`เลือก ${String(r.data[model.displayFieldId] ?? r.id)}`} checked={selected.has(r.id)} onChange={() => setSelected((s) => { const n = new Set(s); if (n.has(r.id)) n.delete(r.id); else n.add(r.id); return n; })} /></td> : null}
                  {columns.map((c, i) => (
                    <td key={c.id} className="max-w-60 truncate px-3 py-2">
                      {i === 0 ? <button type="button" className="font-medium text-brand-700 hover:underline dark:text-brand-300" onClick={() => setEditing(r)}>{displayValue(c, r.data[c.id])}</button> : displayValue(c, r.data[c.id])}
                    </td>
                  ))}
                  <td className="whitespace-nowrap px-3 py-2 text-slate-500">{formatDate(r.createdAt)} <span className="text-[11px]">({r.source})</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {list.data && list.data.meta.lastPage > 1 ? (
        <nav aria-label="เปลี่ยนหน้า" className="mt-3 flex items-center justify-end gap-2 text-sm">
          <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>ก่อนหน้า</Button>
          <span>หน้า {page} / {list.data.meta.lastPage}</span>
          <Button size="sm" variant="secondary" disabled={page >= list.data.meta.lastPage} onClick={() => setPage((p) => p + 1)}>ถัดไป</Button>
        </nav>
      ) : null}
      <p className="mt-2 text-xs text-slate-500">ทั้งหมด {list.data?.meta.total ?? 0} รายการ</p>

      {editing ? <RecordDialog projectId={projectId} env={env} base={base} model={model} record={editing === 'new' ? null : editing} canEdit={canEdit} onClose={() => setEditing(null)} onSaved={refresh} /> : null}
      <ImportDialog env={env} open={importOpen} onOpenChange={setImportOpen} base={base} onDone={refresh} />
    </section>
  );
}

function RecordDialog({ projectId, env, base, model, record, canEdit, onClose, onSaved }: { projectId: string; env: DataEnv; base: string; model: DataModel; record: RecordRow | null; canEdit: boolean; onClose: () => void; onSaved: () => void }) {
  const [values, setValues] = useState<Record<string, unknown>>(() => ({ ...(record?.data ?? {}) }));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      if (record) await api(withEnv(`${base}/records/${record.id}`, env), { method: 'PATCH', body: { data: values, version: record.version } });
      else await api(withEnv(`${base}/records`, env), { method: 'POST', body: { data: values } });
      toast.success('บันทึกแล้ว');
      onSaved();
      onClose();
    } catch (e) {
      if (e instanceof ApiError && e.code === 'record_invalid') {
        const fields = (e.details.fields ?? {}) as Record<string, string>;
        setErrors(Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, FIELD_ERRORS[v] ?? v])));
      } else toast.error(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={record ? `แก้ไขข้อมูล (v${record.version})` : `เพิ่มข้อมูลใน ${model.label}`}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void save(); }}>
        <fieldset disabled={!canEdit} className="space-y-3">
          {model.fields.map((f) => (
            <FieldInput key={f.id} id={`rec-${f.id}`} field={f} value={values[f.id]} error={errors[f.id]} onChange={(v) => setValues((s) => ({ ...s, [f.id]: v }))} />
          ))}
        </fieldset>
        {Object.keys(errors).length > 0 ? <p role="alert" className="text-sm text-red-600">กรุณาแก้ไขข้อมูลที่ไม่ถูกต้อง</p> : null}
        <div className="flex justify-between gap-2 pt-2">
          {record && canEdit ? (
            <Button variant="danger" size="sm" onClick={async () => {
              if (!window.confirm('ลบข้อมูลนี้?')) return;
              try {
                await api(withEnv(`${base}/records/${record.id}`, env), { method: 'DELETE' });
                toast.success('ลบแล้ว');
                onSaved();
                onClose();
              } catch (e) {
                toast.error(errorMessage(e));
              }
            }}><Trash2 className="h-4 w-4" /> ลบ</Button>
          ) : <span />}
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onClose}>ปิด</Button>
            {canEdit ? <Button type="submit" loading={busy}>บันทึก</Button> : null}
          </div>
        </div>
      </form>
      {record ? <Attachments projectId={projectId} modelId={model.id} recordId={record.id} env={env} canEdit={canEdit} /> : null}
    </Dialog>
  );
}

function ImportDialog({ env, open, onOpenChange, base, onDone }: { env: DataEnv; open: boolean; onOpenChange: (o: boolean) => void; base: string; onDone: () => void }) {
  const input = useRef<HTMLInputElement>(null);
  const [report, setReport] = useState<{ rows: number; created: number; dryRun: boolean; errors: Array<{ row: number; field: string; message: string }> } | null>(null);
  const [busy, setBusy] = useState(false);
  const run = async (dryRun: boolean) => {
    const file = input.current?.files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    body.append('dryRun', dryRun ? '1' : '0');
    setBusy(true);
    try {
      const r = await api<Envelope<NonNullable<typeof report>>>(withEnv(`${base}/import`, env), { method: 'POST', body });
      setReport(r.data);
      if (!dryRun) {
        toast.success(`นำเข้า ${r.data.created} รายการแล้ว`);
        onDone();
      }
    } catch (e) {
      const reportBody = e instanceof ApiError ? (e.body as { data?: NonNullable<typeof report> } | null) : null;
      if (reportBody?.data?.errors) {
        setReport(reportBody.data);
        return;
      }
      toast.error(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => { onOpenChange(o); if (!o) setReport(null); }} title="นำเข้าข้อมูลจาก CSV" description="แถวแรกต้องเป็นชื่อฟิลด์ (name) หรือป้ายกำกับ ระบบจะนำเข้าเฉพาะเมื่อทุกแถวถูกต้อง">
      <div className="space-y-3 text-sm">
        <input ref={input} type="file" accept=".csv,text/csv" aria-label="ไฟล์ CSV" onChange={() => setReport(null)} />
        {report ? (
          <div className={`rounded-lg p-3 ${report.errors.length ? 'bg-red-50 text-red-900 dark:bg-red-950 dark:text-red-100' : 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'}`} role="status">
            <p>{report.rows} แถว · {report.dryRun ? 'ตรวจสอบเท่านั้น' : `นำเข้าแล้ว ${report.created}`} · ข้อผิดพลาด {report.errors.length}</p>
            <ul className="mt-1 max-h-40 list-disc overflow-auto pl-5">{report.errors.map((e, i) => <li key={i}>แถว {e.row} · {e.field}: {e.message}</li>)}</ul>
          </div>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" loading={busy} onClick={() => run(true)}>ตรวจสอบก่อน (dry run)</Button>
          <Button loading={busy} onClick={() => run(false)}>นำเข้า</Button>
        </div>
      </div>
    </Dialog>
  );
}

function AuditTab({ projectId, model, env }: { projectId: string; model: DataModel; env: DataEnv }) {
  const q = useQuery({
    queryKey: ['data-audit', projectId, model.id],
    queryFn: () => api<{ data: Array<{ id: number; recordId: string; action: string; actorType: string; changes: unknown; createdAt: string }> }>(withEnv(`/projects/${projectId}/data/${model.id}/audit`, env)),
  });
  if (q.isLoading) return <Spinner />;
  if (q.error) return <ErrorState message={errorMessage(q.error)} />;
  return (
    <ol className="space-y-2 text-sm">
      {q.data!.data.length === 0 ? <li className="text-slate-500">ยังไม่มีประวัติ</li> : q.data!.data.map((l) => (
        <li key={l.id} className="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
          <span className="font-medium">{l.action}</span> · {l.actorType} · <span className="text-slate-500">{formatDate(l.createdAt)}</span>
          <code className="mt-1 block truncate text-xs text-slate-500">{l.recordId} {JSON.stringify(l.changes)}</code>
        </li>
      ))}
    </ol>
  );
}

function RunsTab({ projectId }: { projectId: string }) {
  const q = useQuery({ queryKey: ['workflow-runs', projectId], queryFn: () => api<{ data: WorkflowRunRow[] }>(`/projects/${projectId}/workflow-runs`) });
  if (q.isLoading) return <Spinner />;
  if (q.error) return <ErrorState message={errorMessage(q.error)} />;
  const tone: Record<string, string> = { succeeded: 'text-emerald-700', failed: 'text-red-600', skipped: 'text-slate-500', running: 'text-amber-600' };
  return (
    <ol className="space-y-2 text-sm">
      {q.data!.data.length === 0 ? <li className="text-slate-500">ยังไม่มีการทำงานของ workflow</li> : q.data!.data.map((r) => (
        <li key={r.id} className="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
          <span className={`font-medium ${tone[r.status] ?? ''}`}>{r.status}</span> · {r.workflowId} · {r.event} · <span className="text-slate-500">{formatDate(r.createdAt)}</span>
          {r.error ? <p className="text-xs text-red-600">{r.error}</p> : null}
          <code className="mt-1 block truncate text-xs text-slate-500">{JSON.stringify(r.steps)}</code>
        </li>
      ))}
    </ol>
  );
}

function KeysTab({ projectId, canEdit, models }: { projectId: string; canEdit: boolean; models: DataModel[] }) {
  const qc = useQueryClient();
  const keys = useQuery({ queryKey: ['api-keys', projectId], queryFn: () => api<Envelope<ApiKey[]>>(`/projects/${projectId}/api-keys`), enabled: canEdit });
  const [name, setName] = useState('');
  const [role, setRole] = useState<ApiKey['role']>('admin');
  const [scopes, setScopes] = useState<string[]>(['records:read']);
  const [created, setCreated] = useState<string | null>(null);
  const example = useMemo(() => `curl -H "Authorization: Bearer <API_KEY>" ${location.origin}/api/v1/apps/${projectId}/models/${models[0]?.name ?? 'model'}/records`, [projectId, models]);
  if (!canEdit) return <p className="text-sm text-slate-500">เฉพาะผู้มีสิทธิ์แก้ไขเท่านั้นที่จัดการ API keys ได้</p>;
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <form className="space-y-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-800" onSubmit={async (e) => {
        e.preventDefault();
        try {
          const r = await api<Envelope<ApiKey>>(`/projects/${projectId}/api-keys`, { method: 'POST', body: { name, role, scopes } });
          setCreated(r.data.token ?? null);
          setName('');
          qc.invalidateQueries({ queryKey: ['api-keys', projectId] });
        } catch (err) {
          toast.error(errorMessage(err));
        }
      }}>
        <h3 className="font-semibold">สร้าง API key</h3>
        <Field label="ชื่อ" value={name} onChange={(e) => setName(e.target.value)} required maxLength={80} />
        <label className="block"><span className="field-label">ทำงานในบทบาท (ตามสิทธิ์ของตาราง)</span>
          <select className="field-input" value={role} onChange={(e) => setRole(e.target.value as ApiKey['role'])}>
            <option value="admin">admin</option><option value="member">member</option><option value="public">public</option>
          </select>
        </label>
        <fieldset className="flex gap-4"><legend className="field-label">สิทธิ์ (scopes)</legend>
          {['records:read', 'records:write'].map((s) => (
            <label key={s} className="flex items-center gap-1"><input type="checkbox" checked={scopes.includes(s)} onChange={(e) => setScopes((x) => e.target.checked ? [...x, s] : x.filter((y) => y !== s))} /> {s}</label>
          ))}
        </fieldset>
        <Button type="submit" disabled={!name.trim() || scopes.length === 0}><KeyRound className="h-4 w-4" /> สร้าง</Button>
        {created ? (
          <div role="status" className="rounded-lg bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-100">
            <p className="font-medium">คัดลอกเก็บไว้ตอนนี้ — ระบบจะไม่แสดง key นี้อีก</p>
            <div className="mt-1 flex gap-2"><code className="flex-1 break-all text-xs" data-testid="new-api-key">{created}</code>
              <button type="button" aria-label="คัดลอก" onClick={() => navigator.clipboard?.writeText(created)}><Copy className="h-4 w-4" /></button></div>
          </div>
        ) : null}
        <p className="text-xs text-slate-500">ตัวอย่าง: <code className="break-all">{example}</code></p>
      </form>
      <div>
        {keys.isLoading ? <Spinner /> : keys.error ? <ErrorState message={errorMessage(keys.error)} /> : (
          <ul className="space-y-2 text-sm">
            {keys.data!.data.length === 0 ? <li className="text-slate-500">ยังไม่มี API key</li> : keys.data!.data.map((k) => (
              <li key={k.id} className="flex items-center gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <div className="flex-1">
                  <p className="font-medium">{k.name} <code className="text-xs text-slate-500">sk_{k.prefix}_…</code></p>
                  <p className="text-xs text-slate-500">{k.role} · {k.scopes.join(', ')} · {k.revokedAt ? `เพิกถอนแล้ว ${formatDate(k.revokedAt)}` : k.lastUsedAt ? `ใช้ล่าสุด ${formatDate(k.lastUsedAt)}` : 'ยังไม่เคยใช้'}</p>
                </div>
                {!k.revokedAt ? <Button size="sm" variant="secondary" onClick={async () => {
                  if (!window.confirm(`เพิกถอน key "${k.name}"?`)) return;
                  await api(`/projects/${projectId}/api-keys/${k.id}`, { method: 'DELETE' });
                  qc.invalidateQueries({ queryKey: ['api-keys', projectId] });
                }}>เพิกถอน</Button> : null}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

interface AppUserRow { id: string; name: string; email: string; role: 'member' | 'admin'; status: 'active' | 'disabled'; lastLoginAt: string | null; createdAt: string }

/** End users who signed up on the published site (separate from studio accounts). */
function AppUsersTab({ projectId, canEdit }: { projectId: string; canEdit: boolean }) {
  const qc = useQueryClient();
  const key = ['app-users', projectId];
  const users = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<AppUserRow[]>>(`/projects/${projectId}/app-users`)).data });
  const update = (id: string, body: Partial<Pick<AppUserRow, 'role' | 'status'>>) =>
    api(`/projects/${projectId}/app-users/${id}`, { method: 'PATCH', body }).then(() => toast.success('บันทึกแล้ว'), (e) => toast.error(errorMessage(e))).finally(() => qc.invalidateQueries({ queryKey: key }));
  if (users.isLoading) return <Spinner />;
  if (users.error) return <ErrorState message={errorMessage(users.error)} />;
  if (!users.data?.length) return <EmptyState icon={<UsersIcon className="h-10 w-10" />} title="ยังไม่มีผู้ใช้แอป" description="เมื่อผู้เยี่ยมชมสมัครสมาชิกบนเว็บไซต์ที่เผยแพร่ จะแสดงที่นี่ (ปุ่มสมัครแสดงเมื่อตั้งสิทธิ์ให้สมาชิก)" />;
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
      <table className="w-full text-left text-sm">
        <thead className="bg-slate-50 text-slate-600 dark:bg-navy-900 dark:text-slate-300"><tr><th className="px-3 py-2">ชื่อ</th><th className="px-3">อีเมล</th><th className="px-3">บทบาท</th><th className="px-3">สถานะ</th><th className="px-3">เข้าสู่ระบบล่าสุด</th><th className="px-3">PDPA</th></tr></thead>
        <tbody>
          {users.data.map((u) => (
            <tr key={u.id} className="border-t border-slate-100 dark:border-slate-800">
              <td className="px-3 py-2">{u.name}</td>
              <td className="px-3">{u.email}</td>
              <td className="px-3">
                <select aria-label={`บทบาทของ ${u.email}`} className="field-input h-8 w-auto py-0" disabled={!canEdit} value={u.role} onChange={(e) => update(u.id, { role: e.target.value as AppUserRow['role'] })}>
                  <option value="member">สมาชิก</option><option value="admin">ผู้ดูแลแอป</option>
                </select>
              </td>
              <td className="px-3">
                <label className="inline-flex items-center gap-1.5">
                  <input type="checkbox" disabled={!canEdit} checked={u.status === 'active'} onChange={(e) => update(u.id, { status: e.target.checked ? 'active' : 'disabled' })} />
                  {u.status === 'active' ? 'ใช้งาน' : 'ระงับ'}
                </label>
              </td>
              <td className="px-3 text-slate-500">{u.lastLoginAt ? formatDate(u.lastLoginAt) : '—'}</td>
              <td className="px-3">
                <a className="text-brand-700 underline" href={`/api/v1/projects/${projectId}/app-users/${u.id}/export`}>ส่งออก</a>
                {canEdit ? <button type="button" className="ml-3 text-red-700 underline" onClick={() => {
                  if (!window.confirm(`ลบบัญชี ${u.email}? (คำขอลบข้อมูลตาม PDPA)`)) return;
                  const withRecords = window.confirm('ลบข้อมูลที่ผู้ใช้นี้ส่งไว้ด้วยหรือไม่? (ยกเลิก = เก็บข้อมูลไว้แต่ไม่ผูกกับบุคคล)');
                  api(`/projects/${projectId}/app-users/${u.id}${withRecords ? '?deleteRecords=1' : ''}`, { method: 'DELETE' }).then(() => { toast.success('ลบแล้ว'); qc.invalidateQueries({ queryKey: key }); }, (e) => toast.error(errorMessage(e)));
                }}>ลบ</button> : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
