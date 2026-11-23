import { create } from 'zustand';
import {
  applyCommands,
  CommandError,
  validateDefinition,
  type AppCommand,
  type AppDefinition,
  type ComponentNode,
  type ValidationIssue,
} from '@studio/app-schema';
import type { Breakpoint } from '@studio/component-registry';

export type SaveState = 'saved' | 'pending' | 'saving' | 'retrying' | 'conflict' | 'error' | 'readonly';
export type PanelId = 'ai' | 'pages' | 'components' | 'layers' | 'assets' | 'theme' | 'database' | 'workflows' | 'versions';

interface HistoryEntry {
  label: string;
  forward: AppCommand[];
  inverse: AppCommand[];
  coalesceKey?: string;
  at: number;
}

export interface DispatchOptions {
  label?: string;
  /** Consecutive dispatches with the same key within 1.5s merge into one undo step (typing). */
  coalesceKey?: string;
  /** Do not record in history (used by undo/redo themselves). */
  skipHistory?: boolean;
}

export interface EditorState {
  projectId: string;
  workspaceId: string;
  canEdit: boolean;
  doc: AppDefinition | null;
  serverRevision: number;
  pending: AppCommand[];
  inflight: AppCommand[] | null;
  saveState: SaveState;
  saveMessage: string | null;
  lastSavedAt: number | null;
  pageId: string;
  selectedId: string | null;
  hoveredId: string | null;
  breakpoint: Breakpoint;
  zoom: number;
  panel: PanelId;
  past: HistoryEntry[];
  future: HistoryEntry[];
  clipboard: ComponentNode[] | null;
  issues: ValidationIssue[];

  load: (args: { projectId: string; workspaceId: string; canEdit: boolean; doc: AppDefinition; revision: number }) => void;
  dispatch: (cmds: AppCommand[], opts?: DispatchOptions) => boolean;
  undo: () => void;
  redo: () => void;
  select: (id: string | null) => void;
  hover: (id: string | null) => void;
  setPage: (id: string) => void;
  setBreakpoint: (bp: Breakpoint) => void;
  setZoom: (z: number) => void;
  setPanel: (p: PanelId) => void;
  setClipboard: (nodes: ComponentNode[] | null) => void;
  clearIssues: () => void;
  // save lifecycle (driven by useAutosave)
  beginSave: () => AppCommand[] | null;
  saveSucceeded: (revision: number) => void;
  saveFailed: (state: 'retrying' | 'conflict' | 'error' | 'readonly', message: string) => void;
  /** Replace local state with a fresh server head, optionally re-applying unsaved commands on top. */
  rebase: (doc: AppDefinition, revision: number, reapply: boolean) => { ok: boolean; message?: string };
  /** Live collaboration: take a newer server version when there are no local edits; keeps page/selection. */
  applyRemote: (doc: AppDefinition, revision: number) => boolean;
}

const HISTORY_LIMIT = 200;
const COALESCE_MS = 1500;

