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

/** The first workspace the user can access (workspace switcher arrives with M02 invitations). */
export function useCurrentWorkspace() {
  const me = useMe().data;
  const org = me?.organizations[0];
  const ws = org?.workspaces[0];
  return ws ? { ...ws, organization: org } : null;
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
