import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from './api.ts';
import type { Asset, Project, Revision, Template, WorkspaceSummary } from './types.ts';
import type { AppDefinition } from '@studio/app-schema';

export interface ProjectFilters {
  search: string;
  status: 'active' | 'archived' | 'trashed';
  favorites: boolean;
  sort: 'updated' | 'name' | 'created';
}

export const keys = {
  workspace: (id: string) => ['workspace', id] as const,
  projects: (ws: string, f?: ProjectFilters) => (f ? (['projects', ws, f] as const) : (['projects', ws] as const)),
  project: (id: string) => ['project', id] as const,
  schema: (id: string) => ['schema', id] as const,
  revisions: (id: string) => ['revisions', id] as const,
  assets: (ws: string) => ['assets', ws] as const,
  templates: ['templates'] as const,
  template: (slug: string) => ['template', slug] as const,
};

export function useWorkspace(id: string | undefined) {
  return useQuery({
    queryKey: keys.workspace(id ?? ''),
    queryFn: async () => (await api<Envelope<WorkspaceSummary>>(`/workspaces/${id}`)).data,
    enabled: !!id,
  });
}

export function useProjects(ws: string | undefined, f: ProjectFilters) {
  return useQuery({
    queryKey: keys.projects(ws ?? '', f),
    queryFn: async () => {
      const q = new URLSearchParams({ status: f.status, sort: f.sort, perPage: '60' });
      if (f.search) q.set('search', f.search);
      if (f.favorites) q.set('favorites', '1');
      return api<Paginated<Project>>(`/workspaces/${ws}/projects?${q}`);
    },
    enabled: !!ws,
    placeholderData: keepPreviousData,
  });
}

export function useProject(id: string) {
  return useQuery({ queryKey: keys.project(id), queryFn: async () => (await api<Envelope<Project>>(`/projects/${id}`)).data });
}

export interface SchemaHead {
  revision: number;
  document: AppDefinition;
  contentHash: string;
  updatedAt: string | null;
}

export function useSchema(id: string) {
  return useQuery({
    queryKey: keys.schema(id),
    queryFn: async () => (await api<Envelope<SchemaHead>>(`/projects/${id}/schema`)).data,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  });
}

export function useTemplates() {
  return useQuery({ queryKey: keys.templates, queryFn: async () => (await api<Envelope<Template[]>>('/templates')).data, staleTime: 5 * 60_000 });
}

export function useTemplate(slug: string | null) {
  return useQuery({
    queryKey: keys.template(slug ?? ''),
    queryFn: async () => (await api<Envelope<Template & { document: AppDefinition }>>(`/templates/${slug}`)).data,
    enabled: !!slug,
    staleTime: 5 * 60_000,
  });
}

export function useRevisions(projectId: string, enabled: boolean) {
  return useQuery({
    queryKey: keys.revisions(projectId),
    queryFn: async () => api<Paginated<Revision>>(`/projects/${projectId}/revisions`),
    enabled,
  });
}

export function useAssets(ws: string | undefined) {
  return useQuery({
    queryKey: keys.assets(ws ?? ''),
    queryFn: async () => api<Paginated<Asset>>(`/workspaces/${ws}/assets`),
    enabled: !!ws,
  });
}

/** Resolves asset:<id> references to short-lived signed URLs (refreshed before they expire). */
export function useAssetUrls(ws: string | undefined, ids: string[]) {
  const sorted = [...ids].sort();
  return useQuery({
    queryKey: ['asset-urls', ws, sorted],
    queryFn: async () => {
      if (sorted.length === 0) return {} as Record<string, string>;
      const q = new URLSearchParams();
      sorted.forEach((id) => q.append('ids[]', id));
      const res = await api<Envelope<Asset[]>>(`/workspaces/${ws}/assets?${q}`);
      const map: Record<string, string> = {};
      for (const a of res.data) if (a.url) map[`asset:${a.id}`] = a.url;
      return map;
    },
    enabled: !!ws,
    staleTime: 20 * 60_000,
    refetchInterval: 20 * 60_000,
    placeholderData: keepPreviousData,
  });
}

export function useProjectMutation<TVars>(fn: (vars: TVars) => Promise<unknown>, ws?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      if (ws) {
        qc.invalidateQueries({ queryKey: keys.projects(ws) });
        qc.invalidateQueries({ queryKey: keys.workspace(ws) });
      }
    },
  });
}
