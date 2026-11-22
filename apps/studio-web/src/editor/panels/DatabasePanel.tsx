import { useState } from 'react';
import { Link } from 'react-router';
import { Database, ExternalLink, Plus, Trash2, ArrowUp, ArrowDown } from 'lucide-react';
import {
  createField, createModel, modelReferences, validateDefinition, applyCommands,
  type AppRole, type DataModel, type FieldDefinition, type FieldType, type Permission, type RecordAction,
} from '@studio/app-schema';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import type { ImpactReport } from '../../lib/types.ts';
import { Button } from '../../components/ui/Button.tsx';
import { Dialog } from '../../components/ui/Dialog.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { reportRejected } from '../actions.ts';
import { useEditor } from '../store.ts';

const TYPES: Array<[FieldType, string]> = [
  ['text', 'ข้อความสั้น'], ['longtext', 'ข้อความยาว'], ['email', 'อีเมล'], ['phone', 'เบอร์โทร'], ['number', 'ตัวเลข'],
  ['integer', 'จำนวนเต็ม'], ['boolean', 'ใช่/ไม่ใช่'], ['date', 'วันที่'], ['datetime', 'วันและเวลา'], ['url', 'ลิงก์'], ['select', 'ตัวเลือก'],
];
const ROLES: Array<[AppRole, string]> = [['public', 'ผู้เยี่ยมชม (public)'], ['member', 'สมาชิก (member)'], ['admin', 'ผู้ดูแลแอป (admin)']];
const ACTIONS: Array<[RecordAction, string]> = [['create', 'สร้าง'], ['read', 'อ่าน'], ['update', 'แก้ไข'], ['delete', 'ลบ']];

/** Machine name from a label: ASCII slug or a numbered fallback (Thai labels). */
export function machineName(label: string, fallback: string, taken: string[]): string {
  let base = label.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[^a-z]+/, '').slice(0, 36);
  if (!base) base = fallback;
  let name = base;
  let i = 2;
  while (taken.includes(name) || ['id', 'created_at', 'updated_at', 'version'].includes(name)) name = `${base}_${i++}`;
  return name;
}

