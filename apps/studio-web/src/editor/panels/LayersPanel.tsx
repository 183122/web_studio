import { useState } from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { ChevronDown, ChevronRight, Eye } from 'lucide-react';
import { getComponentDefinition } from '@studio/component-registry';
import { iconFor } from '../icons.ts';
import { useEditor } from '../store.ts';
import { useDragState } from '../dragState.ts';

function Row({ id, depth, collapsed, toggle }: { id: string; depth: number; collapsed: Set<string>; toggle: (id: string) => void }) {
  const node = useEditor((s) => s.doc?.components[id]);
  const selected = useEditor((s) => s.selectedId === id);
  const select = useEditor((s) => s.select);
  const hover = useEditor((s) => s.hover);
  const target = useDragState((s) => s.target);
  const isPage = node?.type === 'Page';
  const drag = useDraggable({ id: `node:${id}`, data: { kind: 'node', id }, disabled: isPage });
  const drop = useDroppable({ id: `layer:${id}`, data: { layerId: id } });
  if (!node) return null; // removed while a render was in flight
  const def = getComponentDefinition(node.type);
  const Icon = iconFor(def?.icon ?? 'square');
  const hasChildren = node.children.length > 0;
  const isCollapsed = collapsed.has(id);
  const isTarget = target?.parentId === id;

  return (
    <li role="treeitem" aria-selected={selected} aria-expanded={hasChildren ? !isCollapsed : undefined}>
      <div
        ref={(el) => {
          drag.setNodeRef(el);
          drop.setNodeRef(el);
        }}
        className={`group flex items-center gap-1 rounded-md py-1 pr-1 text-sm ${selected ? 'bg-brand-100 text-brand-900 dark:bg-brand-900/40 dark:text-brand-100' : 'hover:bg-slate-100 dark:hover:bg-navy-800'} ${isTarget ? 'ring-2 ring-brand-500' : ''} ${drag.isDragging ? 'opacity-40' : ''}`}
        style={{ paddingLeft: 4 + depth * 14 }}
        onMouseEnter={() => hover(isPage ? null : id)}
        onMouseLeave={() => hover(null)}
      >
        <button
          type="button"
          className={`rounded p-0.5 ${hasChildren ? '' : 'invisible'}`}
          onClick={() => toggle(id)}
          aria-label={isCollapsed ? 'ขยาย' : 'ยุบ'}
          tabIndex={-1}
        >
          {isCollapsed ? <ChevronRight className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
        </button>
        <button
          type="button"
          {...(isPage ? {} : drag.listeners)}
          {...(isPage ? {} : drag.attributes)}
          onClick={() => select(isPage ? null : id)}
          className="flex min-w-0 flex-1 items-center gap-1.5 text-left"
          data-testid={`layer-${id}`}
        >
          <Icon className="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden />
          <span className="truncate">{node.name || def?.labelTh || node.type}</span>
          {node.type === 'Heading' || node.type === 'Text' || node.type === 'Button' ? (
            <span className="truncate text-xs text-slate-400">{String(node.props.text ?? node.props.label ?? '').slice(0, 24)}</span>
          ) : null}
        </button>
        {selected ? <Eye className="h-3.5 w-3.5 opacity-50" aria-hidden /> : null}
      </div>
      {hasChildren && !isCollapsed ? (
        <ul role="group">
          {node.children.map((c) => <Row key={c} id={c} depth={depth + 1} collapsed={collapsed} toggle={toggle} />)}
        </ul>
      ) : null}
    </li>
  );
}

export function LayersPanel() {
  const doc = useEditor((s) => s.doc)!;
  const pageId = useEditor((s) => s.pageId);
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const page = doc.pages.find((p) => p.id === pageId)!;
  const toggle = (id: string) =>
    setCollapsed((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });
  return (
    <div>
      <p className="mb-2 text-xs text-slate-500 dark:text-slate-400">ลากเพื่อจัดลำดับ: วางช่วงบน/ล่างของแถว = ก่อน/หลัง, กลางแถวของกล่อง = ใส่ข้างใน</p>
      <ul role="tree" aria-label={`โครงสร้างหน้า ${page.name}`}>
        <Row id={page.rootId} depth={0} collapsed={collapsed} toggle={toggle} />
      </ul>
    </div>
  );
}
