import { createContext, useContext, useEffect, useRef, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';
import { validateFieldValue, type ComponentNode, type DataModel, type FieldDefinition } from '@studio/app-schema';
import { useRender } from './Renderer.tsx';

export interface DataRow {
  id: string;
  data: Record<string, unknown>;
}

export type SubmitResult = { ok: true } | { ok: false; message: string; fieldErrors?: Record<string, string> };

export interface DataHost {
  submit(modelId: string, data: Record<string, unknown>, meta: { hp: string; elapsedMs: number }): Promise<SubmitResult>;
  list(modelId: string, q: { page: number; perPage: number; search: string }): Promise<{ rows: DataRow[]; total: number; lastPage: number }>;
}

const THAI_ERRORS: Record<string, string> = {
  required: 'กรุณากรอกข้อมูล',
  'must be an e-mail address': 'รูปแบบอีเมลไม่ถูกต้อง',
  'must be a phone number': 'รูปแบบเบอร์โทรไม่ถูกต้อง',
  'must be a number': 'กรุณากรอกตัวเลข',
  'must be a whole number': 'กรุณากรอกจำนวนเต็ม',
  'already exists': 'ข้อมูลนี้มีอยู่แล้วในระบบ',
};
const thaiError = (msg: string) => THAI_ERRORS[msg] ?? msg;

/** Same normalisation rules as RecordInput.php ('' → null, numeric strings → numbers). */
export function normalizeInput(field: FieldDefinition, raw: unknown): unknown {
  if (raw === undefined || raw === null) return null;
  if (typeof raw === 'boolean') return raw;
  const s = String(raw).trim();
  if (s === '') return null;
  if (field.type === 'number') return /^-?\d+(\.\d+)?$/.test(s) ? Number(s) : raw;
  if (field.type === 'integer') return /^-?\d{1,15}$/.test(s) ? Number(s) : raw;
  if (field.type === 'email') return s.toLowerCase();
  if (field.type === 'longtext') return raw;
  return s;
}

interface FormState {
  model: DataModel | null;
  values: Record<string, unknown>;
  errors: Record<string, string>;
  setValue: (fieldId: string, v: unknown) => void;
  disabled: boolean;
}
const FormCtx = createContext<FormState | null>(null);

const inputStyle: CSSProperties = {
  width: '100%', padding: '10px 12px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 16, fontFamily: 'inherit', background: '#fff', color: '#0f172a', boxSizing: 'border-box',
};

export function FormView({ node, css, attrs, children }: { node: ComponentNode; css: CSSProperties; attrs: Record<string, string>; children: ReactNode }) {
  const { doc, mode, dataHost } = useRender();
  const model = doc.dataModels.find((m) => m.id === node.props.modelId) ?? null;
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<'idle' | 'sending' | 'done' | 'error'>('idle');
  const [message, setMessage] = useState('');
  const hp = useRef<HTMLInputElement>(null);
  const started = useRef(Date.now());
  const editing = mode === 'edit';
  const submitLabel = typeof node.props.submitLabel === 'string' ? node.props.submitLabel : 'ส่งข้อมูล';

  // Fields actually placed in this form (only those are submitted).
  const placed = new Set<string>();
  const collect = (id: string) => {
    const n = doc.components[id];
    if (!n) return;
    if (n.type === 'FormField' && typeof n.props.fieldId === 'string') placed.add(n.props.fieldId);
    n.children.forEach(collect);
  };
  node.children.forEach(collect);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (editing || !model) return;
    if (!dataHost) {
      setStatus('error');
      setMessage('ฟอร์มนี้จะส่งข้อมูลได้เมื่อเผยแพร่เว็บไซต์หรือในหน้าดูตัวอย่าง');
      return;
    }
    const data: Record<string, unknown> = {};
    const errs: Record<string, string> = {};
    for (const f of model.fields) {
      if (!placed.has(f.id)) continue;
      const v = normalizeInput(f, values[f.id] ?? (f.type === 'boolean' ? false : null));
      const err = validateFieldValue(f, v);
      if (err) errs[f.id] = thaiError(err);
      data[f.id] = v;
    }
    setErrors(errs);
    if (Object.keys(errs).length > 0) return;
    setStatus('sending');
    const res = await dataHost.submit(model.id, data, { hp: hp.current?.value ?? '', elapsedMs: Date.now() - started.current });
    if (res.ok) {
      setStatus('done');
      setValues({});
    } else {
      setStatus('error');
      setMessage(res.message);
      setErrors(Object.fromEntries(Object.entries(res.fieldErrors ?? {}).map(([k, v]) => [k, thaiError(v)])));
    }
  };

  if (status === 'done') {
    return (
      <div {...attrs} role="status" style={{ ...css, alignItems: 'center', textAlign: 'center' }}>
        <p style={{ margin: 0, fontWeight: 600 }}>✓ {typeof node.props.successMessage === 'string' ? node.props.successMessage : 'ส่งข้อมูลเรียบร้อย'}</p>
        <button type="button" onClick={() => { setStatus('idle'); started.current = Date.now(); }} style={{ background: 'none', border: 0, textDecoration: 'underline', cursor: 'pointer', color: 'inherit' }}>
          ส่งอีกครั้ง
        </button>
      </div>
    );
  }

  const Tag = editing ? 'div' : 'form';
  return (
    <FormCtx.Provider value={{ model, values, errors, disabled: editing || status === 'sending', setValue: (id, v) => setValues((s) => ({ ...s, [id]: v })) }}>
      <Tag {...attrs} style={css} {...(editing ? {} : { onSubmit, noValidate: true })}>
        {!model && editing ? <p style={{ margin: 0, color: '#b45309', fontSize: 14 }}>⚠ เลือกตารางข้อมูลให้ฟอร์มในแผงคุณสมบัติ</p> : null}
        {/* Honeypot: invisible to people and screen readers, bots tend to fill it. */}
        <input ref={hp} type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" style={{ position: 'absolute', left: '-10000px', width: 1, height: 1, opacity: 0 }} />
        {children}
        {status === 'error' && message ? <p role="alert" style={{ margin: 0, color: '#b91c1c' }}>{message}</p> : null}
        <button
          type={editing ? 'button' : 'submit'}
          disabled={editing || status === 'sending'}
          style={{ alignSelf: 'flex-start', padding: '12px 22px', borderRadius: 999, border: 0, fontWeight: 600, fontSize: 16, cursor: 'pointer', background: doc.designTokens.colors.primary, color: '#fff', fontFamily: 'inherit' }}
        >
          {status === 'sending' ? 'กำลังส่ง…' : submitLabel}
        </button>
      </Tag>
    </FormCtx.Provider>
  );
}