export function DatabasePanel() {
  const doc = useEditor((s) => s.doc)!;
  const projectId = useEditor((s) => s.projectId);
  const canEdit = useEditor((s) => s.canEdit);
  const dispatch = useEditor((s) => s.dispatch);
  const [editing, setEditing] = useState<{ model: DataModel; permission: Permission; isNew: boolean } | null>(null);

  return (
    <div className="space-y-3 text-sm">
      <p className="text-xs text-slate-500 dark:text-slate-400">ตารางข้อมูลของแอป ใช้กับฟอร์ม ตารางแสดงผล workflow และ REST API</p>
      {doc.dataModels.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 p-4 text-center text-slate-500 dark:border-slate-700">
          <Database className="mx-auto mb-1 h-6 w-6" aria-hidden /> ยังไม่มีตารางข้อมูล
        </div>
      ) : (
        <ul className="space-y-1">
          {doc.dataModels.map((m) => (
            <li key={m.id} className="group flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-100 dark:hover:bg-navy-800">
              <Database className="h-4 w-4 shrink-0 opacity-70" aria-hidden />
              <button type="button" className="min-w-0 flex-1 text-left" onClick={() => setEditing({ model: structuredClone(m), permission: structuredClone(doc.permissions.find((p) => p.modelId === m.id)!), isNew: false })}>
                <span className="block truncate font-medium">{m.label}</span>
                <span className="block truncate text-xs text-slate-500">{m.name} · {m.fields.length} ฟิลด์</span>
              </button>
              {canEdit ? (
                <button type="button" aria-label={`ลบตาราง ${m.label}`} className="rounded p-1 text-slate-400 opacity-0 hover:text-red-600 group-hover:opacity-100 focus:opacity-100" onClick={() => {
                  const refs = modelReferences(doc, m.id);
                  if (refs.length > 0) {
                    toast.error(`ยังมีฟอร์ม/ตาราง/workflow ใช้ตารางนี้อยู่ ${refs.length} รายการ กรุณาลบออกก่อน`);
                    return;
                  }
                  if (!window.confirm(`ลบตาราง "${m.label}" ออกจากแอป? (ข้อมูลที่บันทึกแล้วจะถูกเก็บไว้ ไม่ถูกลบ)`)) return;
                  if (!dispatch([{ type: 'permission.remove', modelId: m.id }, { type: 'model.remove', id: m.id }], { label: 'ลบตารางข้อมูล' })) reportRejected();
                }}>
                  <Trash2 className="h-4 w-4" />
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {canEdit ? (
        <Button variant="secondary" size="sm" className="w-full justify-center" onClick={() => {
          const { model, permission } = createModel('ตารางใหม่', machineName('', `table_${doc.dataModels.length + 1}`, doc.dataModels.map((m) => m.name)));
          setEditing({ model, permission, isNew: true });
        }}>
          <Plus className="h-4 w-4" /> เพิ่มตารางข้อมูล
        </Button>
      ) : null}
      <Link to={`/app/projects/${projectId}/data`} target="_blank" className="flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
        <ExternalLink className="h-3.5 w-3.5" /> จัดการข้อมูล (CRUD, CSV, API keys)
      </Link>
      {editing ? <ModelDialog initial={editing} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function ModelDialog({ initial, onClose }: { initial: { model: DataModel; permission: Permission; isNew: boolean }; onClose: () => void }) {
  const doc = useEditor((s) => s.doc)!;
  const projectId = useEditor((s) => s.projectId);
  const canEdit = useEditor((s) => s.canEdit);
  const dispatch = useEditor((s) => s.dispatch);
  const [model, setModel] = useState(initial.model);
  const [permission, setPermission] = useState(initial.permission);
  const [issues, setIssues] = useState<string[]>([]);
  const [impact, setImpact] = useState<ImpactReport | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);

  const setField = (i: number, patch: Partial<FieldDefinition>) =>
    setModel((m) => ({ ...m, fields: m.fields.map((f, j) => (j === i ? clean({ ...f, ...patch }) : f)) }));
  const move = (i: number, d: number) => setModel((m) => {
    const fields = [...m.fields];
    const [f] = fields.splice(i, 1);
    fields.splice(i + d, 0, f);
    return { ...m, fields };
  });

  const commands = () => [
    { type: 'model.upsert' as const, model, index: doc.dataModels.length },
    { type: 'permission.set' as const, permission },
  ];

  const save = async () => {
    // Validate locally first (same rules as the server).
    try {
      const preview = applyCommands(doc, commands()).doc;
      const errs = validateDefinition(preview).map((i) => `${i.path}: ${i.message}`);
      setIssues(errs);
      if (errs.length) return;
    } catch (e) {
      setIssues([(e as Error).message]);
      return;
    }
    if (!initial.isNew && !confirmed) {
      setBusy(true);
      try {
        const report = (await api<Envelope<ImpactReport>>(`/projects/${projectId}/data/impact`, { method: 'POST', body: { model } })).data;
        if (report.requiresConfirmation) {
          setImpact(report);
          return;
        }
      } catch (e) {
        toast.error(errorMessage(e));
        return;
      } finally {
        setBusy(false);
      }
    }
    if (dispatch(commands(), { label: initial.isNew ? 'เพิ่มตารางข้อมูล' : 'แก้ไขตารางข้อมูล' })) {
      toast.success('บันทึกโครงสร้างตารางแล้ว');
      onClose();
    } else reportRejected();
  };

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={initial.isNew ? 'เพิ่มตารางข้อมูล' : `แก้ไขตาราง: ${initial.model.label}`} wide>
      <fieldset disabled={!canEdit} className="space-y-5 text-sm">
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block"><span className="field-label">ชื่อตาราง (แสดงผล)</span>
            <input className="field-input" value={model.label} maxLength={80} onChange={(e) => setModel((m) => ({ ...m, label: e.target.value }))} />
          </label>
          <label className="block"><span className="field-label">ชื่อสำหรับ API (a-z, 0-9, _)</span>
            <input className="field-input font-mono" value={model.name} maxLength={40} disabled={!initial.isNew} onChange={(e) => setModel((m) => ({ ...m, name: e.target.value.toLowerCase() }))} />
          </label>
        </div>

        <section>
          <h3 className="mb-2 font-semibold">ฟิลด์</h3>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-xs">
              <thead><tr className="text-slate-500"><th className="p-1">ป้ายกำกับ</th><th className="p-1">ชื่อ API</th><th className="p-1">ชนิด</th><th className="p-1">จำเป็น</th><th className="p-1">ห้ามซ้ำ</th><th className="p-1">ตัวเลือก/ข้อจำกัด</th><th className="p-1" /></tr></thead>
              <tbody>
                {model.fields.map((f, i) => {
                  const isExisting = initial.model.fields.some((x) => x.id === f.id) && !initial.isNew;
                  return (
                    <tr key={f.id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="p-1"><input aria-label={`ป้ายกำกับฟิลด์ ${i + 1}`} className="field-input" value={f.label} maxLength={80} onChange={(e) => setField(i, { label: e.target.value })} /></td>
                      <td className="p-1"><input aria-label={`ชื่อ API ฟิลด์ ${i + 1}`} className="field-input font-mono" value={f.name} maxLength={40} disabled={isExisting} onChange={(e) => setField(i, { name: e.target.value.toLowerCase() })} /></td>
                      <td className="p-1">
                        <select aria-label={`ชนิดฟิลด์ ${i + 1}`} className="field-input" value={f.type} onChange={(e) => setField(i, { type: e.target.value as FieldType })}>
                          {TYPES.map(([t, l]) => <option key={t} value={t}>{l}</option>)}
                        </select>
                      </td>
                      <td className="p-1 text-center"><input type="checkbox" aria-label={`ฟิลด์ ${i + 1} จำเป็น`} checked={f.required} onChange={(e) => setField(i, { required: e.target.checked })} /></td>
                      <td className="p-1 text-center"><input type="checkbox" aria-label={`ฟิลด์ ${i + 1} ห้ามซ้ำ`} checked={f.unique} disabled={f.type === 'longtext' || f.type === 'boolean'} onChange={(e) => setField(i, { unique: e.target.checked })} /></td>
                      <td className="p-1">
                        {f.type === 'select' ? (
                          <input aria-label={`ตัวเลือกของฟิลด์ ${i + 1} (คั่นด้วย ,)`} className="field-input" value={(f.options ?? []).join(', ')} onChange={(e) => setField(i, { options: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} />
                        ) : f.type === 'number' || f.type === 'integer' ? (
                          <div className="flex gap-1">
                            <input aria-label="ค่าต่ำสุด" placeholder="min" type="number" className="field-input" value={f.min ?? ''} onChange={(e) => setField(i, { min: e.target.value === '' ? undefined : Number(e.target.value) })} />
                            <input aria-label="ค่าสูงสุด" placeholder="max" type="number" className="field-input" value={f.max ?? ''} onChange={(e) => setField(i, { max: e.target.value === '' ? undefined : Number(e.target.value) })} />
                          </div>
                        ) : f.type === 'text' || f.type === 'longtext' ? (
                          <input aria-label="ความยาวสูงสุด" placeholder="ยาวสุด" type="number" min={1} className="field-input" value={f.maxLength ?? ''} onChange={(e) => setField(i, { maxLength: e.target.value === '' ? undefined : Number(e.target.value) })} />
                        ) : null}
                      </td>
                      <td className="whitespace-nowrap p-1">
                        <button type="button" aria-label="เลื่อนขึ้น" disabled={i === 0} onClick={() => move(i, -1)} className="p-1 disabled:opacity-30"><ArrowUp className="h-3.5 w-3.5" /></button>
                        <button type="button" aria-label="เลื่อนลง" disabled={i === model.fields.length - 1} onClick={() => move(i, 1)} className="p-1 disabled:opacity-30"><ArrowDown className="h-3.5 w-3.5" /></button>
                        <button type="button" aria-label={`ลบฟิลด์ ${f.label}`} disabled={model.fields.length === 1 || f.id === model.displayFieldId} onClick={() => setModel((m) => ({ ...m, fields: m.fields.filter((x) => x.id !== f.id) }))} className="p-1 text-red-600 disabled:opacity-30"><Trash2 className="h-3.5 w-3.5" /></button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <Button size="sm" variant="secondary" onClick={() => setModel((m) => {
              const n = m.fields.length + 1;
              return { ...m, fields: [...m.fields, createField(`ฟิลด์ ${n}`, 'text', machineName('', `field_${n}`, m.fields.map((f) => f.name)))] };
            })}><Plus className="h-4 w-4" /> เพิ่มฟิลด์</Button>
            <label className="flex items-center gap-2"><span className="text-slate-500">ฟิลด์ที่ใช้แสดงชื่อรายการ</span>
              <select className="field-input w-auto" value={model.displayFieldId} onChange={(e) => setModel((m) => ({ ...m, displayFieldId: e.target.value }))}>
                {model.fields.map((f) => <option key={f.id} value={f.id}>{f.label}</option>)}
              </select>
            </label>
          </div>
        </section>

        <section>
          <h3 className="mb-1 font-semibold">สิทธิ์การเข้าถึงในแอป</h3>
          <p className="mb-2 text-xs text-slate-500">ค่าเริ่มต้นคือปฏิเสธ (default deny) — ผู้ใช้ Studio ของคุณจัดการข้อมูลได้เสมอ</p>
          <table className="text-xs">
            <thead><tr><th className="p-1 text-left">บทบาท</th>{ACTIONS.map(([a, l]) => <th key={a} className="p-1">{l}</th>)}</tr></thead>
            <tbody>
              {ROLES.map(([role, label]) => (
                <tr key={role}>
                  <th scope="row" className="p-1 text-left font-normal">{label}</th>
                  {ACTIONS.map(([a, l]) => (
                    <td key={a} className="p-1 text-center">
                      <input type="checkbox" aria-label={`${label} ${l}`} checked={permission.rules[role].includes(a)} onChange={(e) => setPermission((p) => ({
                        ...p,
                        rules: { ...p.rules, [role]: e.target.checked ? ACTIONS.map(([x]) => x).filter((x) => x === a || p.rules[role].includes(x)) : p.rules[role].filter((x) => x !== a) },
                      }))} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </section>

        {issues.length > 0 ? (
          <ul role="alert" className="list-disc rounded-lg bg-red-50 p-3 pl-6 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">{issues.slice(0, 8).map((m, i) => <li key={i}>{m}</li>)}</ul>
        ) : null}
        {impact ? (
          <div role="alert" className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p className="font-semibold">ผลกระทบต่อข้อมูลเดิม ({impact.totalRecords} รายการ) — ระบบจะไม่ลบหรือแก้ข้อมูลอัตโนมัติ</p>
            <ul className="list-disc pl-5">
              {impact.fields.filter((f) => f.invalidRecords || f.duplicateValues || f.recordsWithData).map((f) => (
                <li key={f.fieldId}>
                  {f.name}: {f.change === 'removed' ? `จะถูกซ่อน (มีข้อมูล ${f.recordsWithData} รายการ เก็บไว้ไม่ลบ)` : ''}
                  {f.invalidRecords ? ` ข้อมูลไม่ผ่านเงื่อนไขใหม่ ${f.invalidRecords} รายการ` : ''}
                  {f.duplicateValues ? ` ค่าซ้ำ ${f.duplicateValues} รายการ` : ''}
                </li>
              ))}
            </ul>
            <label className="flex items-center gap-2"><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} /> ฉันเข้าใจผลกระทบและต้องการบันทึก</label>
          </div>
        ) : null}
      </fieldset>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>ยกเลิก</Button>
        {canEdit ? <Button loading={busy} disabled={!!impact && !confirmed} onClick={() => void save()}>บันทึก</Button> : null}
      </div>
    </Dialog>
  );
}

/** Drop attributes that do not apply to the field type (the validator rejects them). */
function clean(f: FieldDefinition): FieldDefinition {
  const out: FieldDefinition = { id: f.id, name: f.name, label: f.label, type: f.type, required: f.required, unique: f.unique && f.type !== 'longtext' && f.type !== 'boolean' };
  if (f.type === 'select') out.options = f.options && f.options.length ? f.options : ['ตัวเลือก 1'];
  if ((f.type === 'number' || f.type === 'integer')) {
    if (f.min !== undefined) out.min = f.min;
    if (f.max !== undefined) out.max = f.max;
  }
  if (['text', 'longtext', 'email', 'phone', 'url'].includes(f.type) && f.maxLength !== undefined) out.maxLength = f.maxLength;
  if (f.helpText) out.helpText = f.helpText;
  return out;
}
