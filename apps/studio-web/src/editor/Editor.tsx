import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useLocation, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import {
  DndContext, DragOverlay, PointerSensor, KeyboardSensor, useSensor, useSensors, type DragEndEvent, type DragMoveEvent, type DragStartEvent,
} from '@dnd-kit/core';
import {
  AlertTriangle, ArrowLeft, Database, Sparkles, Table2, Zap, Blocks, CheckCircle2, CloudUpload, Eye, FileStack, History, Image as ImageIcon, Laptop, Layers, Loader2, Monitor, Palette, Redo2, Smartphone, Tablet, Undo2, ZoomIn, ZoomOut,
} from 'lucide-react';
import { createNode, type AppCommand } from '@studio/app-schema';
import { getComponentDefinition, type Breakpoint } from '@studio/component-registry';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { keys, useProject, useSchema, type SchemaHead } from '../lib/queries.ts';
import { Button } from '../components/ui/Button.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { ErrorState, FullPageSpinner, ThemeToggle, formatDate } from '../components/ui/misc.tsx';
import { Canvas, useFitZoom } from './Canvas.tsx';
import { Inspector } from './Inspector.tsx';
import { canvasDropTarget, layerDropTarget, moveIndex, type DragData } from './dnd.ts';
import { canvasRef, useDragState } from './dragState.ts';
import { useEditor, type PanelId } from './store.ts';
import { useAutosave } from './useAutosave.ts';
import { copySelected, duplicateSelected, moveSelectedBy, paste, removeSelected, reportRejected, selectParent } from './actions.ts';
import { ComponentsPanel } from './panels/ComponentsPanel.tsx';
import { LayersPanel } from './panels/LayersPanel.tsx';
import { PagesPanel } from './panels/PagesPanel.tsx';
import { AssetsPanel } from './panels/AssetsPanel.tsx';
import { ThemePanel } from './panels/ThemePanel.tsx';
import { VersionsPanel } from './panels/VersionsPanel.tsx';
import { DatabasePanel } from './panels/DatabasePanel.tsx';
import { WorkflowsPanel } from './panels/WorkflowsPanel.tsx';
import { AiPanel } from './panels/AiPanel.tsx';

const PANELS: Array<{ id: PanelId; label: string; icon: typeof Layers }> = [
  { id: 'ai', label: 'AI', icon: Sparkles },
  { id: 'pages', label: 'หน้า', icon: FileStack },
  { id: 'components', label: 'คอมโพเนนต์', icon: Blocks },
  { id: 'layers', label: 'เลเยอร์', icon: Layers },
  { id: 'assets', label: 'คลังภาพ', icon: ImageIcon },
  { id: 'theme', label: 'ธีม', icon: Palette },
  { id: 'database', label: 'ฐานข้อมูล', icon: Database },
  { id: 'workflows', label: 'Workflow', icon: Zap },
  { id: 'versions', label: 'เวอร์ชัน', icon: History },
];

const BREAKPOINTS: Array<{ id: Breakpoint; label: string; icon: typeof Monitor }> = [
  { id: 'base', label: 'เดสก์ท็อป', icon: Monitor },
  { id: 'tablet', label: 'แท็บเล็ต', icon: Tablet },
  { id: 'mobile', label: 'มือถือ', icon: Smartphone },
];

export default function EditorPage() {
  const { projectId = '' } = useParams();
  const project = useProject(projectId);
  const schema = useSchema(projectId);
  const loadedFor = useRef<string | null>(null);
  const ready = useEditor((s) => s.doc !== null && s.projectId === projectId);

  useEffect(() => {
    if (!project.data || !schema.data || loadedFor.current === projectId) return;
    loadedFor.current = projectId;
    const role = project.data.role;
    useEditor.getState().load({
      projectId,
      workspaceId: project.data.workspaceId,
      canEdit: role !== 'viewer' && project.data.status === 'active',
      doc: schema.data.document,
      revision: schema.data.revision,
    });
  }, [project.data, schema.data, projectId]);

  if (project.error || schema.error) {
    return <div className="p-8"><ErrorState message={errorMessage(project.error ?? schema.error)} /><Link to="/app" className="mt-4 inline-block text-brand-700 underline">กลับไปแดชบอร์ด</Link></div>;
  }
  if (!ready) return <FullPageSpinner />;
  return <EditorShell projectName={project.data!.name} archived={project.data!.status === 'archived'} />;
}

