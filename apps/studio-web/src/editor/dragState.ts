import { create } from 'zustand';
import type { DragData, DropTarget } from './dnd.ts';

interface DragState {
  drag: DragData | null;
  target: DropTarget | null;
  set: (s: Partial<Pick<DragState, 'drag' | 'target'>>) => void;
}

export const useDragState = create<DragState>((set) => ({ drag: null, target: null, set: (s) => set(s) }));

/** Element registry so the DnD controller can reach the canvas DOM. */
export const canvasRef: { current: HTMLDivElement | null } = { current: null };
