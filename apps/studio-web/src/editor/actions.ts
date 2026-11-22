import { cloneWithNewIds, collectSubtree, createNode, createPage, type AppDefinition } from '@studio/app-schema';
import { getComponentDefinition } from '@studio/component-registry';
import { toast } from '../components/ui/Toast.tsx';
import { parentOf, useEditor } from './store.ts';
import type { DropTarget } from './dnd.ts';

const get = () => useEditor.getState();

function pageRoot(doc: AppDefinition, pageId: string): string {
  return doc.pages.find((p) => p.id === pageId)!.rootId;
}

/** Where a click-to-insert should go: inside the selected container, after the selected leaf, or at page end. */
export function defaultInsertTarget(): DropTarget | null {
  const { doc, selectedId, pageId } = get();
  if (!doc) return null;
  if (selectedId && doc.components[selectedId]) {
    const node = doc.components[selectedId];
    if (getComponentDefinition(node.type)?.acceptsChildren) return { parentId: selectedId, index: node.children.length };
    const parent = parentOf(doc, selectedId);
    if (parent) return { parentId: parent, index: doc.components[parent].children.indexOf(selectedId) + 1 };
  }
  const root = pageRoot(doc, pageId);
  return { parentId: root, index: doc.components[root].children.length };
}

export function insertComponent(type: string, target: DropTarget | null = defaultInsertTarget()): boolean {
  if (!target) return false;
  const node = createNode(type);
  const def = getComponentDefinition(type);
  const ok = get().dispatch([{ type: 'component.insert', parentId: target.parentId, index: target.index, nodes: [node] }], { label: `เพิ่ม ${def?.labelTh ?? type}` });
  if (ok) get().select(node.id);
  else reportRejected();
  return ok;
}

export function removeSelected(): void {
  const { doc, selectedId } = get();
  if (!doc || !selectedId) return;
  const node = doc.components[selectedId];
  if (!node || node.type === 'Page') return;
  const parent = parentOf(doc, selectedId);
  if (get().dispatch([{ type: 'component.remove', id: selectedId }], { label: 'ลบคอมโพเนนต์' })) {
    get().select(parent && doc.components[parent]?.type !== 'Page' ? parent : null);
  }
}

export function duplicateSelected(): void {
  const { doc, selectedId } = get();
  if (!doc || !selectedId || doc.components[selectedId]?.type === 'Page') return;
  const parent = parentOf(doc, selectedId);
  if (!parent) return;
  const copy = cloneWithNewIds(collectSubtree(doc, selectedId));
  const index = doc.components[parent].children.indexOf(selectedId) + 1;
  if (get().dispatch([{ type: 'component.insert', parentId: parent, index, nodes: copy }], { label: 'ทำสำเนา' })) get().select(copy[0].id);
  else reportRejected();
}

export function copySelected(): void {
  const { doc, selectedId } = get();
  if (!doc || !selectedId || doc.components[selectedId]?.type === 'Page') return;
  get().setClipboard(structuredClone(collectSubtree(doc, selectedId)));
  toast.info('คัดลอกแล้ว');
}

export function paste(): void {
  const { clipboard } = get();
  if (!clipboard) return;
  const target = defaultInsertTarget();
  if (!target) return;
  const nodes = cloneWithNewIds(clipboard);
  if (get().dispatch([{ type: 'component.insert', parentId: target.parentId, index: target.index, nodes }], { label: 'วาง' })) get().select(nodes[0].id);
  else reportRejected();
}

export function moveSelectedBy(delta: -1 | 1): void {
  const { doc, selectedId } = get();
  if (!doc || !selectedId) return;
  const parent = parentOf(doc, selectedId);
  if (!parent) return;
  const siblings = doc.components[parent].children;
  const index = siblings.indexOf(selectedId) + delta;
  if (index < 0 || index >= siblings.length) return;
  get().dispatch([{ type: 'component.move', id: selectedId, parentId: parent, index }], { label: 'ย้ายตำแหน่ง' });
}

export function selectParent(): void {
  const { doc, selectedId } = get();
  if (!doc || !selectedId) return;
  const parent = parentOf(doc, selectedId);
  get().select(parent && doc.components[parent].type !== 'Page' ? parent : null);
}

export function addPage(name: string, path: string): boolean {
  const { doc } = get();
  if (!doc) return false;
  const { page, root } = createPage(name, path);
  const ok = get().dispatch([{ type: 'page.add', page, index: doc.pages.length, nodes: [root] }], { label: 'เพิ่มหน้า' });
  if (ok) get().setPage(page.id);
  else reportRejected();
  return ok;
}

export function reportRejected(): void {
  const issue = get().issues[0];
  toast.error(issue ? `ไม่สามารถทำรายการได้: ${issue.message}` : 'ไม่สามารถทำรายการได้');
}

/** Thai-friendly slug: keeps a-z0-9, otherwise falls back to page-N. */
export function slugify(input: string, fallbackIndex: number): string {
  const slug = input
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);
  return slug || `page-${fallbackIndex}`;
}