function EditorShell({ projectName, archived }: { projectName: string; archived: boolean }) {
  const panel = useEditor((s) => s.panel);
  const setPanel = useEditor((s) => s.setPanel);
  // Projects created from the dashboard's "describe with AI" option open the AI panel prefilled.
  const location = useLocation();
  const aiPrompt = (location.state as { aiPrompt?: string } | null)?.aiPrompt;
  useEffect(() => {
    if (aiPrompt) setPanel('ai');
  }, [aiPrompt, setPanel]);
  const { flushNow } = useAutosave();
  const centerRef = useRef<HTMLDivElement>(null);
  const [centerWidth, setCenterWidth] = useState(0);
  useFitZoom(centerWidth);
  useEffect(() => {
    const el = centerRef.current;
    if (!el) return;
    const ro = new ResizeObserver(() => setCenterWidth(el.clientWidth));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  useShortcuts(flushNow);
  const dnd = useDndController();

  return (
    <DndContext sensors={dnd.sensors} onDragStart={dnd.onDragStart} onDragMove={dnd.onDragMove} onDragEnd={dnd.onDragEnd} onDragCancel={dnd.onDragCancel} autoScroll={false}>
      <div className="flex h-full flex-col">
        <TopBar projectName={projectName} />
        {archived ? <div className="bg-amber-100 px-4 py-1.5 text-center text-sm text-amber-900">โปรเจกต์นี้ถูกเก็บถาวร (อ่านอย่างเดียว) — นำออกจากที่เก็บถาวรที่แดชบอร์ดเพื่อแก้ไข</div> : null}
        <ConflictBanner />
        <div className="flex min-h-0 flex-1">
          <nav className="flex w-16 shrink-0 flex-col items-center gap-1 border-r border-slate-200 bg-white py-2 dark:border-slate-800 dark:bg-navy-900" aria-label="แผงเครื่องมือ">
            {PANELS.map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => setPanel(p.id)}
                aria-pressed={panel === p.id}
                className={`flex w-14 flex-col items-center gap-0.5 rounded-lg py-2 text-[10px] ${panel === p.id ? 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-navy-800'}`}
              >
                <p.icon className="h-5 w-5" aria-hidden />
                {p.label}
              </button>
            ))}
          </nav>
          <aside className="w-72 shrink-0 overflow-y-auto border-r border-slate-200 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-navy-950" aria-label={PANELS.find((p) => p.id === panel)?.label}>
            <h2 className="mb-3 text-sm font-semibold">{PANELS.find((p) => p.id === panel)?.label}</h2>
            {panel === 'ai' ? <AiPanel initialPrompt={aiPrompt} /> : null}
            {panel === 'pages' ? <PagesPanel /> : null}
            {panel === 'components' ? <ComponentsPanel /> : null}
            {panel === 'layers' ? <LayersPanel /> : null}
            {panel === 'assets' ? <AssetsPanel /> : null}
            {panel === 'theme' ? <ThemePanel /> : null}
            {panel === 'database' ? <DatabasePanel /> : null}
            {panel === 'workflows' ? <WorkflowsPanel /> : null}
            {panel === 'versions' ? <VersionsPanel /> : null}
          </aside>
          <div ref={centerRef} className="flex min-w-0 flex-1 flex-col">
            <Canvas />
            <BottomBar />
          </div>
          <Inspector />
        </div>
      </div>
      <DragOverlay dropAnimation={null}>{dnd.overlay}</DragOverlay>
    </DndContext>
  );
}

