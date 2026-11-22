import { useMemo } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Monitor, Smartphone, Tablet } from 'lucide-react';
import { BREAKPOINT_WIDTHS, type Breakpoint } from '@studio/component-registry';
import type { AppDefinition } from '@studio/app-schema';
import { PageRenderer, findPageByPath } from '@studio/renderer';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { useAssetUrls, useProject, useSchema } from '../lib/queries.ts';
import { ErrorState, FullPageSpinner } from '../components/ui/misc.tsx';

/** Private, authenticated preview of the current draft (or a specific checkpoint via ?revision=). */
export default function Preview() {
  const { projectId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const revisionParam = params.get('revision');
  const project = useProject(projectId);
  const head = useSchema(projectId);
  const revision = useQuery({
    queryKey: ['revision-doc', projectId, revisionParam],
    queryFn: async () => (await api<Envelope<{ revision: number; document: AppDefinition }>>(`/projects/${projectId}/revisions/${revisionParam}`)).data,
    enabled: !!revisionParam,
  });
  const doc = revisionParam ? revision.data?.document : head.data?.document;
  const assets = useAssetUrls(project.data?.workspaceId, doc?.assets ?? []);
  const path = params.get('page') ?? '/';
  const bp = (params.get('bp') as Breakpoint) || 'base';
  const pageId = useMemo(() => (doc ? findPageByPath(doc, path) : null), [doc, path]);

  const set = (patch: Record<string, string>) => setParams({ ...Object.fromEntries(params), ...patch }, { replace: true });
  const error = project.error ?? (revisionParam ? revision.error : head.error);
  if (error) return <div className="p-8"><ErrorState message={errorMessage(error)} /></div>;
  if (!doc || !project.data) return <FullPageSpinner />;

  return (
    <div className="flex h-full flex-col bg-slate-200 dark:bg-navy-950">
      <header className="flex h-12 shrink-0 items-center gap-3 border-b border-slate-300 bg-white px-3 text-sm dark:border-slate-800 dark:bg-navy-900">
        <Link to={`/app/projects/${projectId}`} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 hover:bg-slate-100 dark:hover:bg-navy-800"><ArrowLeft className="h-4 w-4" /> ตัวแก้ไข</Link>
        <span className="truncate font-medium">{project.data.name}</span>
        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{revisionParam ? `ตัวอย่าง rev ${revisionParam}` : 'ตัวอย่างฉบับร่าง (ส่วนตัว)'}</span>
        <label className="ml-auto flex items-center gap-2">
          <span className="sr-only">เลือกหน้า</span>
          <select className="field-input h-8 w-auto py-0" value={doc.pages.find((p) => p.id === pageId)?.path ?? path} onChange={(e) => set({ page: e.target.value })}>
            {doc.pages.map((p) => <option key={p.id} value={p.path}>{p.name} ({p.path})</option>)}
          </select>
        </label>
        <div role="radiogroup" aria-label="ขนาดหน้าจอ" className="flex rounded-lg bg-slate-100 p-0.5 dark:bg-navy-800">
          {([['base', Monitor], ['tablet', Tablet], ['mobile', Smartphone]] as const).map(([id, Icon]) => (
            <button key={id} type="button" role="radio" aria-checked={bp === id} aria-label={id} onClick={() => set({ bp: id })} className={`rounded-md p-1.5 ${bp === id ? 'bg-white shadow-sm dark:bg-navy-900' : 'text-slate-500'}`}>
              <Icon className="h-4 w-4" />
            </button>
          ))}
        </div>
      </header>
      <main className="min-h-0 flex-1 overflow-auto">
        <div className="mx-auto bg-white shadow-xl transition-[width]" style={{ width: bp === 'base' ? '100%' : BREAKPOINT_WIDTHS[bp], maxWidth: '100%' }} lang={doc.seo.lang}>
          {pageId ? (
            <PageRenderer
              doc={doc}
              pageId={pageId}
              breakpoint={bp}
              mode="preview"
              resolveAsset={(ref) => assets.data?.[ref]}
              onNavigate={(href) => {
                if (href.startsWith('#')) {
                  document.getElementById(href.slice(1))?.scrollIntoView({ behavior: 'smooth' });
                  return;
                }
                const [p, hash] = href.split('#');
                set({ page: p || '/' });
                if (hash) setTimeout(() => document.getElementById(hash)?.scrollIntoView({ behavior: 'smooth' }), 50);
              }}
            />
          ) : (
            <div className="flex h-96 flex-col items-center justify-center gap-2 text-slate-500">
              <p className="text-4xl font-semibold">404</p>
              <p>ไม่พบหน้า {path}</p>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
