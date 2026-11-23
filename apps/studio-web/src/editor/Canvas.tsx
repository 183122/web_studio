import { useEffect, useLayoutEffect, useRef, useState, type CSSProperties } from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { ArrowDown, ArrowUp, Copy, CornerLeftUp, GripVertical, Trash2 } from 'lucide-react';
import { BREAKPOINT_WIDTHS, getComponentDefinition } from '@studio/component-registry';
import { PageRenderer } from '@studio/renderer';
import { useAssetUrls } from '../lib/queries.ts';
import { duplicateSelected, moveSelectedBy, removeSelected, selectParent } from './actions.ts';
import { canvasRef, useDragState } from './dragState.ts';
import { useCollab } from './collab.ts';
import { useEditor } from './store.ts';

interface Box { top: number; left: number; width: number; height: number }

function useNodeBox(frame: HTMLDivElement | null, id: string | null, zoom: number, deps: unknown[]): Box | null {
  const [box, setBox] = useState<Box | null>(null);
  useLayoutEffect(() => {
    if (!frame || !id) {
      setBox(null);
      return;
    }
    let raf = 0;
    const measure = () => {
      const el = frame.querySelector<HTMLElement>(`[data-node-id="${CSS.escape(id)}"]`);
      if (!el) return setBox(null);
      const f = frame.getBoundingClientRect();
      const r = el.getBoundingClientRect();
      setBox({ top: (r.top - f.top) / zoom, left: (r.left - f.left) / zoom, width: r.width / zoom, height: r.height / zoom });
    };
    measure();
    const ro = new ResizeObserver(() => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(measure);
    });
    ro.observe(frame);
    return () => {
      ro.disconnect();
      cancelAnimationFrame(raf);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frame, id, zoom, ...deps]);
  return box;
}