export function FormFieldView({ node, css, attrs }: { node: ComponentNode; css: CSSProperties; attrs: Record<string, string> }) {
  const form = useContext(FormCtx);
  const field = form?.model?.fields.find((f) => f.id === node.props.fieldId);
  if (!form || !field) {
    return (
      <div {...attrs} style={{ ...css, padding: 12, border: '1px dashed #94a3b8', borderRadius: 8, color: '#64748b', fontSize: 14 }}>
        ช่องกรอกข้อมูล — เลือกฟิลด์ในแผงคุณสมบัติ
      </div>
    );
  }
  const id = `ff-${node.id}`;
  const label = (typeof node.props.label === 'string' && node.props.label) || field.label;
  const placeholder = typeof node.props.placeholder === 'string' ? node.props.placeholder : '';
  const help = (typeof node.props.helpText === 'string' && node.props.helpText) || field.helpText || '';
  const error = form.errors[field.id];
  const value = form.values[field.id];
  const describedBy = [help ? `${id}-help` : '', error ? `${id}-err` : ''].filter(Boolean).join(' ') || undefined;
  const common = {
    id, name: field.name, disabled: form.disabled, required: field.required, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy,
  };
  let control: ReactNode;
  switch (field.type) {
    case 'longtext':
      control = <textarea {...common} rows={4} placeholder={placeholder} value={String(value ?? '')} onChange={(e) => form.setValue(field.id, e.target.value)} style={inputStyle} />;
      break;
    case 'boolean':
      control = (
        <label htmlFor={id} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <input {...common} type="checkbox" checked={value === true} onChange={(e) => form.setValue(field.id, e.target.checked)} style={{ width: 18, height: 18 }} />
          <span>{label}</span>
        </label>
      );
      break;
    case 'select':
      control = (
        <select {...common} value={String(value ?? '')} onChange={(e) => form.setValue(field.id, e.target.value)} style={inputStyle}>
          <option value="">{placeholder || '— เลือก —'}</option>
          {(field.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
      );
      break;
    default: {
      const type = { email: 'email', phone: 'tel', url: 'url', number: 'number', integer: 'number', date: 'date', datetime: 'datetime-local' }[field.type as string] ?? 'text';
      control = (
        <input {...common} type={type} placeholder={placeholder} value={String(value ?? '')} step={field.type === 'integer' ? 1 : undefined}
          min={field.min} max={field.max} maxLength={field.maxLength} onChange={(e) => form.setValue(field.id, e.target.value)} style={inputStyle} />
      );
    }
  }
  return (
    <div {...attrs} style={{ display: 'flex', flexDirection: 'column', gap: 6, ...css }}>
      {field.type !== 'boolean' ? (
        <label htmlFor={id} style={{ fontWeight: 500 }}>
          {label}
          {field.required ? <span aria-hidden="true" style={{ color: '#dc2626' }}> *</span> : null}
        </label>
      ) : null}
      {control}
      {help ? <small id={`${id}-help`} style={{ color: '#64748b' }}>{help}</small> : null}
      {error ? <small id={`${id}-err`} role="alert" style={{ color: '#b91c1c' }}>{error}</small> : null}
    </div>
  );
}

function formatValue(field: FieldDefinition | undefined, v: unknown): string {
  if (v === null || v === undefined) return '—';
  if (field?.type === 'boolean') return v ? '✓' : '✗';
  return String(v);
}

export function DataTableView({ node, css, attrs }: { node: ComponentNode; css: CSSProperties; attrs: Record<string, string> }) {
  const { doc, mode, dataHost } = useRender();
  const model = doc.dataModels.find((m) => m.id === node.props.modelId);
  const ids = Array.isArray(node.props.fieldIds) && node.props.fieldIds.length > 0 ? (node.props.fieldIds as string[]) : (model?.fields.slice(0, 4).map((f) => f.id) ?? []);
  const columns = ids.map((id) => model?.fields.find((f) => f.id === id)).filter((f): f is FieldDefinition => !!f);
  const perPage = Number(node.props.pageSize) || 10;
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [state, setState] = useState<{ rows: DataRow[]; total: number; lastPage: number; error?: string; loading: boolean }>({ rows: [], total: 0, lastPage: 1, loading: true });

  useEffect(() => {
    if (mode === 'edit' || !model || !dataHost) return;
    let cancelled = false;
    setState((s) => ({ ...s, loading: true }));
    const t = setTimeout(() => {
      dataHost.list(model.id, { page, perPage, search }).then(
        (r) => !cancelled && setState({ ...r, loading: false }),
        (e: Error) => !cancelled && setState({ rows: [], total: 0, lastPage: 1, loading: false, error: e.message }),
      );
    }, search ? 300 : 0);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [mode, model, dataHost, page, perPage, search]);

  if (!model) {
    return mode === 'edit' ? <div {...attrs} style={{ ...css, padding: 16, border: '1px dashed #94a3b8', borderRadius: 8, color: '#64748b' }}>ตารางแสดงข้อมูล — เลือกตารางข้อมูลในแผงคุณสมบัติ</div> : null;
  }
  const rows: DataRow[] = mode === 'edit'
    ? [1, 2, 3].map((i) => ({ id: `sample-${i}`, data: Object.fromEntries(columns.map((c) => [c.id, `ตัวอย่าง ${c.label} ${i}`])) }))
    : state.rows;
  const cell: CSSProperties = { padding: '10px 12px', borderBottom: '1px solid #e2e8f0', textAlign: 'left', verticalAlign: 'top' };

  return (
    <div {...attrs} style={{ overflowX: 'auto', ...css }}>
      {node.props.searchable === true ? (
        <input type="search" aria-label={`ค้นหาใน ${model.label}`} placeholder="ค้นหา…" value={search} disabled={mode === 'edit'}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }} style={{ ...inputStyle, maxWidth: 320, marginBottom: 12 }} />
      ) : null}
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 15 }}>
        <caption style={{ position: 'absolute', left: -10000 }}>{model.label}</caption>
        <thead>
          <tr>{columns.map((c) => <th key={c.id} scope="col" style={{ ...cell, fontWeight: 600, background: 'rgba(148,163,184,0.12)' }}>{c.label}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>{columns.map((c) => <td key={c.id} style={{ ...cell, color: mode === 'edit' ? '#94a3b8' : undefined }}>{formatValue(c, r.data[c.id])}</td>)}</tr>
          ))}
          {mode !== 'edit' && !state.loading && rows.length === 0 ? (
            <tr><td colSpan={columns.length} style={{ ...cell, textAlign: 'center', color: '#64748b' }}>{state.error ?? (typeof node.props.emptyMessage === 'string' ? node.props.emptyMessage : 'ยังไม่มีข้อมูล')}</td></tr>
          ) : null}
        </tbody>
      </table>
      {mode !== 'edit' && state.lastPage > 1 ? (
        <nav aria-label="เปลี่ยนหน้า" style={{ display: 'flex', gap: 8, alignItems: 'center', justifyContent: 'flex-end', marginTop: 8, fontSize: 14 }}>
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>‹ ก่อนหน้า</button>
          <span>หน้า {page} / {state.lastPage} ({state.total} รายการ)</span>
          <button type="button" disabled={page >= state.lastPage} onClick={() => setPage((p) => p + 1)}>ถัดไป ›</button>
        </nav>
      ) : null}
    </div>
  );
}
