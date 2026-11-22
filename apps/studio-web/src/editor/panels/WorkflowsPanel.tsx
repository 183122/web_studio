import { useState } from 'react';
import { Plus, Trash2, Play, Zap } from 'lucide-react';
import {
  generateId, validateDefinition, applyCommands,
  type ConditionOp, type DataModel, type Scalar, type Workflow, type WorkflowAction,
} from '@studio/app-schema';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import { Button } from '../../components/ui/Button.tsx';
import { Dialog } from '../../components/ui/Dialog.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { reportRejected } from '../actions.ts';
import { useEditor } from '../store.ts';

const OPS: Array<[ConditionOp, string]> = [
  ['not_empty', 'มีค่า'], ['empty', 'ว่าง'], ['eq', 'เท่ากับ'], ['neq', 'ไม่เท่ากับ'], ['contains', 'มีคำว่า'], ['gt', 'มากกว่า'], ['lt', 'น้อยกว่า'],
];

function coerce(model: DataModel, fieldId: string, raw: string): Scalar {
  const f = model.fields.find((x) => x.id === fieldId);
  if (!f) return raw;
  if (f.type === 'number' || f.type === 'integer') return raw === '' ? null : Number(raw);
  if (f.type === 'boolean') return raw === 'true';
  return raw;
}