function SaveIndicator() {
  const state = useEditor((s) => s.saveState);
  const message = useEditor((s) => s.saveMessage);
  const lastSavedAt = useEditor((s) => s.lastSavedAt);
  const revision = useEditor((s) => s.serverRevision);
  const map = {
    saved: { icon: CheckCircle2, text: 'บันทึกแล้ว', cls: 'text-emerald-700 dark:text-emerald-300' },
    pending: { icon: CloudUpload, text: 'มีการแก้ไข…', cls: 'text-slate-500' },
    saving: { icon: Loader2, text: 'กำลังบันทึก…', cls: 'text-slate-500' },
    retrying: { icon: AlertTriangle, text: message ?? 'กำลังลองใหม่', cls: 'text-amber-600' },
    conflict: { icon: AlertTriangle, text: 'ขัดแย้งกับเวอร์ชันล่าสุด', cls: 'text-red-600' },
    error: { icon: AlertTriangle, text: 'บันทึกไม่สำเร็จ', cls: 'text-red-600' },
    readonly: { icon: Eye, text: 'อ่านอย่างเดียว', cls: 'text-slate-500' },
  }[state];
  return (
    <span className={`inline-flex items-center gap-1.5 text-xs ${map.cls}`} role="status" aria-live="polite" data-testid="save-state" data-state={state}
      title={`rev ${revision}${lastSavedAt ? ` · ล่าสุด ${formatDate(new Date(lastSavedAt).toISOString())}` : ''}${message ? ` · ${message}` : ''}`}>
      <map.icon className={`h-3.5 w-3.5 ${state === 'saving' ? 'animate-spin' : ''}`} aria-hidden />
      <span className="hidden sm:inline">{map.text}</span>
    </span>
  );
}

function TopBar({ projectName }: { projectName: string }) {
  const projectId = useEditor((s) => s.projectId);
  const doc = useEditor((s) => s.doc)!;
  const pageId = useEditor((s) => s.pageId);
  const breakpoint = useEditor((s) => s.breakpoint);
  const setBreakpoint = useEditor((s) => s.setBreakpoint);
  const zoom = useEditor((s) => s.zoom);
  const setZoom = useEditor((s) => s.setZoom);
  const canUndo = useEditor((s) => s.past.length > 0);
  const canRedo = useEditor((s) => s.future.length > 0);
  const page = doc.pages.find((p) => p.id === pageId);

  return (
    <header className="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 bg-white px-3 dark:border-slate-800 dark:bg-navy-900">
      <Link to="/app" className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="กลับไปแดชบอร์ด"><ArrowLeft className="h-4 w-4" /></Link>
      <nav aria-label="breadcrumb" className="min-w-0 text-sm">
        <ol className="flex items-center gap-1.5 truncate">
          <li className="truncate font-semibold" title={projectName}>{projectName}</li>
          <li aria-hidden className="text-slate-400">/</li>
          <li className="truncate text-slate-500">{page?.name} <span className="text-xs">{page?.path}</span></li>
        </ol>
      </nav>
      <SaveIndicator />
      <div className="mx-auto flex items-center gap-1">
        <Button variant="ghost" size="icon" disabled={!canUndo} onClick={() => useEditor.getState().undo()} aria-label="เลิกทำ (Ctrl+Z)" title="เลิกทำ (Ctrl+Z)"><Undo2 className="h-4 w-4" /></Button>
        <Button variant="ghost" size="icon" disabled={!canRedo} onClick={() => useEditor.getState().redo()} aria-label="ทำซ้ำ (Ctrl+Shift+Z)" title="ทำซ้ำ (Ctrl+Shift+Z)"><Redo2 className="h-4 w-4" /></Button>
        <span className="mx-2 h-6 w-px bg-slate-200 dark:bg-slate-700" aria-hidden />
        <div role="radiogroup" aria-label="ขนาดหน้าจอ" className="flex rounded-lg bg-slate-100 p-0.5 dark:bg-navy-800">
          {BREAKPOINTS.map((b) => (
            <button key={b.id} role="radio" aria-checked={breakpoint === b.id} type="button" onClick={() => setBreakpoint(b.id)} title={b.label} aria-label={b.label}
              className={`rounded-md p-1.5 ${breakpoint === b.id ? 'bg-white shadow-sm dark:bg-navy-900' : 'text-slate-500'}`}>
              <b.icon className="h-4 w-4" />
            </button>
          ))}
        </div>
        <span className="mx-2 h-6 w-px bg-slate-200 dark:bg-slate-700" aria-hidden />
        <Button variant="ghost" size="icon" onClick={() => setZoom(zoom - 0.1)} aria-label="ซูมออก"><ZoomOut className="h-4 w-4" /></Button>
        <button type="button" className="w-12 text-center text-xs tabular-nums" onClick={() => setZoom(1)} title="ซูม 100%">{Math.round(zoom * 100)}%</button>
        <Button variant="ghost" size="icon" onClick={() => setZoom(zoom + 0.1)} aria-label="ซูมเข้า"><ZoomIn className="h-4 w-4" /></Button>
      </div>
      <ThemeToggle />
      <Link to={`/app/projects/${projectId}/data`} target="_blank" className="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-sm hover:bg-slate-100 dark:hover:bg-navy-800">
        <Table2 className="h-4 w-4" /> ข้อมูล
      </Link>
      <Link to={`/app/projects/${projectId}/preview?page=${encodeURIComponent(page?.path ?? '/')}`} target="_blank" className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-300 px-3 text-sm hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-navy-800">
        <Laptop className="h-4 w-4" /> ดูตัวอย่าง
      </Link>
    </header>
  );
}

