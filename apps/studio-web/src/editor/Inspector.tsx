import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Tabs } from 'radix-ui';
import { ArrowDown, ArrowUp, ImageIcon, Plus, RotateCcw, Trash2, X } from 'lucide-react';
import { getComponentDefinition, registry, type Breakpoint, type PropDefinition } from '@studio/component-registry';
import { COLOR_TOKENS, isSafeHref, isSafeImageSource, PATTERNS, validatePropValue, validateStyleValue, type ComponentNode } from '@studio/app-schema';
import { resolveStyles } from '@studio/renderer';
import { Dialog } from '../components/ui/Dialog.tsx';
import { AssetGrid } from './panels/AssetsPanel.tsx';
import { reportRejected } from './actions.ts';
import { useEditor } from './store.ts';

const BP_LABEL: Record<Breakpoint, string> = { base: 'เดสก์ท็อป (ค่าหลัก)', tablet: 'แท็บเล็ต', mobile: 'มือถือ' };

/** Text input with local draft: invalid values are shown with an error and never dispatched. */
function DraftInput({
  value, onCommit, validate, multiline = false, placeholder, maxLength, id, ariaLabel, rows = 3,
}: {
  value: string; onCommit: (v: string) => void; validate?: (v: string) => string | null; multiline?: boolean;
  placeholder?: string; maxLength?: number; id?: string; ariaLabel?: string; rows?: number;
}) {
  const [draft, setDraft] = useState(value);
  const [error, setError] = useState<string | null>(null);
  const focused = useRef(false);
  useEffect(() => {
    if (!focused.current) {
      setDraft(value);
      setError(null);
    }
  }, [value]);
  const change = (v: string) => {
    setDraft(v);
    const err = validate?.(v) ?? null;
    setError(err);
    if (!err && v !== value) onCommit(v);
  };
  const common = {
    id,
    value: draft,
    placeholder,
    maxLength,
    'aria-label': ariaLabel,
    'aria-invalid': error ? true : undefined,
    onFocus: () => (focused.current = true),
    onBlur: () => {
      focused.current = false;
      if (error) {
        setDraft(value);
        setError(null);
      }
    },
    className: `field-input ${error ? 'border-red-500' : ''}`,
  };
  return (
    <div>
      {multiline ? <textarea rows={rows} {...common} onChange={(e) => change(e.target.value)} /> : <input {...common} onChange={(e) => change(e.target.value)} />}
      {error ? <p role="alert" className="mt-1 text-xs text-red-600">{error}</p> : null}
    </div>
  );
}

function Row({ label, htmlFor, children, action }: { label: string; htmlFor?: string; children: ReactNode; action?: ReactNode }) {
  return (
    <div>
      <div className="flex items-center justify-between">
        <label className="field-label" htmlFor={htmlFor}>{label}</label>
        {action}
      </div>
      {children}
    </div>
  );
}

// ---------------- props ----------------

