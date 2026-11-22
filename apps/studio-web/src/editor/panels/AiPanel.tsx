import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Bot, CheckCircle2, Loader2, Sparkles, Wand2, X } from 'lucide-react';
import { getComponentDefinition } from '@studio/component-registry';
import { api, ApiError, errorMessage, type Envelope } from '../../lib/api.ts';
import { keys, type SchemaHead } from '../../lib/queries.ts';
import { Button } from '../../components/ui/Button.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { Spinner } from '../../components/ui/misc.tsx';
import { useEditor } from '../store.ts';

interface AiStatus {
  provider: string;
  model: string;
  external: boolean;
  keySource: 'byok' | 'platform' | 'none';
  consentRequired: boolean;
  credits: { used: number; limit: number };
  estimate: { plan: number };
}

interface AiRequestView {
  id: string;
  kind: 'plan' | 'patch';
  status: 'queued' | 'running' | 'succeeded' | 'failed';
  provider: string;
  fallbackUsed: boolean;
  result: Plan | { proposalId: string } | null;
  error: { code: string; message: string } | null;
  usage: { inputTokens: number; outputTokens: number; credits: number };
}

interface Plan {
  summary: string;
  confidence: number;
  missingRequirements: string[];
  assumptions: string[];
  siteName: string;
  pages: Array<{ name: string; path: string; sections: Array<{ kind: string; heading: string; entity: string }> }>;
  entities: Array<{ name: string; label: string; fields: Array<{ name: string; label: string; type: string; required: boolean }> }>;
  permissions: Array<{ entity: string; public: string[]; member: string[] }>;
  workflows: Array<{ name: string; entity: string; event: string }>;
}

interface Proposal {
  id: string;
  status: string;
  baseRevision: number;
  summary: {
    pagesAdded?: Array<{ name: string; path: string; sections: string[] }>;
    pagesUpdated?: Array<{ name: string; path: string; sections: string[] }>;
    modelsAdded?: Array<{ name: string; label: string; fields: string[] }>;
    modelsReused?: string[];
    workflowsAdded?: Array<{ name: string; model: string; event: string; actions: string[] }>;
    permissions?: Array<{ model: string; public: string[]; member: string[]; admin: string[] }>;
    themeChanged?: boolean;
    changes?: Array<{ op: string; type?: string; key?: string; before?: unknown; after?: unknown; kind?: string; page?: string; model?: string; field?: string; note?: string; heading?: string }>;
    warnings?: string[];
    note?: string;
    commandCounts?: Record<string, number>;
    componentCount?: number;
  };
}

const SECTION_TH: Record<string, string> = { hero: 'ส่วนเปิด', features: 'จุดเด่น', text: 'ข้อความ', cta: 'ชวนดำเนินการ', form: 'ฟอร์ม', table: 'ตารางข้อมูล', faq: 'คำถามที่พบบ่อย', list: 'รายการ', contact: 'ติดต่อ' };
const ACTION_TH: Record<string, string> = { create: 'สร้าง', read: 'อ่าน', update: 'แก้ไข', delete: 'ลบ' };
const acts = (a: string[]) => (a.length ? a.map((x) => ACTION_TH[x] ?? x).join(', ') : 'ไม่มีสิทธิ์');

async function waitFor(projectId: string, id: string, signal: { cancelled: boolean }): Promise<AiRequestView> {
  for (let i = 0; i < 400 && !signal.cancelled; i++) {
    const r = (await api<Envelope<AiRequestView>>(`/projects/${projectId}/ai/requests/${id}`)).data;
    if (r.status === 'succeeded' || r.status === 'failed') return r;
    await new Promise((res) => setTimeout(res, 1500));
  }
  throw new Error('หมดเวลารอผลจาก AI');
}