function ConflictBanner() {
  const state = useEditor((s) => s.saveState);
  const message = useEditor((s) => s.saveMessage);
  const projectId = useEditor((s) => s.projectId);
  const qc = useQueryClient();
  const [busy, setBusy] = useState(false);
  if (state !== 'conflict' && state !== 'error') return null;
  const reload = async (reapply: boolean) => {
    setBusy(true);
    try {
      const head = (await api<Envelope<SchemaHead>>(`/projects/${projectId}/schema`)).data;
      qc.setQueryData(keys.schema(projectId), head);
      const r = useEditor.getState().rebase(head.document, head.revision, reapply);
      if (r.ok) toast.success(reapply ? 'รวมการแก้ไขของคุณกับเวอร์ชันล่าสุดแล้ว' : 'โหลดเวอร์ชันล่าสุดแล้ว');
      else toast.error(`${r.message} — กรุณาเลือก "โหลดเวอร์ชันล่าสุด"`);
    } catch (e) {
      toast.error(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <div role="alert" className="flex flex-wrap items-center gap-3 border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
      <AlertTriangle className="h-4 w-4" aria-hidden />
      <span className="flex-1">{state === 'conflict' ? 'มีผู้อื่นแก้ไขโปรเจกต์นี้ระหว่างที่คุณทำงาน' : `บันทึกไม่สำเร็จ: ${message}`}</span>
      {state === 'conflict' ? <Button size="sm" loading={busy} onClick={() => reload(true)}>รวมการแก้ไขของฉัน</Button> : null}
      <Button size="sm" variant="secondary" loading={busy} onClick={() => reload(false)}>โหลดเวอร์ชันล่าสุด (ทิ้งการแก้ไขที่ยังไม่บันทึก)</Button>
    </div>
  );
}

function BottomBar() {
  const issues = useEditor((s) => s.issues);
  const clear = useEditor((s) => s.clearIssues);
  const count = useEditor((s) => Object.keys(s.doc!.components).length);
  return (
    <div className="flex min-h-8 shrink-0 items-center gap-3 border-t border-slate-200 bg-white px-3 text-xs text-slate-500 dark:border-slate-800 dark:bg-navy-900">
      <span>{count} คอมโพเนนต์</span>
      <span aria-hidden>·</span>
      <span>Ctrl+Z เลิกทำ · Delete ลบ · Ctrl+D ทำสำเนา · Alt+↑/↓ ย้าย · Esc เลือกตัวแม่</span>
      {issues.length > 0 ? (
        <span role="alert" className="ml-auto flex items-center gap-2 text-red-600">
          <AlertTriangle className="h-3.5 w-3.5" /> ปัญหาการตรวจสอบ {issues.length} รายการ: {issues[0].message}
          <button type="button" className="underline" onClick={clear}>ปิด</button>
        </span>
      ) : null}
    </div>
  );
}

function isTyping(el: EventTarget | null): boolean {
  const t = el as HTMLElement | null;
  if (!t) return false;
  return t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName);
}

function useShortcuts(flushNow: () => void) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 's') {
        e.preventDefault();
        flushNow();
        return;
      }
      if (isTyping(e.target)) return;
      const s = useEditor.getState();
      if (mod && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        if (e.shiftKey) s.redo();
        else s.undo();
      } else if (mod && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        s.redo();
      } else if (mod && e.key.toLowerCase() === 'c') {
        copySelected();
      } else if (mod && e.key.toLowerCase() === 'v') {
        e.preventDefault();
        paste();
      } else if (mod && e.key.toLowerCase() === 'd') {
        e.preventDefault();
        duplicateSelected();
      } else if (e.key === 'Delete' || e.key === 'Backspace') {
        if (s.selectedId) {
          e.preventDefault();
          removeSelected();
        }
      } else if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        moveSelectedBy(e.key === 'ArrowUp' ? -1 : 1);
      } else if (e.key === 'Escape') {
        selectParent();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [flushNow]);
}

