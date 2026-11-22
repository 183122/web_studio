import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import type { AppDefinition } from '@studio/app-schema';
import type { Breakpoint } from '@studio/component-registry';
import { PageRenderer, findPageByPath, type DataHost } from '@studio/renderer';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { ErrorState, FullPageSpinner, formatDate } from '../components/ui/misc.tsx';

interface SharedSnapshot { projectName: string; revision: number; expiresAt: string; document: AppDefinition; assetUrls: Record<string, string> }

/** Forms and tables are inert in shared previews: reviewers never write to (or read) app data. */
const inertHost: DataHost = {
  async submit() {
    return { ok: false, message: 'นี่คือลิงก์ดูตัวอย่าง — การส่งข้อมูลถูกปิดไว้' };
  },
  async list() {
    throw new Error('ข้อมูลจริงไม่แสดงในลิงก์ดูตัวอย่าง');
  },
};

function useBreakpoint(): Breakpoint {
  const get = (): Breakpoint => (window.innerWidth <= 600 ? 'mobile' : window.innerWidth <= 1024 ? 'tablet' : 'base');
  const [bp, setBp] = useState(get);
  useEffect(() => {
    const on = () => setBp(get());
    window.addEventListener('resize', on);
    return () => window.removeEventListener('resize', on);
  }, []);
  return bp;
}

/** Public, read-only snapshot shared with reviewers via an expiring link (M26). */
export default function SharePreview() {
  const { token = '' } = useParams();
  const [path, setPath] = useState('/');
  const bp = useBreakpoint();
  const snap = useQuery({
    queryKey: ['share', token],
    queryFn: async () => (await api<Envelope<SharedSnapshot>>(`/preview-links/${encodeURIComponent(token)}`)).data,
    retry: false,
  });
  const doc = snap.data?.document;
  const pageId = useMemo(() => (doc ? findPageByPath(doc, path) : null), [doc, path]);

  useEffect(() => {
    const meta = document.createElement('meta');
    meta.name = 'robots';
    meta.content = 'noindex';
    document.head.appendChild(meta);
    return () => meta.remove();
  }, []);

  if (snap.error) return <div className="p-8"><ErrorState message={errorMessage(snap.error)} /></div>;
  if (!snap.data || !doc) return <FullPageSpinner />;

  return (
    <div className="min-h-full bg-white">
      <div className="sticky top-0 z-50 flex flex-wrap items-center gap-2 bg-navy-900 px-3 py-1.5 text-xs text-white">
        <span className="font-medium">ตัวอย่าง: {snap.data.projectName}</span>
        <span className="opacity-70">rev {snap.data.revision} · ลิงก์หมดอายุ {formatDate(snap.data.expiresAt)}</span>
        {doc.pages.length > 1 ? (
          <label className="ml-auto flex items-center gap-1">หน้า
            <select className="rounded bg-navy-800 px-1 py-0.5" value={doc.pages.find((p) => p.id === pageId)?.path ?? path} onChange={(e) => setPath(e.target.value)}>
              {doc.pages.map((p) => <option key={p.id} value={p.path}>{p.name}</option>)}
            </select>
          </label>
        ) : null}
      </div>
      <div lang={doc.seo.lang}>
        {pageId ? (
          <PageRenderer
            doc={doc}
            pageId={pageId}
            breakpoint={bp}
            mode="preview"
            resolveAsset={(ref) => snap.data.assetUrls[ref]}
            dataHost={inertHost}
            onNavigate={(href) => {
              if (href.startsWith('#')) {
                document.getElementById(href.slice(1))?.scrollIntoView({ behavior: 'smooth' });
                return;
              }
              setPath(href.split('#')[0] || '/');
              window.scrollTo(0, 0);
            }}
          />
        ) : <p className="p-10 text-center text-slate-500">ไม่พบหน้า</p>}
      </div>
    </div>
  );
}