export function Canvas() {
  const doc = useEditor((s) => s.doc)!;
  const pageId = useEditor((s) => s.pageId);
  const breakpoint = useEditor((s) => s.breakpoint);
  const zoom = useEditor((s) => s.zoom);
  const selectedId = useEditor((s) => s.selectedId);
  const participants = useCollab((s) => s.participants);
  const remote = participants.filter((p) => !p.me && p.pageId === pageId && p.selectedId && p.selectedId !== selectedId);
  const hoveredId = useEditor((s) => s.hoveredId);
  const workspaceId = useEditor((s) => s.workspaceId);
  const select = useEditor((s) => s.select);
  const hover = useEditor((s) => s.hover);
  const target = useDragState((s) => s.target);
  const dragging = useDragState((s) => s.drag);
  const assetUrls = useAssetUrls(workspaceId, doc.assets);
  const [frame, setFrame] = useState<HTMLDivElement | null>(null);
  const [frameHeight, setFrameHeight] = useState(800);
  const { setNodeRef } = useDroppable({ id: 'canvas' });
  const width = BREAKPOINT_WIDTHS[breakpoint];

  useEffect(() => {
    canvasRef.current = frame;
    if (!frame) return;
    const ro = new ResizeObserver(() => setFrameHeight(frame.offsetHeight));
    ro.observe(frame);
    return () => ro.disconnect();
  }, [frame]);

  const selectedBox = useNodeBox(frame, selectedId, zoom, [doc, breakpoint, pageId, frameHeight]);
  const hoverBox = useNodeBox(frame, hoveredId !== selectedId ? hoveredId : null, zoom, [doc, breakpoint, pageId]);
  const targetBox = useNodeBox(frame, target?.parentId ?? null, zoom, [doc, target]);
  const indicator = useDropIndicator(frame, zoom, target);

  const selectedNode = selectedId ? doc.components[selectedId] : null;

  return (
    <div className="canvas-dots relative min-h-0 flex-1 overflow-auto" onClick={(e) => e.target === e.currentTarget && select(null)}>
      <div className="mx-auto py-10" style={{ width: width * zoom + 80 }}>
        <div style={{ width: width * zoom, height: frameHeight * zoom }} className="relative mx-auto">
          <div
            ref={(el) => {
              setFrame(el);
              setNodeRef(el);
            }}
            data-testid="canvas-frame"
            className="absolute left-0 top-0 origin-top-left bg-white shadow-[0_8px_40px_rgba(15,23,42,0.18)]"
            style={{ width, minHeight: 600, transform: `scale(${zoom})` }}
            onClickCapture={(e) => {
              const el = (e.target as HTMLElement).closest<HTMLElement>('[data-node-id]');
              if (!el) return;
              e.preventDefault();
              const id = el.dataset.nodeId!;
              select(doc.components[id]?.type === 'Page' ? null : id);
            }}
            onMouseOver={(e) => {
              const el = (e.target as HTMLElement).closest<HTMLElement>('[data-node-id]');
              const id = el?.dataset.nodeId ?? null;
              if (id !== hoveredId) hover(id && doc.components[id]?.type !== 'Page' ? id : null);
            }}
            onMouseLeave={() => hover(null)}
          >
            <PageRenderer doc={doc} pageId={pageId} breakpoint={breakpoint} mode="edit" resolveAsset={(ref) => assetUrls.data?.[ref]} />

            {hoverBox && !dragging ? <Outline box={hoverBox} className="border border-dashed border-brand-400" /> : null}
            {dragging && targetBox ? <Outline box={targetBox} className="border-2 border-brand-500/60 bg-brand-500/5" /> : null}
            {indicator ? (
              <div className="pointer-events-none absolute z-20 rounded-full bg-brand-500" style={indicator} data-testid="drop-indicator" />
            ) : null}
            {remote.map((p) => <RemoteSelection key={p.userId} frame={frame} id={p.selectedId!} name={p.name} color={p.color} zoom={zoom} deps={[doc, breakpoint, pageId, frameHeight]} />)}
            {selectedBox && selectedNode ? (
              <>
                <Outline box={selectedBox} className="border-2 border-brand-600" />
                {!dragging ? <SelectionToolbar box={selectedBox} id={selectedNode.id} label={selectedNode.name || getComponentDefinition(selectedNode.type)?.labelTh || selectedNode.type} zoom={zoom} /> : null}
              </>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  );
}

/** Another collaborator's selection on this page (outline + name tag in their colour). */
function RemoteSelection({ frame, id, name, color, zoom, deps }: { frame: HTMLDivElement | null; id: string; name: string; color: string; zoom: number; deps: unknown[] }) {
  const box = useNodeBox(frame, id, zoom, deps);
  if (!box) return null;
  return (
    <div className="pointer-events-none absolute z-10" style={{ top: box.top, left: box.left, width: box.width, height: box.height, outline: `2px solid ${color}` }} data-testid="remote-selection">
      <span className="absolute -top-5 left-0 rounded px-1.5 text-[11px] font-medium text-white" style={{ background: color }}>{name}</span>
    </div>
  );
}

function Outline({ box, className }: { box: Box; className: string }) {
  return <div className={`pointer-events-none absolute z-10 ${className}`} style={{ top: box.top, left: box.left, width: box.width, height: box.height }} />;
}

function useDropIndicator(frame: HTMLDivElement | null, zoom: number, target: { parentId: string; index: number } | null): CSSProperties | null {
  const doc = useEditor((s) => s.doc);
  if (!frame || !target || !doc) return null;
  const parent = doc.components[target.parentId];
  if (!parent) return null;
  const f = frame.getBoundingClientRect();
  const q = (id: string) => frame.querySelector<HTMLElement>(`[data-node-id="${CSS.escape(id)}"]`)?.getBoundingClientRect();
  const parentRect = q(parent.id);
  if (!parentRect) return null;
  const thickness = 3 / zoom;
  if (parent.children.length === 0) {
    return { top: (parentRect.top - f.top) / zoom + 4, left: (parentRect.left - f.left) / zoom + 4, width: parentRect.width / zoom - 8, height: thickness };
  }
  const beforeId = parent.children[target.index];
  const afterId = parent.children[target.index - 1];
  const before = beforeId ? q(beforeId) : undefined;
  const after = afterId ? q(afterId) : undefined;
  // Horizontal neighbours (same row) → vertical bar; otherwise horizontal line.
  if (before && after && Math.abs(before.top - after.top) < 4) {
    return { top: (before.top - f.top) / zoom, left: (before.left - f.left) / zoom - 2 / zoom, width: thickness, height: before.height / zoom };
  }
  if (before) return { top: (before.top - f.top) / zoom - 2 / zoom, left: (before.left - f.left) / zoom, width: before.width / zoom, height: thickness };
  if (after) return { top: (after.bottom - f.top) / zoom, left: (after.left - f.left) / zoom, width: after.width / zoom, height: thickness };
  return null;
}

function SelectionToolbar({ box, id, label, zoom }: { box: Box; id: string; label: string; zoom: number }) {
  const { attributes, listeners, setNodeRef } = useDraggable({ id: `node:${id}`, data: { kind: 'node', id } });
  const above = box.top > 34 / zoom;
  const btn = 'rounded p-1 hover:bg-white/20';
  return (
    <div
      className="absolute z-30 flex items-center gap-0.5 rounded-md bg-brand-700 px-1 py-0.5 text-xs text-white shadow-lg"
      style={{ top: above ? box.top - 30 / zoom : box.top + box.height + 4 / zoom, left: box.left, transform: `scale(${1 / zoom})`, transformOrigin: above ? 'bottom left' : 'top left' }}
      onClick={(e) => e.stopPropagation()}
    >
      <button ref={setNodeRef} {...listeners} {...attributes} className={`${btn} cursor-grab`} aria-label={`ลากเพื่อย้าย ${label}`} title="ลากเพื่อย้าย">
        <GripVertical className="h-3.5 w-3.5" />
      </button>
      <span className="max-w-40 truncate px-1 font-medium">{label}</span>
      <button className={btn} onClick={selectParent} aria-label="เลือกองค์ประกอบแม่" title="เลือกองค์ประกอบแม่ (Esc)"><CornerLeftUp className="h-3.5 w-3.5" /></button>
      <button className={btn} onClick={() => moveSelectedBy(-1)} aria-label="ย้ายขึ้น" title="ย้ายขึ้น (Alt+↑)"><ArrowUp className="h-3.5 w-3.5" /></button>
      <button className={btn} onClick={() => moveSelectedBy(1)} aria-label="ย้ายลง" title="ย้ายลง (Alt+↓)"><ArrowDown className="h-3.5 w-3.5" /></button>
      <button className={btn} onClick={duplicateSelected} aria-label="ทำสำเนา" title="ทำสำเนา (Ctrl+D)"><Copy className="h-3.5 w-3.5" /></button>
      <button className={btn} onClick={removeSelected} aria-label="ลบ" title="ลบ (Delete)"><Trash2 className="h-3.5 w-3.5" /></button>
    </div>
  );
}

export function useFitZoom(containerWidth: number) {
  const breakpoint = useEditor((s) => s.breakpoint);
  const setZoom = useEditor((s) => s.setZoom);
  const fitted = useRef<string>('');
  useEffect(() => {
    const key = `${breakpoint}:${Math.round(containerWidth / 50)}`;
    if (!containerWidth || fitted.current === key) return;
    fitted.current = key;
    setZoom(Math.min(1, (containerWidth - 80) / BREAKPOINT_WIDTHS[breakpoint]));
  }, [breakpoint, containerWidth, setZoom]);
}
