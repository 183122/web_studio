import { useEffect, useRef } from 'react';
import { create } from 'zustand';
import type { AppDefinition } from '@studio/app-schema';
import { api, type Envelope } from '../lib/api.ts';
import { toast } from '../components/ui/Toast.tsx';
import { useEditor } from './store.ts';

export interface Participant { userId: string; name: string; color: string; pageId: string | null; selectedId: string | null; canEdit: boolean; me: boolean }

export const useCollab = create<{ participants: Participant[]; set: (p: Participant[]) => void }>((set) => ({
  participants: [],
  set: (participants) => set({ participants }),
}));

const INTERVAL_MS = 3000;

/**
 * Live collaboration over short polling (no WebSocket server required):
 * - heartbeat publishes this user's page + selection and returns who else is here and the head revision;
 * - a newer head is pulled automatically when there are no local edits;
 * - a save conflict is merged automatically (re-applying local commands on the new head); the manual
 *   conflict banner remains only for edits that cannot be merged.
 */
export function useCollaboration(projectId: string) {
  const busy = useRef(false);
  useEffect(() => {
    if (!projectId) return;
    let stopped = false;
    const fetchHead = async () => (await api<Envelope<{ revision: number; document: AppDefinition }>>(`/projects/${projectId}/schema`)).data;
    const tick = async () => {
      if (busy.current || document.hidden) return;
      busy.current = true;
      try {
        const s = useEditor.getState();
        const res = await api<Envelope<{ headRevision: number; participants: Participant[] }>>(`/projects/${projectId}/presence`, { method: 'POST', body: { pageId: s.pageId || null, selectedId: s.selectedId } });
        if (stopped) return;
        useCollab.getState().set(res.data.participants);
        const now = useEditor.getState();
        const others = res.data.participants.filter((p) => !p.me).map((p) => p.name);
        if (now.saveState === 'conflict') {
          const head = await fetchHead();
          if (useEditor.getState().rebase(head.document, head.revision, true).ok) toast.info(`รวมการแก้ไขของคุณกับ${others.length ? ` ${others.join(', ')}` : 'เวอร์ชันล่าสุด'} แล้ว`);
        } else if (res.data.headRevision > now.serverRevision && now.pending.length === 0 && !now.inflight && ['saved', 'readonly'].includes(now.saveState)) {
          const head = await fetchHead();
          useEditor.getState().applyRemote(head.document, head.revision);
        }
      } catch {
        /* transient: next tick retries */
      } finally {
        busy.current = false;
      }
    };
    void tick();
    const id = setInterval(() => void tick(), INTERVAL_MS);
    return () => {
      stopped = true;
      clearInterval(id);
      useCollab.getState().set([]);
      void api(`/projects/${projectId}/presence`, { method: 'DELETE' }).catch(() => undefined);
    };
  }, [projectId]);
}
