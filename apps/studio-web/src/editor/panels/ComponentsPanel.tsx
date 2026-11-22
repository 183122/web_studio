import { useDraggable } from '@dnd-kit/core';
import { insertableComponents, type ComponentDefinition } from '@studio/component-registry';
import { iconFor } from '../icons.ts';
import { insertComponent } from '../actions.ts';

const CATEGORIES: Array<[ComponentDefinition['category'], string]> = [
  ['layout', 'โครงสร้าง'],
  ['basic', 'พื้นฐาน'],
  ['media', 'สื่อ'],
  ['navigation', 'การนำทาง'],
  ['data', 'ข้อมูลและฟอร์ม'],
];

function Item({ type, def }: { type: string; def: ComponentDefinition }) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: `new:${type}`, data: { kind: 'new', type } });
  const Icon = iconFor(def.icon);
  return (
    <button
      ref={setNodeRef}
      {...listeners}
      {...attributes}
      type="button"
      onClick={() => insertComponent(type)}
      data-testid={`palette-${type}`}
      title={`คลิกเพื่อเพิ่ม หรือ ลากไปวางบนแคนวาส`}
      className={`flex flex-col items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 py-3 text-xs hover:border-brand-500 hover:text-brand-700 dark:border-slate-700 dark:bg-navy-900 dark:hover:text-brand-300 ${isDragging ? 'opacity-40' : ''}`}
    >
      <Icon className="h-5 w-5" aria-hidden />
      <span>{def.labelTh}</span>
    </button>
  );
}

export function ComponentsPanel() {
  const all = insertableComponents();
  return (
    <div className="space-y-5">
      <p className="text-xs text-slate-500 dark:text-slate-400">ลากไปวางบนแคนวาส หรือคลิก/กด Enter เพื่อเพิ่มต่อจากรายการที่เลือก</p>
      {CATEGORIES.map(([cat, label]) => (
        <section key={cat}>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</h3>
          <div className="grid grid-cols-3 gap-2">
            {all.filter(([, d]) => d.category === cat).map(([type, def]) => <Item key={type} type={type} def={def} />)}
          </div>
        </section>
      ))}
    </div>
  );
}