function ImagePicker({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const [open, setOpen] = useState(false);
  const isAsset = PATTERNS.assetRef.test(value);
  return (
    <div className="space-y-2">
      <div className="flex gap-2">
        <button type="button" onClick={() => setOpen(true)} className="field-input flex items-center gap-2 text-left">
          <ImageIcon className="h-4 w-4 shrink-0" aria-hidden />
          <span className="truncate">{value ? (isAsset ? 'รูปจากคลังภาพ' : value) : 'เลือกรูปภาพ…'}</span>
        </button>
        {value ? (
          <button type="button" onClick={() => onChange('')} className="rounded-lg border border-slate-300 px-2 dark:border-slate-600" aria-label="ล้างรูปภาพ">
            <X className="h-4 w-4" />
          </button>
        ) : null}
      </div>
      <Dialog open={open} onOpenChange={setOpen} title="เลือกรูปภาพ" wide>
        <AssetGrid
          compact
          onPick={(a) => {
            onChange(a.ref);
            setOpen(false);
          }}
        />
        <div className="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
          <label className="field-label" htmlFor="image-url">หรือใช้ URL รูปภาพ (https:// เท่านั้น)</label>
          <DraftInput id="image-url" value={isAsset ? '' : value} placeholder="https://…" validate={(v) => (v === '' || isSafeImageSource(v) ? null : 'ต้องเป็นลิงก์ https:// ที่ถูกต้อง')} onCommit={onChange} />
        </div>
      </Dialog>
    </div>
  );
}

function LinksEditor({ value, onChange, max }: { value: Array<{ label: string; href: string }>; onChange: (v: Array<{ label: string; href: string }>) => void; max: number }) {
  const update = (i: number, patch: Partial<{ label: string; href: string }>) => onChange(value.map((l, j) => (j === i ? { ...l, ...patch } : l)));
  const move = (i: number, d: number) => {
    const next = [...value];
    const [item] = next.splice(i, 1);
    next.splice(i + d, 0, item);
    onChange(next);
  };
  return (
    <div className="space-y-2">
      {value.map((l, i) => (
        <div key={i} className="space-y-1 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
          <DraftInput ariaLabel={`ข้อความเมนู ${i + 1}`} value={l.label} maxLength={80} onCommit={(v) => update(i, { label: v })} validate={(v) => (v.trim() ? null : 'กรุณากรอกข้อความ')} />
          <DraftInput ariaLabel={`ลิงก์เมนู ${i + 1}`} value={l.href} onCommit={(v) => update(i, { href: v })} validate={(v) => (isSafeHref(v) ? null : 'ลิงก์ไม่ปลอดภัยหรือไม่ถูกต้อง')} />
          <div className="flex justify-end gap-1">
            <button type="button" disabled={i === 0} onClick={() => move(i, -1)} aria-label="เลื่อนขึ้น" className="rounded p-1 disabled:opacity-30"><ArrowUp className="h-3.5 w-3.5" /></button>
            <button type="button" disabled={i === value.length - 1} onClick={() => move(i, 1)} aria-label="เลื่อนลง" className="rounded p-1 disabled:opacity-30"><ArrowDown className="h-3.5 w-3.5" /></button>
            <button type="button" onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label="ลบเมนู" className="rounded p-1 text-red-600"><Trash2 className="h-3.5 w-3.5" /></button>
          </div>
        </div>
      ))}
      {value.length < max ? (
        <button type="button" onClick={() => onChange([...value, { label: 'เมนูใหม่', href: '/' }])} className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 dark:text-brand-300">
          <Plus className="h-3.5 w-3.5" /> เพิ่มเมนู
        </button>
      ) : null}
    </div>
  );
}

function PropField({ node, name, def }: { node: ComponentNode; name: string; def: PropDefinition }) {
  const dispatch = useEditor((s) => s.dispatch);
  const value = node.props[name] ?? def.default;
  const id = `prop-${node.id}-${name}`;
  const commit = (v: unknown, coalesce = true) => {
    if (!dispatch([{ type: 'component.update', id: node.id, props: { [name]: v } }], { label: `แก้ไข ${def.label}`, coalesceKey: coalesce ? `${node.id}:${name}` : undefined })) reportRejected();
  };
  const validate = (v: unknown) => {
    const err = validatePropValue(def, v);
    return err ? `ค่าไม่ถูกต้อง (${err})` : null;
  };

  switch (def.kind) {
    case 'text':
    case 'slug':
    case 'videoId':
      return <Row label={def.label} htmlFor={id}><DraftInput id={id} value={String(value)} maxLength={def.maxLength} validate={validate} onCommit={commit} /></Row>;
    case 'longtext':
      return <Row label={def.label} htmlFor={id}><DraftInput id={id} multiline rows={4} value={String(value)} maxLength={def.maxLength} validate={validate} onCommit={commit} /></Row>;
    case 'url':
      return (
        <Row label={def.label} htmlFor={id}>
          <DraftInput id={id} value={String(value)} placeholder="/page, #section, https://…" validate={(v) => (isSafeHref(v) ? null : 'ใช้ได้เฉพาะ /หน้า, #ส่วน, http(s)://, mailto:, tel:')} onCommit={commit} />
        </Row>
      );
    case 'select':
      return (
        <Row label={def.label} htmlFor={id}>
          <select id={id} className="field-input" value={String(value)} onChange={(e) => commit(e.target.value, false)}>
            {def.options!.map((o) => <option key={o} value={o}>{o}</option>)}
          </select>
        </Row>
      );
    case 'boolean':
      return (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="h-4 w-4 accent-brand-700" checked={value === true} onChange={(e) => commit(e.target.checked, false)} /> {def.label}
        </label>
      );
    case 'image':
      return <Row label={def.label}><ImagePicker value={String(value)} onChange={(v) => commit(v, false)} /></Row>;
    case 'links':
      return <Row label={def.label}><LinksEditor value={value as Array<{ label: string; href: string }>} max={def.maxItems ?? 12} onChange={(v) => commit(v, false)} /></Row>;
    case 'stringList':
      return (
        <Row label={`${def.label} (บรรทัดละ 1 รายการ)`} htmlFor={id}>
          <DraftInput
            id={id}
            multiline
            rows={5}
            value={(value as string[]).join('\n')}
            validate={(v) => validate(v.split('\n'))}
            onCommit={(v) => commit(v.split('\n'))}
          />
        </Row>
      );
    default:
      return null;
  }
}

// ---------------- styles ----------------

const STYLE_GROUPS: Array<{ title: string; props: string[]; when?: (n: ComponentNode) => boolean }> = [
  { title: 'การจัดวาง', props: ['display', 'flexDirection', 'flexWrap', 'alignItems', 'justifyContent', 'gap', 'columns'], when: (n) => !!getComponentDefinition(n.type)?.acceptsChildren },
  { title: 'ระยะห่าง', props: ['padding', 'margin'] },
  { title: 'ขนาด', props: ['width', 'maxWidth', 'minHeight', 'height'] },
  { title: 'ตัวอักษร', props: ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textAlign', 'textTransform', 'color'] },
  { title: 'พื้นหลังและขอบ', props: ['backgroundColor', 'borderColor', 'borderWidth', 'borderRadius', 'boxShadow', 'opacity'] },
  { title: 'รูปภาพ', props: ['objectFit'], when: (n) => n.type === 'Image' },
];

const STYLE_LABEL: Record<string, string> = {
  display: 'การแสดงผล', flexDirection: 'ทิศทาง', flexWrap: 'การตัดบรรทัด', alignItems: 'จัดแนวแกนรอง', justifyContent: 'จัดแนวแกนหลัก', gap: 'ระยะห่างระหว่างลูก',
  columns: 'จำนวนคอลัมน์', padding: 'ระยะขอบใน (padding)', margin: 'ระยะขอบนอก (margin)', width: 'ความกว้าง', maxWidth: 'ความกว้างสูงสุด',
  minHeight: 'ความสูงต่ำสุด', height: 'ความสูง', fontFamily: 'ฟอนต์', fontSize: 'ขนาดตัวอักษร', fontWeight: 'น้ำหนักตัวอักษร', lineHeight: 'ระยะบรรทัด',
  letterSpacing: 'ระยะตัวอักษร', textAlign: 'จัดข้อความ', textTransform: 'ตัวพิมพ์', color: 'สีตัวอักษร', backgroundColor: 'สีพื้นหลัง', borderColor: 'สีขอบ',
  borderWidth: 'ความหนาขอบ', borderRadius: 'ความโค้งมุม', boxShadow: 'เงา', opacity: 'ความทึบ', objectFit: 'การปรับภาพ',
};

function StyleField({ node, prop, breakpoint }: { node: ComponentNode; prop: string; breakpoint: Breakpoint }) {
  const dispatch = useEditor((s) => s.dispatch);
  const def = registry.styleProperties[prop];
  const own = node.styles[breakpoint]?.[prop];
  const effective = resolveStyles(node.styles, breakpoint)[prop];
  const id = `style-${node.id}-${prop}`;
  const set = (v: string | number | null, coalesce = false) => {
    if (!dispatch([{ type: 'component.update', id: node.id, styles: { [breakpoint]: { [prop]: v } } }], { label: `แก้ไข ${STYLE_LABEL[prop]}`, coalesceKey: coalesce ? `${node.id}:${breakpoint}:${prop}` : undefined })) reportRejected();
  };
  const reset = own !== undefined ? (
    <button type="button" onClick={() => set(null)} className="text-slate-400 hover:text-slate-700" aria-label={`รีเซ็ต ${STYLE_LABEL[prop]}`} title="รีเซ็ต (ใช้ค่าที่สืบทอด)">
      <RotateCcw className="h-3 w-3" />
    </button>
  ) : null;
  const placeholder = own === undefined && effective !== undefined ? `สืบทอด: ${effective}` : 'ไม่ได้ตั้งค่า';

  let control: ReactNode;
  if (def.kind === 'enum') {
    control = (
      <select id={id} className="field-input" value={own === undefined ? '' : String(own)} onChange={(e) => set(e.target.value === '' ? null : e.target.value)}>
        <option value="">{placeholder}</option>
        {def.values!.map((v) => <option key={v} value={v}>{v}</option>)}
      </select>
    );
  } else if (def.kind === 'integer' || def.kind === 'number') {
    control = (
      <input
        id={id}
        type="number"
        className="field-input"
        min={def.min}
        max={def.max}
        step={def.kind === 'integer' ? 1 : 0.1}
        value={own === undefined ? '' : Number(own)}
        placeholder={placeholder}
        onChange={(e) => {
          if (e.target.value === '') return set(null);
          const n = Number(e.target.value);
          if (!validateStyleValue(prop, n)) set(n, true);
        }}
      />
    );
  } else if (def.kind === 'color') {
    const isToken = typeof own === 'string' && own.startsWith('$');
    control = (
      <div className="flex gap-2">
        <select id={id} className="field-input" value={own === undefined ? '' : isToken || own === 'transparent' ? String(own) : 'custom'} onChange={(e) => {
          const v = e.target.value;
          if (v === '') set(null);
          else if (v === 'custom') set('#000000');
          else set(v);
        }}>
          <option value="">{placeholder}</option>
          {COLOR_TOKENS.map((t) => <option key={t} value={`$${t}`}>ธีม: {t}</option>)}
          <option value="transparent">โปร่งใส</option>
          <option value="custom">กำหนดเอง…</option>
        </select>
        {own !== undefined && !isToken && own !== 'transparent' ? (
          <input type="color" aria-label={`${STYLE_LABEL[prop]} กำหนดเอง`} value={String(own).slice(0, 7)} onChange={(e) => set(e.target.value, true)} className="h-9 w-10 shrink-0 cursor-pointer rounded border border-slate-300 dark:border-slate-600" />
        ) : null}
      </div>
    );
  } else {
    control = (
      <DraftInput
        id={id}
        value={own === undefined ? '' : String(own)}
        placeholder={def.kind === 'box' ? `${placeholder} เช่น 16px 24px` : `${placeholder} เช่น 16px, 50%`}
        validate={(v) => (v === '' ? null : validateStyleValue(prop, v.trim()) ? 'รูปแบบไม่ถูกต้อง (เช่น 16px, 1.5rem, 50%, auto)' : null)}
        onCommit={(v) => set(v.trim() === '' ? null : v.trim(), true)}
      />
    );
  }
  return <Row label={STYLE_LABEL[prop] ?? prop} htmlFor={id} action={reset}>{control}</Row>;
}

function ComponentInspector({ node }: { node: ComponentNode }) {
  const breakpoint = useEditor((s) => s.breakpoint);
  const setBreakpoint = useEditor((s) => s.setBreakpoint);
  const dispatch = useEditor((s) => s.dispatch);
  const def = getComponentDefinition(node.type)!;
  return (
    <div className="space-y-5">
      <div>
        <p className="text-xs uppercase tracking-wide text-slate-500">{def.labelTh} · {node.type}</p>
        <label className="field-label mt-2" htmlFor={`name-${node.id}`}>ชื่อเลเยอร์</label>
        <DraftInput id={`name-${node.id}`} value={node.name ?? ''} maxLength={80} placeholder={def.labelTh} onCommit={(v) => dispatch([{ type: 'component.update', id: node.id, name: v === '' ? null : v }], { label: 'เปลี่ยนชื่อเลเยอร์', coalesceKey: `${node.id}:name` })} />
      </div>
      {Object.keys(def.props).length > 0 ? (
        <section className="space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">เนื้อหา</h3>
          {Object.entries(def.props).map(([name, pd]) => <PropField key={name} node={node} name={name} def={pd} />)}
        </section>
      ) : null}
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">สไตล์</h3>
          <select aria-label="Breakpoint ที่กำลังแก้ไข" className="rounded-md border border-slate-300 bg-transparent px-1.5 py-0.5 text-xs dark:border-slate-600" value={breakpoint} onChange={(e) => setBreakpoint(e.target.value as Breakpoint)}>
            {(Object.keys(BP_LABEL) as Breakpoint[]).map((b) => <option key={b} value={b}>{BP_LABEL[b]}</option>)}
          </select>
        </div>
        {breakpoint !== 'base' ? <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">ค่าที่ตั้งที่นี่จะใช้เฉพาะหน้าจอ{BP_LABEL[breakpoint]}และเล็กกว่า</p> : null}
        {STYLE_GROUPS.filter((g) => !g.when || g.when(node)).map((g) => (
          <details key={g.title} className="group rounded-lg border border-slate-200 dark:border-slate-700" open={g.title === 'ระยะห่าง' || g.title === 'ตัวอักษร'}>
            <summary className="cursor-pointer select-none px-3 py-2 text-sm font-medium">{g.title}</summary>
            <div className="space-y-3 px-3 pb-3">
              {g.props.map((p) => <StyleField key={p} node={node} prop={p} breakpoint={breakpoint} />)}
            </div>
          </details>
        ))}
      </section>
    </div>
  );
}

// ---------------- page & site ----------------

const PATH_RE = /^\/([a-z0-9]+(-[a-z0-9]+)*(\/[a-z0-9]+(-[a-z0-9]+)*)*)?$/;

function PageInspector() {
  const doc = useEditor((s) => s.doc)!;
  const pageId = useEditor((s) => s.pageId);
  const dispatch = useEditor((s) => s.dispatch);
  const page = doc.pages.find((p) => p.id === pageId)!;
  const upd = (changes: Record<string, unknown>, key: string) => {
    if (!dispatch([{ type: 'page.update', id: page.id, changes }], { label: 'แก้ไขหน้า', coalesceKey: `page:${page.id}:${key}` })) reportRejected();
  };
  const site = (changes: Record<string, unknown>, key: string) => {
    if (!dispatch([{ type: 'site.update', changes }], { label: 'แก้ไขเว็บไซต์', coalesceKey: `site:${key}` })) reportRejected();
  };
  return (
    <div className="space-y-6">
      <section className="space-y-3">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">หน้า: {page.name}</h3>
        <Row label="ชื่อหน้า" htmlFor="page-name"><DraftInput id="page-name" value={page.name} maxLength={80} validate={(v) => (v.trim() ? null : 'กรุณากรอกชื่อ')} onCommit={(v) => upd({ name: v }, 'name')} /></Row>
        <Row label="URL (path)" htmlFor="page-path">
          <DraftInput
            id="page-path"
            value={page.path}
            validate={(v) => (!PATH_RE.test(v) ? 'ใช้ a-z 0-9 และ - เช่น /about-us' : doc.pages.some((p) => p.path === v && p.id !== page.id) ? 'URL นี้ถูกใช้แล้ว' : null)}
            onCommit={(v) => upd({ path: v }, 'path')}
          />
        </Row>
        <Row label="SEO: ชื่อเรื่อง (title)" htmlFor="seo-title"><DraftInput id="seo-title" value={page.seo.title} maxLength={120} onCommit={(v) => upd({ seo: { title: v } }, 'seo-title')} /></Row>
        <Row label="SEO: คำอธิบาย (meta description)" htmlFor="seo-desc">
          <DraftInput id="seo-desc" multiline value={page.seo.description} maxLength={320} validate={(v) => (/[\r\n]/.test(v) ? 'ห้ามขึ้นบรรทัดใหม่' : null)} onCommit={(v) => upd({ seo: { description: v } }, 'seo-desc')} />
          <p className="mt-1 text-right text-[11px] text-slate-400">{page.seo.description.length}/320</p>
        </Row>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="h-4 w-4 accent-brand-700" checked={page.seo.noindex} onChange={(e) => upd({ seo: { noindex: e.target.checked } }, 'noindex')} /> ไม่ให้เครื่องมือค้นหาแสดงหน้านี้ (noindex)
        </label>
      </section>
      <section className="space-y-3">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">เว็บไซต์</h3>
        <Row label="ชื่อเว็บไซต์" htmlFor="site-name"><DraftInput id="site-name" value={doc.seo.siteName} maxLength={120} onCommit={(v) => site({ seo: { siteName: v } }, 'siteName')} /></Row>
        <Row label="คำอธิบายเว็บไซต์" htmlFor="site-desc"><DraftInput id="site-desc" multiline value={doc.seo.description} maxLength={320} validate={(v) => (/[\r\n]/.test(v) ? 'ห้ามขึ้นบรรทัดใหม่' : null)} onCommit={(v) => site({ seo: { description: v } }, 'siteDesc')} /></Row>
        <Row label="ภาษาหลัก" htmlFor="site-lang">
          <select id="site-lang" className="field-input" value={doc.seo.lang} onChange={(e) => site({ seo: { lang: e.target.value } }, 'lang')}>
            <option value="th">ไทย</option>
            <option value="en">English</option>
          </select>
        </Row>
        <Row label="หน้า 404 (ไม่พบหน้า)" htmlFor="site-404">
          <select id="site-404" className="field-input" value={doc.settings.notFoundPageId ?? ''} onChange={(e) => site({ settings: { notFoundPageId: e.target.value || null } }, '404')}>
            <option value="">ค่าเริ่มต้นของระบบ</option>
            {doc.pages.filter((p) => p.id !== doc.settings.homePageId).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </Row>
      </section>
    </div>
  );
}

export function Inspector() {
  const doc = useEditor((s) => s.doc)!;
  const selectedId = useEditor((s) => s.selectedId);
  const canEdit = useEditor((s) => s.canEdit);
  const node = selectedId ? doc.components[selectedId] : null;
  const [tab, setTab] = useState<'design' | 'page'>('design');
  useEffect(() => {
    if (selectedId) setTab('design');
  }, [selectedId]);

  return (
    <aside className="flex w-80 shrink-0 flex-col border-l border-slate-200 bg-white dark:border-slate-800 dark:bg-navy-900" aria-label="แผงคุณสมบัติ">
      <Tabs.Root value={tab} onValueChange={(v) => setTab(v as 'design' | 'page')} className="flex min-h-0 flex-1 flex-col">
        <Tabs.List className="flex border-b border-slate-200 text-sm dark:border-slate-800" aria-label="ประเภทคุณสมบัติ">
          <Tabs.Trigger value="design" className="flex-1 px-3 py-2.5 data-[state=active]:border-b-2 data-[state=active]:border-brand-600 data-[state=active]:font-medium">ออกแบบ</Tabs.Trigger>
          <Tabs.Trigger value="page" className="flex-1 px-3 py-2.5 data-[state=active]:border-b-2 data-[state=active]:border-brand-600 data-[state=active]:font-medium">หน้า & SEO</Tabs.Trigger>
        </Tabs.List>
        <fieldset disabled={!canEdit} className="min-h-0 flex-1 overflow-y-auto p-4">
          <Tabs.Content value="design">
            {node && node.type !== 'Page' ? <ComponentInspector key={node.id} node={node} /> : (
              <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500 dark:bg-navy-800 dark:text-slate-400">คลิกคอมโพเนนต์บนแคนวาสหรือในแผงเลเยอร์เพื่อแก้ไขคุณสมบัติ</p>
            )}
          </Tabs.Content>
          <Tabs.Content value="page">
            <PageInspector />
          </Tabs.Content>
        </fieldset>
      </Tabs.Root>
    </aside>
  );
}