export function WorkflowsPanel() {
  const doc = useEditor((s) => s.doc)!;
  const canEdit = useEditor((s) => s.canEdit);
  const dispatch = useEditor((s) => s.dispatch);
  const [editing, setEditing] = useState<{ wf: Workflow; isNew: boolean } | null>(null);
  const modelName = (id: string) => doc.dataModels.find((m) => m.id === id)?.label ?? '?';

  return (
    <div className="space-y-3 text-sm">
      <p className="text-xs text-slate-500 dark:text-slate-400">เมื่อมีการสร้าง/แก้ไขข้อมูล → ตรวจเงื่อนไข → ทำงานอัตโนมัติ (ตั้งค่าฟิลด์, ส่งอีเมล)</p>
      {doc.workflows.length === 0 ? <p className="rounded-xl border border-dashed border-slate-300 p-4 text-center text-slate-500 dark:border-slate-700">ยังไม่มี workflow</p> : (
        <ul className="space-y-1">
          {doc.workflows.map((w) => (
            <li key={w.id} className="group flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-100 dark:hover:bg-navy-800">
              <Zap className={`h-4 w-4 shrink-0 ${w.enabled ? 'text-amber-500' : 'opacity-40'}`} aria-label={w.enabled ? 'เปิดใช้งาน' : 'ปิดอยู่'} />
              <button type="button" className="min-w-0 flex-1 text-left" onClick={() => setEditing({ wf: structuredClone(w), isNew: false })}>
                <span className="block truncate font-medium">{w.name}</span>
                <span className="block truncate text-xs text-slate-500">{w.trigger.event === 'record.created' ? 'เมื่อสร้าง' : 'เมื่อแก้ไข'} {modelName(w.trigger.modelId)} · {w.actions.length} การทำงาน</span>
              </button>
              {canEdit ? (
                <button type="button" aria-label={`ลบ workflow ${w.name}`} className="rounded p-1 text-slate-400 opacity-0 hover:text-red-600 group-hover:opacity-100 focus:opacity-100" onClick={() => {
                  if (window.confirm(`ลบ workflow "${w.name}"?`) && !dispatch([{ type: 'workflow.remove', id: w.id }], { label: 'ลบ workflow' })) reportRejected();
                }}><Trash2 className="h-4 w-4" /></button>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {canEdit ? (
        <Button variant="secondary" size="sm" className="w-full justify-center" disabled={doc.dataModels.length === 0} title={doc.dataModels.length === 0 ? 'สร้างตารางข้อมูลก่อน' : undefined} onClick={() => {
          const m = doc.dataModels[0];
          setEditing({ isNew: true, wf: { id: generateId('w'), name: 'workflow ใหม่', enabled: true, trigger: { event: 'record.created', modelId: m.id }, conditions: [], actions: [{ type: 'record.setField', fieldId: m.fields[0].id, value: null }] } });
        }}><Plus className="h-4 w-4" /> เพิ่ม workflow</Button>
      ) : null}
      {editing ? <WorkflowDialog initial={editing} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function WorkflowDialog({ initial, onClose }: { initial: { wf: Workflow; isNew: boolean }; onClose: () => void }) {
  const doc = useEditor((s) => s.doc)!;
  const projectId = useEditor((s) => s.projectId);
  const canEdit = useEditor((s) => s.canEdit);
  const dispatch = useEditor((s) => s.dispatch);
  const unsaved = useEditor((s) => s.pending.length > 0 || s.inflight !== null);
  const [wf, setWf] = useState(initial.wf);
  const [issues, setIssues] = useState<string[]>([]);
  const [sample, setSample] = useState<Record<string, string>>({});
  const [dry, setDry] = useState<{ matched: boolean; actions: Array<Record<string, unknown>> } | null>(null);
  const model = doc.dataModels.find((m) => m.id === wf.trigger.modelId) ?? doc.dataModels[0];
  const emailFields = model.fields.filter((f) => f.type === 'email');
  const setAction = (i: number, a: WorkflowAction) => setWf((w) => ({ ...w, actions: w.actions.map((x, j) => (j === i ? a : x)) }));

  const save = () => {
    const cmd = { type: 'workflow.upsert' as const, workflow: wf, index: doc.workflows.length };
    try {
      const errs = validateDefinition(applyCommands(doc, [cmd]).doc).map((i) => `${i.path}: ${i.message}`);
      setIssues(errs);
      if (errs.length) return;
    } catch (e) {
      setIssues([(e as Error).message]);
      return;
    }
    if (dispatch([cmd], { label: 'บันทึก workflow' })) {
      toast.success('บันทึก workflow แล้ว');
      onClose();
    } else reportRejected();
  };

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={initial.isNew ? 'เพิ่ม workflow' : `แก้ไข workflow: ${initial.wf.name}`} wide>
      <fieldset disabled={!canEdit} className="space-y-4 text-sm">
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block sm:col-span-2"><span className="field-label">ชื่อ</span><input className="field-input" value={wf.name} maxLength={80} onChange={(e) => setWf({ ...wf, name: e.target.value })} /></label>
          <label className="flex items-end gap-2 pb-2"><input type="checkbox" checked={wf.enabled} onChange={(e) => setWf({ ...wf, enabled: e.target.checked })} /> เปิดใช้งาน</label>
        </div>
        <section className="grid gap-3 sm:grid-cols-2">
          <label className="block"><span className="field-label">เมื่อ</span>
            <select className="field-input" value={wf.trigger.event} onChange={(e) => setWf({ ...wf, trigger: { ...wf.trigger, event: e.target.value as Workflow['trigger']['event'] } })}>
              <option value="record.created">มีการสร้างข้อมูลใหม่</option><option value="record.updated">มีการแก้ไขข้อมูล</option>
            </select>
          </label>
          <label className="block"><span className="field-label">ในตาราง</span>
            <select className="field-input" value={wf.trigger.modelId} onChange={(e) => {
              const m = doc.dataModels.find((x) => x.id === e.target.value)!;
              setWf({ ...wf, trigger: { ...wf.trigger, modelId: m.id }, conditions: [], actions: [{ type: 'record.setField', fieldId: m.fields[0].id, value: null }] });
            }}>
              {doc.dataModels.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
            </select>
          </label>
        </section>

        <section>
          <h3 className="mb-1 font-semibold">เงื่อนไข (ต้องเป็นจริงทั้งหมด)</h3>
          {wf.conditions.map((c, i) => (
            <div key={i} className="mb-1 flex gap-2">
              <select aria-label="ฟิลด์ของเงื่อนไข" className="field-input" value={c.fieldId} onChange={(e) => setWf({ ...wf, conditions: wf.conditions.map((x, j) => (j === i ? { ...x, fieldId: e.target.value } : x)) })}>
                {model.fields.map((f) => <option key={f.id} value={f.id}>{f.label}</option>)}
              </select>
              <select aria-label="ตัวดำเนินการ" className="field-input" value={c.op} onChange={(e) => {
                const op = e.target.value as ConditionOp;
                setWf({ ...wf, conditions: wf.conditions.map((x, j) => (j === i ? (op === 'empty' || op === 'not_empty' ? { fieldId: x.fieldId, op } : { ...x, op, value: x.value ?? (op === 'gt' || op === 'lt' ? 0 : '') }) : x)) });
              }}>{OPS.map(([o, l]) => <option key={o} value={o}>{l}</option>)}</select>
              {c.op !== 'empty' && c.op !== 'not_empty' ? (
                <input aria-label="ค่าเปรียบเทียบ" className="field-input" type={c.op === 'gt' || c.op === 'lt' ? 'number' : 'text'} value={String(c.value ?? '')} onChange={(e) => setWf({ ...wf, conditions: wf.conditions.map((x, j) => (j === i ? { ...x, value: c.op === 'gt' || c.op === 'lt' ? Number(e.target.value) : e.target.value } : x)) })} />
              ) : null}
              <button type="button" aria-label="ลบเงื่อนไข" className="p-1 text-red-600" onClick={() => setWf({ ...wf, conditions: wf.conditions.filter((_, j) => j !== i) })}><Trash2 className="h-4 w-4" /></button>
            </div>
          ))}
          <Button size="sm" variant="ghost" onClick={() => setWf({ ...wf, conditions: [...wf.conditions, { fieldId: model.fields[0].id, op: 'not_empty' }] })}><Plus className="h-4 w-4" /> เพิ่มเงื่อนไข</Button>
        </section>

        <section>
          <h3 className="mb-1 font-semibold">การทำงาน</h3>
          {wf.actions.map((a, i) => (
            <div key={i} className="mb-2 space-y-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
              <div className="flex gap-2">
                <select aria-label="ชนิดการทำงาน" className="field-input w-auto" value={a.type} onChange={(e) => setAction(i, e.target.value === 'email.send'
                  ? { type: 'email.send', to: emailFields[0] ? `field:${emailFields[0].id}` : 'admin@example.com', subject: 'แจ้งเตือน', body: 'มีข้อมูลใหม่' }
                  : { type: 'record.setField', fieldId: model.fields[0].id, value: null })}>
                  <option value="record.setField">ตั้งค่าฟิลด์</option><option value="email.send">ส่งอีเมล</option>
                </select>
                <span className="flex-1" />
                <button type="button" aria-label="ลบการทำงาน" disabled={wf.actions.length === 1} className="p-1 text-red-600 disabled:opacity-30" onClick={() => setWf({ ...wf, actions: wf.actions.filter((_, j) => j !== i) })}><Trash2 className="h-4 w-4" /></button>
              </div>
              {a.type === 'record.setField' ? (
                <div className="flex gap-2">
                  <select aria-label="ฟิลด์ที่จะตั้งค่า" className="field-input" value={a.fieldId} onChange={(e) => setAction(i, { ...a, fieldId: e.target.value, value: null })}>
                    {model.fields.map((f) => <option key={f.id} value={f.id}>{f.label}</option>)}
                  </select>
                  {(() => {
                    const f = model.fields.find((x) => x.id === a.fieldId);
                    if (f?.type === 'select') return <select aria-label="ค่าที่จะตั้ง" className="field-input" value={String(a.value ?? '')} onChange={(e) => setAction(i, { ...a, value: e.target.value || null })}><option value="">(ว่าง)</option>{f.options!.map((o) => <option key={o}>{o}</option>)}</select>;
                    if (f?.type === 'boolean') return <select aria-label="ค่าที่จะตั้ง" className="field-input" value={String(a.value)} onChange={(e) => setAction(i, { ...a, value: e.target.value === 'true' })}><option value="true">ใช่</option><option value="false">ไม่ใช่</option></select>;
                    return <input aria-label="ค่าที่จะตั้ง" className="field-input" value={String(a.value ?? '')} onChange={(e) => setAction(i, { ...a, value: coerce(model, a.fieldId, e.target.value) })} />;
                  })()}
                </div>
              ) : (
                <div className="space-y-2">
                  <label className="block"><span className="field-label">ส่งถึง</span>
                    <input aria-label="ผู้รับ" className="field-input" list={`email-targets-${i}`} value={a.to} onChange={(e) => setAction(i, { ...a, to: e.target.value })} />
                    <datalist id={`email-targets-${i}`}>{emailFields.map((f) => <option key={f.id} value={`field:${f.id}`}>{`อีเมลในฟิลด์ ${f.label}`}</option>)}</datalist>
                  </label>
                  <input aria-label="หัวเรื่องอีเมล" className="field-input" value={a.subject} maxLength={150} onChange={(e) => setAction(i, { ...a, subject: e.target.value })} />
                  <textarea aria-label="เนื้อหาอีเมล" className="field-input" rows={3} value={a.body} maxLength={5000} onChange={(e) => setAction(i, { ...a, body: e.target.value })} />
                  <p className="text-[11px] text-slate-500">แทรกค่าฟิลด์ด้วย {model.fields.map((f) => `{{${f.name}}}`).join(' ')} · อีเมลเป็นข้อความล้วน (ไม่มี HTML)</p>
                </div>
              )}
            </div>
          ))}
          <Button size="sm" variant="ghost" disabled={wf.actions.length >= 10} onClick={() => setWf({ ...wf, actions: [...wf.actions, { type: 'record.setField', fieldId: model.fields[0].id, value: null }] })}><Plus className="h-4 w-4" /> เพิ่มการทำงาน</Button>
        </section>

        {issues.length ? <ul role="alert" className="list-disc rounded-lg bg-red-50 p-3 pl-6 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">{issues.slice(0, 8).map((m, k) => <li key={k}>{m}</li>)}</ul> : null}

        {!initial.isNew ? (
          <section className="rounded-lg bg-slate-50 p-3 dark:bg-navy-800">
            <h3 className="mb-2 font-semibold">ทดลองรัน (dry run — ไม่บันทึกข้อมูล ไม่ส่งอีเมล)</h3>
            <div className="grid gap-2 sm:grid-cols-2">
              {model.fields.map((f) => <input key={f.id} aria-label={`ค่าทดลอง ${f.label}`} placeholder={f.label} className="field-input" value={sample[f.id] ?? ''} onChange={(e) => setSample((s) => ({ ...s, [f.id]: e.target.value }))} />)}
            </div>
            <Button size="sm" variant="secondary" className="mt-2" disabled={unsaved} title={unsaved ? 'รอบันทึกอัตโนมัติก่อน' : undefined} onClick={async () => {
              try {
                setDry((await api<Envelope<{ matched: boolean; actions: Array<Record<string, unknown>> }>>(`/projects/${projectId}/workflows/${wf.id}/dry-run`, { method: 'POST', body: { data: sample } })).data);
              } catch (e) {
                toast.error(errorMessage(e));
              }
            }}><Play className="h-4 w-4" /> ทดลอง (ใช้เวอร์ชันที่บันทึกแล้ว)</Button>
            {dry ? <pre role="status" className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap text-xs">{dry.matched ? 'เงื่อนไขเป็นจริง → ' + JSON.stringify(dry.actions, null, 1) : 'เงื่อนไขไม่เป็นจริง — จะไม่ทำงาน'}</pre> : null}
          </section>
        ) : null}
      </fieldset>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>ยกเลิก</Button>
        {canEdit ? <Button onClick={save}>บันทึก</Button> : null}
      </div>
    </Dialog>
  );
}
