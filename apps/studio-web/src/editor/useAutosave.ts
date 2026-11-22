import { useEffect, useRef } from 'react';
import { api, ApiError, type Envelope } from '../lib/api.ts';
import { useEditor } from './store.ts';

const DEBOUNCE_MS = 800;
const MAX_BACKOFF_MS = 30_000;

function newKey(): string {
  const bytes = new Uint8Array(12);
  crypto.getRandomValues(bytes);
  return `save-${Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Sends pending commands in batches against the last acknowledged revision.
 * - Network/5xx errors: retried with exponential backoff using the SAME Idempotency-Key,
 *   so a request that reached the server is never applied twice.
 * - 409: stops and surfaces a conflict (user chooses merge or reload).
 * - 422/403: stops with an actionable error.
 */
export function useAutosave() {
  const pendingCount = useEditor((s) => s.pending.length);
  const inflight = useEditor((s) => s.inflight);
  const saveState = useEditor((s) => s.saveState);
  const busy = useRef(false);
  const key = useRef<string | null>(null);
  const attempt = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const flush = async () => {
    if (busy.current) return;
    const state = useEditor.getState();
    const batch = state.beginSave();
    if (!batch) return;
    busy.current = true;
    key.current ??= newKey();
    try {
      const res = await api<Envelope<{ revision: number }>>(`/projects/${state.projectId}/commands`, {
        method: 'POST',
        body: { baseRevision: useEditor.getState().serverRevision, commands: batch },
        headers: { 'Idempotency-Key': key.current },
      });
      key.current = null;
      attempt.current = 0;
      useEditor.getState().saveSucceeded(res.data.revision);
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'unknown', String(e));
      if (err.status === 409) {
        key.current = null; // the next attempt (after merge/reload) is a different request
        useEditor.getState().saveFailed('conflict', err.message);
      } else if (err.status === 403) {
        key.current = null;
        useEditor.getState().saveFailed('readonly', err.message);
      } else if (err.status === 422 || err.status === 404 || err.status === 401) {
        key.current = null;
        const issues = (err.details.issues as Array<{ message: string }> | undefined)?.map((i) => i.message).join(', ');
        useEditor.getState().saveFailed('error', issues ? `${err.message} (${issues})` : err.message);
      } else {
        attempt.current += 1;
        const delay = Math.min(MAX_BACKOFF_MS, 1000 * 2 ** attempt.current);
        useEditor.getState().saveFailed('retrying', `บันทึกไม่สำเร็จ จะลองใหม่ใน ${Math.round(delay / 1000)} วินาที`);
        setTimeout(() => {
          busy.current = false;
          void flush();
        }, delay);
        return;
      }
    } finally {
      if (useEditor.getState().saveState !== 'retrying') busy.current = false;
    }
    // More edits may have queued while saving.
    if (useEditor.getState().pending.length > 0) schedule();
  };

  const schedule = () => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => void flush(), DEBOUNCE_MS);
  };

  useEffect(() => {
    if (pendingCount > 0 && !inflight && saveState === 'pending') schedule();
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingCount, inflight, saveState]);

  useEffect(() => {
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      const s = useEditor.getState();
      if (s.pending.length > 0 || s.inflight) {
        e.preventDefault();
      }
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, []);

  return { flushNow: () => void flush() };
}