export function AiPanel({ initialPrompt }: { initialPrompt?: string }) {
  const projectId = useEditor((s) => s.projectId);
  const canEdit = useEditor((s) => s.canEdit);
  const selectedId = useEditor((s) => s.selectedId);
  const pageId = useEditor((s) => s.pageId);
  const doc = useEditor((s) => s.doc)!;
  const qc = useQueryClient();
  const status = useQuery({ queryKey: ['ai-status', projectId], queryFn: async () => (await api<Envelope<AiStatus>>(`/projects/${projectId}/ai/status`)).data });
  const [mode, setMode] = useState<'plan' | 'patch'>(initialPrompt ? 'plan' : doc.pages.length === 1 && Object.keys(doc.components).length <= 1 ? 'plan' : 'patch');
  const [prompt, setPrompt] = useState(initialPrompt ?? '');
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [request, setRequest] = useState<AiRequestView | null>(null);
  const [proposal, setProposal] = useState<Proposal | null>(null);
  const [error, setError] = useState<string | null>(null);
  const selected = selectedId ? doc.components[selectedId] : null;
  const page = doc.pages.find((p) => p.id === pageId);

  useEffect(() => {
    setRequest(null);
    setProposal(null);
    setError(null);
  }, [mode]);

  const run = async () => {
    setError(null);
    setRequest(null);
    setProposal(null);
    setBusy(mode === 'plan' ? 'กำลังวางแผนแอป…' : 'กำลังคิดการแก้ไข…');
    const signal = { cancelled: false };
    try {
      const body = mode === 'plan' ? { prompt, consent } : { prompt, consent, pageId, componentId: selectedId ?? undefined };
      const started = (await api<Envelope<AiRequestView>>(`/projects/${projectId}/ai/${mode}`, { method: 'POST', body })).data;
      const done = await waitFor(projectId, started.id, signal);
      setRequest(done);
      qc.invalidateQueries({ queryKey: ['ai-status', projectId] });
      if (done.status === 'failed') {
        setError(done.error?.message ?? 'AI ทำงานไม่สำเร็จ');
      } else if (done.kind === 'patch' && done.result && 'proposalId' in done.result) {
        setProposal((await api<Envelope<Proposal>>(`/projects/${projectId}/ai/proposals/${done.result.proposalId}`)).data);
      }
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  const generate = async () => {
    if (!request) return;
    setBusy('กำลังสร้างข้อเสนอการเปลี่ยนแปลง…');
    try {
      setProposal((await api<Envelope<Proposal>>(`/projects/${projectId}/ai/requests/${request.id}/generate`, { method: 'POST' })).data);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  const apply = async () => {
    if (!proposal) return;
    const s = useEditor.getState();
    if (s.pending.length > 0 || s.inflight) {
      toast.info('รอบันทึกการแก้ไขล่าสุดก่อน แล้วลองอีกครั้ง');
      return;
    }
    setBusy('กำลังนำไปใช้…');
    try {
      await api(`/projects/${projectId}/ai/proposals/${proposal.id}/apply`, { method: 'POST', body: { baseRevision: s.serverRevision } });
      const head = (await api<Envelope<SchemaHead>>(`/projects/${projectId}/schema`)).data;
      qc.setQueryData(keys.schema(projectId), head);
      useEditor.getState().rebase(head.document, head.revision, false);
      qc.invalidateQueries({ queryKey: keys.revisions(projectId) });
      toast.success('นำการเปลี่ยนแปลงจาก AI ไปใช้แล้ว (ย้อนกลับได้จากแผง "เวอร์ชัน")');
      setProposal(null);
      setRequest(null);
      setPrompt('');
    } catch (e) {
      setError(e instanceof ApiError && e.code === 'revision_conflict' ? 'เอกสารถูกแก้ไขระหว่างนี้ กรุณาสร้างข้อเสนอใหม่' : errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  const reject = async () => {
    if (!proposal) return;
    await api(`/projects/${projectId}/ai/proposals/${proposal.id}/reject`, { method: 'POST' }).catch(() => undefined);
    setProposal(null);
    toast.info('ยกเลิกข้อเสนอแล้ว เอกสารไม่ถูกเปลี่ยน');
  };

  if (status.isLoading) return <Spinner />;
  const st = status.data;
  const plan = request?.kind === 'plan' && request.status === 'succeeded' ? (request.result as Plan) : null;

  return (
    <div className="space-y-4 text-sm">
      {st ? (
        <div className={`rounded-xl border p-3 text-xs ${st.external ? 'border-brand-200 bg-brand-50 dark:border-brand-800 dark:bg-brand-900/20' : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950'}`} data-testid="ai-provider">
          <p className="flex items-center gap-1.5 font-medium">
            <Bot className="h-4 w-4" aria-hidden />
            {st.external ? `Claude (${st.model})${st.keySource === 'byok' ? ' · API key ขององค์กร' : ''}` : 'โหมดออฟไลน์ (แม่แบบอัตโนมัติ ไม่ใช้ AI)'}
          </p>
          {!st.external ? <p className="mt-1">ยังไม่ได้ตั้งค่า API key ของผู้ให้บริการ AI ระบบจะสร้างแผนจากแม่แบบตามคำสำคัญแทน</p> : null}
          <p className="mt-1">เครดิต AI เดือนนี้: {st.credits.used}/{st.credits.limit}{st.external ? ` · ประมาณ ${st.estimate.plan} เครดิต/แผน (สูงสุด)` : ''}</p>
        </div>
      ) : null}

      <div role="tablist" aria-label="โหมด AI" className="flex rounded-lg border border-slate-300 p-0.5 dark:border-slate-700">
        {([['plan', 'สร้างแอปจากคำอธิบาย'], ['patch', 'แก้ไขด้วย AI']] as const).map(([m, label]) => (
          <button key={m} role="tab" type="button" aria-selected={mode === m} onClick={() => setMode(m)} className={`flex-1 rounded-md px-2 py-1.5 text-xs ${mode === m ? 'bg-brand-700 text-white' : ''}`}>{label}</button>
        ))}
      </div>

      {!proposal ? (
        <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); void run(); }}>
          {mode === 'patch' ? (
            <p className="text-xs text-slate-500">
              ขอบเขต: {selected ? <>คอมโพเนนต์ที่เลือก <b>{selected.name || getComponentDefinition(selected.type)?.labelTh}</b></> : <>ทั้งหน้า <b>{page?.name}</b></>}
            </p>
          ) : null}
          <label htmlFor="ai-prompt" className="field-label">{mode === 'plan' ? 'อธิบายแอปที่ต้องการ' : 'ต้องการให้แก้อะไร'}</label>
          <textarea
            id="ai-prompt"
            className="field-input"
            rows={mode === 'plan' ? 6 : 4}
            maxLength={4000}
            value={prompt}
            disabled={!canEdit || !!busy}
            onChange={(e) => setPrompt(e.target.value)}
            placeholder={mode === 'plan' ? 'เช่น ระบบรับสมัครอบรมครู มีหน้าแรก หน้ารายละเอียดหลักสูตร ฟอร์มสมัครเก็บชื่อ อีเมล โรงเรียน และส่งอีเมลยืนยันผู้สมัคร' : 'เช่น เปลี่ยนสีหลักเป็น #2563eb, เปลี่ยนข้อความเป็น "ยินดีต้อนรับ", เพิ่มคำถามที่พบบ่อย'}
          />
          {st?.consentRequired ? (
            <label className="flex items-start gap-2 text-xs">
              <input type="checkbox" className="mt-0.5" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
              <span>ยินยอมส่งคำอธิบายนี้{mode === 'patch' ? 'และโครงร่างหน้าเว็บ' : ''}ไปประมวลผลที่ผู้ให้บริการ AI ภายนอก (Anthropic) — ห้ามใส่ข้อมูลส่วนบุคคลที่ไม่จำเป็น</span>
            </label>
          ) : null}
          <Button type="submit" className="w-full justify-center" disabled={!canEdit || !prompt.trim() || (st?.consentRequired && !consent) || !!busy} loading={!!busy}>
            <Sparkles className="h-4 w-4" /> {mode === 'plan' ? 'วางแผนแอป' : 'เสนอการแก้ไข'}
          </Button>
        </form>
      ) : null}

      {busy ? <p role="status" className="flex items-center gap-2 text-xs text-slate-500"><Loader2 className="h-4 w-4 animate-spin" /> {busy}</p> : null}
      {error ? <p role="alert" className="flex gap-2 rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950 dark:text-red-200"><AlertTriangle className="h-4 w-4 shrink-0" /> {error}</p> : null}
      {request?.fallbackUsed ? <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">ผู้ให้บริการ AI ไม่พร้อมใช้งานชั่วคราว ระบบใช้แม่แบบอัตโนมัติแทน</p> : null}

      {plan && !proposal ? <PlanView plan={plan} usage={request!.usage} onGenerate={generate} busy={!!busy} /> : null}
      {proposal ? <ProposalView proposal={proposal} onApply={apply} onReject={reject} busy={!!busy} /> : null}
    </div>
  );
}

function PlanView({ plan, usage, onGenerate, busy }: { plan: Plan; usage: AiRequestView['usage']; onGenerate: () => void; busy: boolean }) {
  return (
    <section aria-label="แผนแอป" className="space-y-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700" data-testid="ai-plan">
      <h3 className="font-semibold">แผน: {plan.siteName}</h3>
      <p className="text-xs text-slate-600 dark:text-slate-300">{plan.summary}</p>
      <div>
        <p className="text-xs text-slate-500">ความครบถ้วนของคำอธิบาย {Math.round(plan.confidence * 100)}%</p>
        <div className="h-1.5 rounded bg-slate-200 dark:bg-slate-700"><div className="h-1.5 rounded bg-brand-600" style={{ width: `${Math.round(plan.confidence * 100)}%` }} /></div>
      </div>
      <Block title={`หน้า (${plan.pages.length})`} items={plan.pages.map((p) => `${p.name} ${p.path} — ${p.sections.map((s) => SECTION_TH[s.kind] ?? s.kind).join(', ')}`)} />
      <Block title={`ตารางข้อมูล (${plan.entities.length})`} items={plan.entities.map((e) => `${e.label} (${e.name}): ${e.fields.map((f) => f.label + (f.required ? '*' : '')).join(', ')}`)} />
      <Block title="สิทธิ์ของผู้เยี่ยมชม" items={plan.permissions.map((p) => `${p.entity}: ${acts(p.public)}`)} />
      <Block title={`ระบบอัตโนมัติ (${plan.workflows.length})`} items={plan.workflows.map((w) => `${w.name} (${w.event === 'record.created' ? 'เมื่อสร้างข้อมูล' : 'เมื่อแก้ไข'})`)} />
      {plan.missingRequirements.length ? <Block title="ข้อมูลที่ยังขาด (ควรระบุเพิ่ม)" items={plan.missingRequirements} tone="warn" /> : null}
      {plan.assumptions.length ? <Block title="สมมติฐานที่ใช้" items={plan.assumptions} /> : null}
      <p className="text-[11px] text-slate-500">ใช้ {usage.credits} เครดิต ({usage.inputTokens + usage.outputTokens} tokens)</p>
      <Button className="w-full justify-center" onClick={onGenerate} loading={busy}><Wand2 className="h-4 w-4" /> สร้างตามแผนนี้ (ดูการเปลี่ยนแปลงก่อน)</Button>
    </section>
  );
}

function Block({ title, items, tone }: { title: string; items: string[]; tone?: 'warn' }) {
  if (items.length === 0) return null;
  return (
    <div>
      <p className={`text-xs font-semibold ${tone === 'warn' ? 'text-amber-700 dark:text-amber-300' : ''}`}>{title}</p>
      <ul className="list-disc pl-4 text-xs">{items.map((t, i) => <li key={i}>{t}</li>)}</ul>
    </div>
  );
}

function ProposalView({ proposal, onApply, onReject, busy }: { proposal: Proposal; onApply: () => void; onReject: () => void; busy: boolean }) {
  const s = proposal.summary;
  return (
    <section aria-label="ข้อเสนอการเปลี่ยนแปลง" className="space-y-3 rounded-xl border-2 border-brand-500 p-3" data-testid="ai-proposal">
      <h3 className="flex items-center gap-1.5 font-semibold"><CheckCircle2 className="h-4 w-4 text-brand-600" /> ตรวจสอบการเปลี่ยนแปลงก่อนนำไปใช้</h3>
      {s.note ? <p className="text-xs text-slate-600 dark:text-slate-300">{s.note}</p> : null}
      <Block title="หน้าใหม่" items={(s.pagesAdded ?? []).map((p) => `${p.name} (${p.path})`)} />
      <Block title="หน้าที่แก้ไข" items={(s.pagesUpdated ?? []).map((p) => `${p.name} (${p.path}) + ${p.sections.length} ส่วน`)} />
      <Block title="ตารางข้อมูลใหม่" items={(s.modelsAdded ?? []).map((m) => `${m.label} (${m.name}): ${m.fields.join(', ')}`)} />
      {s.permissions?.length ? (
        <div>
          <p className="text-xs font-semibold">ผลต่อสิทธิ์การเข้าถึง</p>
          <table className="w-full text-[11px]"><thead><tr className="text-left text-slate-500"><th>ตาราง</th><th>ผู้เยี่ยมชม</th><th>สมาชิก</th></tr></thead>
            <tbody>{s.permissions.map((p, i) => <tr key={i}><td>{p.model}</td><td className={p.public.length ? 'font-medium text-amber-700 dark:text-amber-300' : ''}>{acts(p.public)}</td><td>{acts(p.member)}</td></tr>)}</tbody></table>
        </div>
      ) : null}
      <Block title="ระบบอัตโนมัติใหม่" items={(s.workflowsAdded ?? []).map((w) => `${w.name} → ${w.actions.map((a) => (a === 'email.send' ? 'ส่งอีเมล' : 'ตั้งค่าฟิลด์')).join(', ')}`)} />
      <Block title="การแก้ไข" items={(s.changes ?? []).map((c) => {
        if (c.op === 'setText' || c.op === 'setStyle' || c.op === 'setTheme' || c.op === 'renamePage') return `${c.op === 'setTheme' ? 'สีธีม ' + c.key : c.op === 'renamePage' ? 'ชื่อหน้า' : `${c.type} ${c.key}`}: "${String(c.before ?? '')}" → "${String(c.after ?? '')}"`;
        if (c.op === 'addSection') return `เพิ่มส่วน ${SECTION_TH[c.kind ?? ''] ?? c.kind} "${c.heading ?? ''}" ในหน้า ${c.page}`;
        if (c.op === 'removeComponent') return `ลบ ${c.type}`;
        if (c.op === 'addField') return `เพิ่มฟิลด์ ${c.field} ใน ${c.model} (${c.note})`;
        return c.op;
      })} />
      {s.themeChanged ? <p className="text-xs">เปลี่ยนสี/ฟอนต์ของธีม</p> : null}
      {s.warnings?.length ? <Block title="หมายเหตุจากระบบตรวจสอบ" items={s.warnings} tone="warn" /> : null}
      <p className="text-[11px] text-slate-500">{Object.values(s.commandCounts ?? {}).reduce((a, b) => a + b, 0)} คำสั่ง · {s.componentCount ?? 0} คอมโพเนนต์ · ผ่านการตรวจสอบความถูกต้องและความปลอดภัยแล้ว</p>
      <div className="flex gap-2">
        <Button className="flex-1 justify-center" onClick={onApply} loading={busy}>นำไปใช้</Button>
        <Button variant="secondary" onClick={onReject} disabled={busy}><X className="h-4 w-4" /> ไม่ใช้</Button>
      </div>
    </section>
  );
}
