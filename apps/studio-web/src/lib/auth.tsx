import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Navigate, Outlet, useLocation } from 'react-router';
import { api, ApiError, type Envelope } from './api.ts';
import type { Me } from './types.ts';
import { FullPageSpinner } from '../components/ui/misc.tsx';

export const ME_KEY = ['me'] as const;

export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async () => {
      try {
        return (await api<Envelope<Me>>('/auth/me')).data;
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null;
        throw e;
      }
    },
    staleTime: 60_000,
    retry: false,
  });
}

export function useAuthActions() {
  const qc = useQueryClient();
  return {
    refresh: () => qc.invalidateQueries({ queryKey: ME_KEY }),
    logout: async () => {
      await api('/auth/logout', { method: 'POST' });
      qc.clear();
      qc.setQueryData(ME_KEY, null);
    },
  };
}

const WS_KEY = 'studio-workspace-id';

/** All workspaces the user can access, across organizations. */
export function useWorkspaces() {
  const me = useMe().data;
  return (me?.organizations ?? []).flatMap((org) => org.workspaces.map((ws) => ({ ...ws, organization: org })));
}

/** The workspace chosen in the switcher (remembered per browser), else the first one. */
export function useCurrentWorkspace() {
  const all = useWorkspaces();
  let stored: string | null = null;
  try {
    stored = localStorage.getItem(WS_KEY);
  } catch {
    /* storage unavailable */
  }
  return all.find((w) => w.id === stored) ?? all[0] ?? null;
}

export function selectWorkspace(id: string) {
  try {
    localStorage.setItem(WS_KEY, id);
  } catch {
    /* storage unavailable */
  }
}

export function RequireAuth() {
  const { data, isLoading, error } = useMe();
  const location = useLocation();
  if (isLoading) return <FullPageSpinner />;
  if (error) return <div className="p-8 text-red-600">โหลดข้อมูลผู้ใช้ไม่สำเร็จ: {(error as Error).message}</div>;
  if (!data) return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />;
  return <Outlet />;
}

export function GuestOnly() {
  const { data, isLoading } = useMe();
  if (isLoading) return <FullPageSpinner />;
  if (data) return <Navigate to="/app" replace />;
  return <Outlet />;
}

/** Only allow same-site relative redirects after login (prevents open redirects). */
export function safeNext(next: string | null): string {
  if (!next || !next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) return '/app';
  return next;
}
