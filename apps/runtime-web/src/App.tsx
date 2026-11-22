import { useEffect, useState, useSyncExternalStore } from 'react';
import type { Breakpoint } from '@studio/component-registry';
import { PageRenderer, findPageByPath } from '@studio/renderer';
import { AccountBar } from './AccountBar.tsx';
import { call, liveDataHost, resolveAsset, siteHasAccounts, type AppUser, type SiteBootstrap } from './site.ts';

const queries: [Breakpoint, string][] = [['mobile', '(max-width: 600px)'], ['tablet', '(max-width: 1024px)']];

function currentBreakpoint(): Breakpoint {
  for (const [bp, q] of queries) if (window.matchMedia(q).matches) return bp;
  return 'base';
}

function subscribe(cb: () => void) {
  const lists = queries.map(([, q]) => window.matchMedia(q));
  lists.forEach((l) => l.addEventListener('change', cb));
  return () => lists.forEach((l) => l.removeEventListener('change', cb));
}

export function App({ site }: { site: SiteBootstrap }) {
  const { document: doc, path } = site;
  const breakpoint = useSyncExternalStore(subscribe, currentBreakpoint, () => 'base' as Breakpoint);
  const accounts = siteHasAccounts(doc);
  const [user, setUser] = useState<AppUser | null | undefined>(accounts ? undefined : null);

  useEffect(() => {
    if (!accounts) return;
    call<{ data: AppUser | null }>('/auth/me').then((r) => setUser(r.data), () => setUser(null));
  }, [accounts]);

  const pageId = findPageByPath(doc, path);
  return (
    <>
      {accounts ? <AccountBar user={user} onChange={setUser} /> : null}
      {pageId ? (
        <PageRenderer
          // Re-mount data components when the signed-in user changes (permissions differ per role).
          key={user?.id ?? 'anon'}
          doc={doc}
          pageId={pageId}
          breakpoint={breakpoint}
          mode="live"
          resolveAsset={resolveAsset}
          dataHost={liveDataHost}
        />
      ) : (
        <main style={{ fontFamily: 'system-ui, sans-serif', padding: '4rem 1rem', textAlign: 'center' }}>
          <h1 style={{ fontSize: '3rem', margin: 0 }}>404</h1>
          <p>ไม่พบหน้านี้</p>
          <a href="/">กลับหน้าแรก</a>
        </main>
      )}
    </>
  );
}