export const useEditor = create<EditorState>((set, get) => ({
  projectId: '',
  workspaceId: '',
  canEdit: false,
  doc: null,
  serverRevision: 0,
  pending: [],
  inflight: null,
  saveState: 'saved',
  saveMessage: null,
  lastSavedAt: null,
  pageId: '',
  selectedId: null,
  hoveredId: null,
  breakpoint: 'base',
  zoom: 1,
  panel: 'components',
  past: [],
  future: [],
  clipboard: null,
  issues: [],

  load: ({ projectId, workspaceId, canEdit, doc, revision }) =>
    set({
      projectId,
      workspaceId,
      canEdit,
      doc,
      serverRevision: revision,
      pending: [],
      inflight: null,
      saveState: canEdit ? 'saved' : 'readonly',
      saveMessage: canEdit ? null : 'คุณมีสิทธิ์อ่านอย่างเดียว',
      pageId: doc.settings.homePageId,
      selectedId: null,
      hoveredId: null,
      past: [],
      future: [],
      issues: [],
    }),

  dispatch: (cmds, opts = {}) => {
    const { doc, canEdit, saveState } = get();
    if (!doc || cmds.length === 0) return false;
    if (!canEdit || saveState === 'readonly') return false;
    let result;
    try {
      result = applyCommands(doc, cmds);
    } catch (e) {
      const message = e instanceof CommandError ? e.message : String(e);
      set({ issues: [{ path: '/', code: e instanceof CommandError ? e.code : 'error', message }] });
      return false;
    }
    const issues = validateDefinition(result.doc);
    if (issues.length > 0) {
      set({ issues });
      return false;
    }
    const now = Date.now();
    let past = get().past;
    if (!opts.skipHistory) {
      const last = past[past.length - 1];
      if (opts.coalesceKey && last && last.coalesceKey === opts.coalesceKey && now - last.at < COALESCE_MS) {
        past = [...past.slice(0, -1), { ...last, forward: [...last.forward, ...cmds], inverse: [...result.inverse, ...last.inverse], at: now }];
      } else {
        past = [...past, { label: opts.label ?? cmds[0].type, forward: cmds, inverse: result.inverse, coalesceKey: opts.coalesceKey, at: now }].slice(-HISTORY_LIMIT);
      }
    }
    const selectedId = get().selectedId && result.doc.components[get().selectedId!] ? get().selectedId : null;
    const pageId = result.doc.pages.some((p) => p.id === get().pageId) ? get().pageId : result.doc.settings.homePageId;
    set({
      doc: result.doc,
      past,
      future: opts.skipHistory ? get().future : [],
      pending: [...get().pending, ...cmds],
      saveState: (['saving', 'retrying', 'conflict', 'error'] as SaveState[]).includes(get().saveState) ? get().saveState : 'pending',
      issues: [],
      selectedId,
      pageId,
    });
    return true;
  },

  undo: () => {
    const past = get().past;
    const entry = past[past.length - 1];
    if (!entry) return;
    set({ past: past.slice(0, -1) });
    if (get().dispatch(entry.inverse, { skipHistory: true })) {
      set({ future: [...get().future, { ...entry, coalesceKey: undefined }] });
    } else {
      set({ past }); // could not undo (e.g. conflicting state); keep history intact
    }
  },

  redo: () => {
    const future = get().future;
    const entry = future[future.length - 1];
    if (!entry) return;
    set({ future: future.slice(0, -1) });
    if (get().dispatch(entry.forward, { skipHistory: true })) {
      set({ past: [...get().past, { ...entry, coalesceKey: undefined, at: 0 }] });
    } else {
      set({ future });
    }
  },

  select: (id) => set({ selectedId: id }),
  hover: (id) => set({ hoveredId: id }),
  setPage: (id) => set({ pageId: id, selectedId: null }),
  setBreakpoint: (breakpoint) => set({ breakpoint }),
  setZoom: (zoom) => set({ zoom: Math.min(2, Math.max(0.25, Math.round(zoom * 100) / 100)) }),
  setPanel: (panel) => set({ panel }),
  setClipboard: (clipboard) => set({ clipboard }),
  clearIssues: () => set({ issues: [] }),

  beginSave: () => {
    const { pending, inflight, saveState } = get();
    if (inflight) return inflight; // retry the same batch
    if (pending.length === 0 || saveState === 'conflict' || saveState === 'readonly' || saveState === 'error') return null;
    set({ inflight: pending, pending: [], saveState: 'saving' });
    return pending;
  },

  saveSucceeded: (revision) =>
    set({
      serverRevision: revision,
      inflight: null,
      saveState: get().pending.length > 0 ? 'pending' : 'saved',
      saveMessage: null,
      lastSavedAt: Date.now(),
    }),

  saveFailed: (state, message) => set({ saveState: state, saveMessage: message }),

  applyRemote: (doc, revision) => {
    const s = get();
    if (revision <= s.serverRevision || s.pending.length > 0 || s.inflight) return false;
    set({
      doc,
      serverRevision: revision,
      pageId: doc.pages.some((p) => p.id === s.pageId) ? s.pageId : doc.settings.homePageId,
      selectedId: s.selectedId && doc.components[s.selectedId] ? s.selectedId : null,
      hoveredId: null,
      // Local undo history refers to an older document; keep it only while it still applies.
      past: [],
      future: [],
    });
    return true;
  },

  rebase: (doc, revision, reapply) => {
    const unsaved = [...(get().inflight ?? []), ...get().pending];
    if (reapply && unsaved.length > 0) {
      try {
        const r = applyCommands(doc, unsaved);
        const issues = validateDefinition(r.doc);
        if (issues.length > 0) return { ok: false, message: 'การแก้ไขของคุณขัดแย้งกับเวอร์ชันล่าสุด' };
        set({ doc: r.doc, serverRevision: revision, inflight: null, pending: unsaved, saveState: 'pending', saveMessage: null, past: [], future: [] });
        return { ok: true };
      } catch (e) {
        return { ok: false, message: e instanceof Error ? e.message : 'รวมการแก้ไขไม่สำเร็จ' };
      }
    }
    const pageId = doc.pages.some((p) => p.id === get().pageId) ? get().pageId : doc.settings.homePageId;
    set({
      doc,
      serverRevision: revision,
      inflight: null,
      pending: [],
      saveState: get().canEdit ? 'saved' : 'readonly',
      saveMessage: null,
      past: [],
      future: [],
      pageId,
      selectedId: null,
    });
    return { ok: true };
  },
}));

/** Parent id of a component (or null for page roots / unknown). */
export function parentOf(doc: AppDefinition, id: string): string | null {
  for (const node of Object.values(doc.components)) if (node.children.includes(id)) return node.id;
  return null;
}

export function pathTo(doc: AppDefinition, id: string): string[] {
  const out: string[] = [];
  let cur: string | null = id;
  while (cur) {
    out.unshift(cur);
    cur = parentOf(doc, cur);
  }
  return out;
}

export function isDescendant(doc: AppDefinition, ancestor: string, id: string): boolean {
  let cur: string | null = id;
  while (cur) {
    if (cur === ancestor) return true;
    cur = parentOf(doc, cur);
  }
  return false;
}