function useDndController() {
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }), useSensor(KeyboardSensor));
  const setDrag = useDragState((s) => s.set);
  const [overlay, setOverlay] = useState<ReactNode>(null);
  const start = useRef<{ x: number; y: number } | null>(null);

  const pointer = (e: DragMoveEvent | DragEndEvent) => {
    if (!start.current) return null;
    return { x: start.current.x + e.delta.x, y: start.current.y + e.delta.y };
  };

  const compute = (e: DragMoveEvent | DragEndEvent) => {
    const doc = useEditor.getState().doc;
    const drag = e.active.data.current as DragData | undefined;
    const p = pointer(e);
    if (!doc || !drag || !p) return null;
    const overId = e.over?.id ? String(e.over.id) : null;
    if (overId?.startsWith('layer:') && e.over) {
      return layerDropTarget(doc, overId.slice(6), e.over.rect, p.y, drag);
    }
    if (overId === 'canvas' && canvasRef.current) {
      return canvasDropTarget(canvasRef.current, doc, p.x, p.y, drag);
    }
    return null;
  };

  return {
    sensors,
    overlay,
    onDragStart: (e: DragStartEvent) => {
      const ev = e.activatorEvent as PointerEvent | KeyboardEvent;
      start.current = 'clientX' in ev ? { x: ev.clientX, y: ev.clientY } : null;
      const drag = e.active.data.current as DragData;
      setDrag({ drag, target: null });
      const doc = useEditor.getState().doc!;
      const label = drag.kind === 'new' ? getComponentDefinition(drag.type)?.labelTh : (doc.components[drag.id]?.name || getComponentDefinition(doc.components[drag.id]?.type ?? '')?.labelTh);
      setOverlay(<div className="pointer-events-none rounded-lg bg-brand-700 px-3 py-1.5 text-xs font-medium text-white shadow-xl">{label}</div>);
    },
    onDragMove: (e: DragMoveEvent) => setDrag({ target: compute(e) }),
    onDragEnd: (e: DragEndEvent) => {
      const target = compute(e);
      const drag = e.active.data.current as DragData;
      setDrag({ drag: null, target: null });
      setOverlay(null);
      start.current = null;
      if (!target) return;
      const s = useEditor.getState();
      const doc = s.doc!;
      let cmd: AppCommand | null = null;
      let selectId: string | null = null;
      if (drag.kind === 'new') {
        const node = createNode(drag.type);
        cmd = { type: 'component.insert', parentId: target.parentId, index: target.index, nodes: [node] };
        selectId = node.id;
      } else {
        const index = moveIndex(doc, drag.id, target);
        if (index === null) return;
        cmd = { type: 'component.move', id: drag.id, parentId: target.parentId, index };
        selectId = drag.id;
      }
      if (s.dispatch([cmd], { label: drag.kind === 'new' ? 'เพิ่มคอมโพเนนต์' : 'ย้ายคอมโพเนนต์' })) s.select(selectId);
      else reportRejected();
    },
    onDragCancel: () => {
      setDrag({ drag: null, target: null });
      setOverlay(null);
      start.current = null;
    },
  };
}
