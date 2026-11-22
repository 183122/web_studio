import { getComponentDefinition } from '@studio/component-registry';
import type { AppDefinition } from '@studio/app-schema';
import { isDescendant, parentOf } from './store.ts';

export interface DropTarget {
  parentId: string;
  /** Index among the parent's CURRENT children (before removing a moved node). */
  index: number;
}

export type DragData = { kind: 'new'; type: string } | { kind: 'node'; id: string };

const EDGE_PX = 10;

function acceptsChildren(doc: AppDefinition, id: string): boolean {
  const node = doc.components[id];
  return !!node && !!getComponentDefinition(node.type)?.acceptsChildren;
}

/** Reading-order test: is the element positioned after the pointer? Works for block, flex and grid. */
function isAfterPointer(rect: DOMRect, x: number, y: number): boolean {
  if (rect.top > y) return true;
  if (rect.bottom < y) return false;
  return rect.left + rect.width / 2 > x;
}

function childIndexAt(root: HTMLElement, doc: AppDefinition, parentId: string, x: number, y: number): number {
  const children = doc.components[parentId].children;
  for (let i = 0; i < children.length; i++) {
    const el = root.querySelector<HTMLElement>(`[data-node-id="${CSS.escape(children[i])}"]`);
    if (el && isAfterPointer(el.getBoundingClientRect(), x, y)) return i;
  }
  return children.length;
}

/**
 * Computes where a dragged item would land on the canvas for a pointer position.
 * Returns null if the position is not a valid drop target.
 */
export function canvasDropTarget(root: HTMLElement, doc: AppDefinition, x: number, y: number, drag: DragData): DropTarget | null {
  const stack = document.elementsFromPoint(x, y);
  let el: HTMLElement | null = null;
  for (const candidate of stack) {
    if (!root.contains(candidate)) continue;
    const hit = (candidate as HTMLElement).closest<HTMLElement>('[data-node-id]');
    if (!hit || !root.contains(hit)) continue;
    const id = hit.dataset.nodeId!;
    if (drag.kind === 'node' && isDescendant(doc, drag.id, id)) continue; // never into itself
    el = hit;
    break;
  }
  if (!el) return null;
  const id = el.dataset.nodeId!;
  const node = doc.components[id];
  if (!node) return null;

  if (acceptsChildren(doc, id)) {
    const rect = el.getBoundingClientRect();
    const parent = parentOf(doc, id);
    const nearEdge = parent && (y - rect.top < EDGE_PX || rect.bottom - y < EDGE_PX);
    if (!nearEdge || !parent) {
      return { parentId: id, index: childIndexAt(root, doc, id, x, y) };
    }
    const siblings = doc.components[parent].children;
    const i = siblings.indexOf(id);
    return { parentId: parent, index: y - rect.top < EDGE_PX ? i : i + 1 };
  }
  const parent = parentOf(doc, id);
  if (!parent) return null;
  const i = doc.components[parent].children.indexOf(id);
  return { parentId: parent, index: isAfterPointer(el.getBoundingClientRect(), x, y) ? i : i + 1 };
}

/** Layers tree: top/bottom third of a row = before/after, middle = inside (containers). */
export function layerDropTarget(doc: AppDefinition, overId: string, rowRect: { top: number; height: number }, y: number, drag: DragData): DropTarget | null {
  if (drag.kind === 'node' && isDescendant(doc, drag.id, overId)) return null;
  const node = doc.components[overId];
  if (!node) return null;
  const rel = (y - rowRect.top) / rowRect.height;
  const parent = parentOf(doc, overId);
  if (acceptsChildren(doc, overId) && (rel > 0.3 && rel < 0.7 || !parent)) {
    return { parentId: overId, index: node.children.length };
  }
  if (!parent) return null;
  const i = doc.components[parent].children.indexOf(overId);
  return { parentId: parent, index: rel <= 0.5 ? i : i + 1 };
}

/** Converts a drop target into the index expected by the component.move command. */
export function moveIndex(doc: AppDefinition, id: string, target: DropTarget): number | null {
  const from = parentOf(doc, id);
  if (!from) return null;
  const oldIndex = doc.components[from].children.indexOf(id);
  let index = target.index;
  if (from === target.parentId && oldIndex < index) index -= 1;
  if (from === target.parentId && oldIndex === index) return null; // no-op
  return index;
}
