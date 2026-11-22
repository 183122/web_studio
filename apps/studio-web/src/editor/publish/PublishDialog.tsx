import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, CircleAlert, CircleDashed, Copy, ExternalLink, Globe, Link2, RotateCcw, Trash2 } from 'lucide-react';
import { Dialog } from '../../components/ui/Dialog.tsx';
import { Button } from '../../components/ui/Button.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { formatDate, Spinner } from '../../components/ui/misc.tsx';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import type { Deployment, PreviewLinkRow, PublishingOverview, SiteDomain } from './types.ts';

const STEP_LABEL: Record<string, string> = {
  freeze: 'ตรึงเวอร์ชัน', validate: 'ตรวจสอบโครงสร้าง', security_scan: 'ตรวจความปลอดภัย', manifest: 'สร้าง manifest',
  stage: 'เตรียม release', smoke_test: 'ทดสอบการแสดงผล', switch: 'สลับเว็บไซต์จริง', health_check: 'ตรวจสุขภาพหลังเผยแพร่', rollback: 'คืนค่า',
};
const STATUS_TH: Record<string, string> = { queued: 'รอคิว', running: 'กำลังเผยแพร่', succeeded: 'สำเร็จ', failed: 'ไม่สำเร็จ' };
const DOMAIN_TH: Record<string, string> = { active: 'ใช้งานได้', verified: 'ยืนยันแล้ว · รอใบรับรอง HTTPS', pending_verification: 'รอยืนยัน DNS' };

function copy(text: string) {
  navigator.clipboard?.writeText(text).then(() => toast.success('คัดลอกแล้ว'), () => toast.error('คัดลอกไม่สำเร็จ'));
}

/** Publish & domains (M26–M28). `unsaved` = the editor has local changes not yet saved. */
export function PublishDialog({ projectId, open, onOpenChange, unsaved }: { projectId: string; open: boolean; onOpenChange: (o: boolean) => void; unsaved: boolean }) {
  const qc = useQueryClient();
  const key = ['publishing', projectId];
  const overview = useQuery({
    queryKey: key,
    queryFn: async () => (await api<Envelope<PublishingOverview>>(`/projects/${projectId}/publishing`)).data,
    enabled: open,
    // Poll while a deployment is in flight.
    refetchInterval: (q) => (q.state.data?.deployments.some((d) => d.status === 'queued' || d.status === 'running') ? 1500 : false),
  });
  const refresh = () => qc.invalidateQueries({ queryKey: key });
  const run = <T,>(fn: () => Promise<T>, ok?: string) => async () => {
    try {
      await fn();
      if (ok) toast.success(ok);
    } catch (e) {
      toast.error(errorMessage(e));
    } finally {
      refresh();
    }
  };

  const publish = useMutation({ mutationFn: run(() => api(`/projects/${projectId}/publish`, { method: 'POST' })) });
  const data = overview.data;
  const latest = data?.deployments[0];
  const inFlight = latest && (latest.status === 'queued' || latest.status === 'running');

  return (
    <Dialog open={open} onOpenChange={onOpenChange} title="เผยแพร่เว็บไซต์" description="เผยแพร่เวอร์ชันที่บันทึกแล้วสู่สาธารณะ จัดการโดเมน และลิงก์แชร์" wide>
      {overview.isLoading ? <Spinner /> : overview.error ? <p role="alert" className="text-red-600">{errorMessage(overview.error)}</p> : data ? (
        <div className="space-y-6 text-sm">
          <SubdomainSection projectId={projectId} data={data} onDone={refresh} />

          <section aria-labelledby="pub-h" className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
            <div className="flex flex-wrap items-center gap-3">
              <h3 id="pub-h" className="font-semibold">สถานะ</h3>
              {data.live ? (
                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">ออนไลน์ · v{data.live.version} (rev {data.live.sourceRevision})</span>
              ) : <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">ยังไม่เผยแพร่</span>}
              {data.live && data.hasUnpublishedChanges ? <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">มีการแก้ไขที่ยังไม่เผยแพร่</span> : null}
              <div className="ml-auto flex gap-2">
                {data.live && data.subdomain ? <a className="inline-flex h-10 items-center gap-1.5 rounded-lg px-3 text-brand-700 underline" href={data.subdomain.url} target="_blank" rel="noreferrer">เปิดเว็บไซต์ <ExternalLink className="h-4 w-4" /></a> : null}
                <Button onClick={() => publish.mutate()} loading={publish.isPending || !!inFlight} disabled={!data.subdomain || unsaved}>
                  {data.live ? 'เผยแพร่การเปลี่ยนแปลง' : 'เผยแพร่'}
                </Button>
              </div>
            </div>
            {unsaved ? <p className="mt-2 text-amber-700">กำลังบันทึกการแก้ไข — เผยแพร่ได้เมื่อบันทึกเสร็จ</p> : null}
            {!data.subdomain ? <p className="mt-2 text-slate-500">ตั้งชื่อโดเมนย่อยก่อนเผยแพร่</p> : null}
            {data.requiresVerifiedEmail ? <p className="mt-1 text-xs text-slate-500">ต้องยืนยันอีเมลก่อนเผยแพร่ครั้งแรก</p> : null}
            {latest ? <DeploymentLog d={latest} /> : null}
          </section>

          <ReleasesSection projectId={projectId} data={data} onDone={refresh} run={run} />
          <DomainsSection projectId={projectId} domains={data.domains} onDone={refresh} />
          <ShareLinks projectId={projectId} />
        </div>
      ) : null}
    </Dialog>
  );
}

function DeploymentLog({ d }: { d: Deployment }) {
  return (
    <div className="mt-4" aria-live="polite">
      <p className="mb-2 font-medium">
        การ{d.action === 'publish' ? 'เผยแพร่' : d.action === 'rollback' ? 'ย้อนกลับ' : 'หยุดเผยแพร่'}ล่าสุด: <span data-testid="deploy-status">{STATUS_TH[d.status]}</span>
        <span className="ml-2 text-xs text-slate-500">{formatDate(d.createdAt)}</span>
      </p>
      <ol className="space-y-1">
        {d.steps.map((s, i) => (
          <li key={i} className="flex items-start gap-2">
            {s.status === 'ok' ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-label="ผ่าน" /> : s.status === 'warning' ? <CircleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-label="คำเตือน" /> : <CircleAlert className="mt-0.5 h-4 w-4 shrink-0 text-red-600" aria-label="ล้มเหลว" />}
            <span><span className="font-medium">{STEP_LABEL[s.step] ?? s.step}</span> — {s.message}</span>
          </li>
        ))}
        {d.status === 'queued' || d.status === 'running' ? <li className="flex items-center gap-2 text-slate-500"><CircleDashed className="h-4 w-4 animate-spin" /> กำลังดำเนินการ…</li> : null}
      </ol>
      {d.error ? <p role="alert" className="mt-2 rounded-lg bg-red-50 p-2 text-red-800 dark:bg-red-950 dark:text-red-200">{d.error.message} — เว็บไซต์ที่ออนไลน์อยู่ไม่ได้รับผลกระทบ</p> : null}
    </div>
  );
}

function SubdomainSection({ projectId, data, onDone }: { projectId: string; data: PublishingOverview; onDone: () => void }) {
  const current = data.subdomain?.hostname.replace(`.${data.baseDomain}`, '') ?? '';
  const [slug, setSlug] = useState(current);
  const [error, setError] = useState('');
  const save = useMutation({
    mutationFn: () => api(`/projects/${projectId}/subdomain`, { method: 'PUT', body: { slug } }),
    onSuccess: () => { setError(''); toast.success('บันทึกโดเมนย่อยแล้ว'); onDone(); },
    onError: (e) => setError(errorMessage(e)),
  });
  return (
    <section aria-labelledby="sub-h">
      <h3 id="sub-h" className="mb-2 font-semibold">โดเมนย่อย</h3>
      <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
        <label htmlFor="slug" className="sr-only">ชื่อโดเมนย่อย</label>
        <div className="flex items-center rounded-lg border border-slate-300 dark:border-slate-600">
          <span className="pl-3 text-slate-500">https://</span>
          <input id="slug" className="w-44 bg-transparent px-1 py-2 outline-none" value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase())} placeholder="my-shop" aria-invalid={!!error} aria-describedby={error ? 'slug-err' : undefined} />
          <span className="pr-3 text-slate-500">.{data.baseDomain}</span>
        </div>
        <Button type="submit" variant="secondary" loading={save.isPending} disabled={!slug || slug === current}>{current ? 'เปลี่ยนชื่อ' : 'ใช้ชื่อนี้'}</Button>
      </form>
      {error ? <p id="slug-err" role="alert" className="mt-1 text-red-600">{error}</p> : null}
      {current && slug !== current ? <p className="mt-1 text-xs text-amber-700">ชื่อเดิมจะถูกพักไว้ 30 วันก่อนให้ผู้อื่นใช้ได้ ลิงก์เดิมจะใช้ไม่ได้ทันที</p> : null}
    </section>
  );
}

function ReleasesSection({ projectId, data, onDone, run }: { projectId: string; data: PublishingOverview; onDone: () => void; run: <T>(fn: () => Promise<T>, ok?: string) => () => Promise<void> }) {
  const [confirmUnpublish, setConfirmUnpublish] = useState(false);
  if (data.releases.length === 0) return null;
  return (
    <section aria-labelledby="rel-h">
      <h3 id="rel-h" className="mb-2 font-semibold">ประวัติ release</h3>
      <ul className="divide-y divide-slate-200 rounded-xl border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
        {data.releases.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
            <span className="font-medium">v{r.version}</span>
            <span className="text-slate-500">rev {r.sourceRevision} · {formatDate(r.createdAt)}</span>
            {r.isLive ? <span className="rounded-full bg-emerald-100 px-2 text-xs text-emerald-800">ออนไลน์</span> : null}
            {r.status === 'failed' ? <span className="rounded-full bg-red-100 px-2 text-xs text-red-800">ล้มเหลว</span> : null}
            {r.warnings.length > 0 ? <span className="text-xs text-amber-700" title={r.warnings.join('\n')}>{r.warnings.length} คำเตือน</span> : null}
            {r.status === 'ready' && !r.isLive ? (
              <Button size="sm" variant="ghost" className="ml-auto" onClick={run(() => api(`/projects/${projectId}/rollback`, { method: 'POST', body: { releaseId: r.id } }), `ย้อนกลับเป็น v${r.version} แล้ว`)}>
                <RotateCcw className="h-4 w-4" /> ใช้เวอร์ชันนี้
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      {data.live ? (
        <div className="mt-2">
          {confirmUnpublish ? (
            <div className="flex flex-wrap items-center gap-2 rounded-lg bg-red-50 p-2 dark:bg-red-950">
              <span>หยุดเผยแพร่? ผู้เยี่ยมชมจะเห็นหน้า "ไม่พบเว็บไซต์" (ข้อมูลไม่ถูกลบ)</span>
              <Button size="sm" variant="danger" onClick={async () => { await run(() => api(`/projects/${projectId}/unpublish`, { method: 'POST' }), 'หยุดเผยแพร่แล้ว')(); setConfirmUnpublish(false); onDone(); }}>ยืนยัน</Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirmUnpublish(false)}>ยกเลิก</Button>
            </div>
          ) : <Button size="sm" variant="ghost" className="text-red-700" onClick={() => setConfirmUnpublish(true)}>หยุดเผยแพร่</Button>}
        </div>
      ) : null}
    </section>
  );
}

function DomainsSection({ projectId, domains, onDone }: { projectId: string; domains: SiteDomain[]; onDone: () => void }) {
  const [host, setHost] = useState('');
  const [error, setError] = useState('');
  const add = useMutation({
    mutationFn: () => api(`/projects/${projectId}/domains`, { method: 'POST', body: { hostname: host } }),
    onSuccess: () => { setHost(''); setError(''); onDone(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const act = (fn: () => Promise<unknown>) => fn().catch((e) => toast.error(errorMessage(e))).finally(onDone);
  return (
    <section aria-labelledby="dom-h">
      <h3 id="dom-h" className="mb-2 flex items-center gap-1.5 font-semibold"><Globe className="h-4 w-4" /> โดเมนของคุณเอง</h3>
      <form className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
        <label htmlFor="custom-host" className="sr-only">โดเมน</label>
        <input id="custom-host" className="field-input w-64" placeholder="www.example.com" value={host} onChange={(e) => setHost(e.target.value)} aria-invalid={!!error} />
        <Button type="submit" variant="secondary" loading={add.isPending} disabled={!host}>เพิ่มโดเมน</Button>
      </form>
      {error ? <p role="alert" className="mt-1 text-red-600">{error}</p> : null}
      <ul className="mt-3 space-y-3">
        {domains.map((d) => (
          <li key={d.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{d.hostname}</span>
              <span data-testid={`domain-status-${d.hostname}`} className={`rounded-full px-2 text-xs ${d.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>{DOMAIN_TH[d.status] ?? d.status}</span>
              <div className="ml-auto flex gap-1">
                <Button size="sm" variant="secondary" onClick={() => act(() => api(`/domains/${d.id}/verify`, { method: 'POST' }))}>ตรวจสอบอีกครั้ง</Button>
                <Button size="sm" variant="ghost" aria-label={`นำ ${d.hostname} ออก`} onClick={() => act(() => api(`/domains/${d.id}`, { method: 'DELETE' }))}><Trash2 className="h-4 w-4" /></Button>
              </div>
            </div>
            {d.checks ? (
              <ul className="mt-2 space-y-0.5 text-xs">
                {Object.entries(d.checks).map(([k, c]) => <li key={k} className={c.ok ? 'text-emerald-700' : 'text-slate-600 dark:text-slate-300'}>{c.ok ? '✓' : '•'} {c.message}</li>)}
              </ul>
            ) : null}
            {d.status !== 'active' && d.instructions ? (
              <div className="mt-2 overflow-x-auto">
                <p className="mb-1 text-xs text-slate-600 dark:text-slate-300">เพิ่มระเบียนเหล่านี้ที่ผู้ให้บริการ DNS ของคุณ ({d.instructions.note})</p>
                <table className="w-full text-left text-xs">
                  <thead><tr className="text-slate-500"><th className="py-1 pr-2">ประเภท</th><th className="pr-2">ชื่อ</th><th className="pr-2">ค่า</th><th>สำหรับ</th></tr></thead>
                  <tbody>
                    {d.instructions.records.map((r) => (
                      <tr key={r.type + r.name} className="border-t border-slate-100 dark:border-slate-800">
                        <td className="py-1 pr-2 font-mono">{r.type}</td>
                        <td className="pr-2 font-mono">{r.name}</td>
                        <td className="pr-2 font-mono"><button type="button" className="inline-flex items-center gap-1 hover:underline" onClick={() => copy(r.value)} aria-label={`คัดลอก ${r.value}`}>{r.value} <Copy className="h-3 w-3" /></button></td>
                        <td>{r.purpose}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
          </li>
        ))}
      </ul>
    </section>
  );
}

function ShareLinks({ projectId }: { projectId: string }) {
  const qc = useQueryClient();
  const key = ['preview-links', projectId];
  const links = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<PreviewLinkRow[]>>(`/projects/${projectId}/preview-links`)).data });
  const [days, setDays] = useState(7);
  const [created, setCreated] = useState<string | null>(null);
  const create = useMutation({
    // Built from the current studio origin so the link matches wherever the studio is served.
    mutationFn: async () => window.location.origin + (await api<Envelope<{ path: string }>>(`/projects/${projectId}/preview-links`, { method: 'POST', body: { days } })).data.path,
    onSuccess: (url) => { setCreated(url); qc.invalidateQueries({ queryKey: key }); },
    onError: (e) => toast.error(errorMessage(e)),
  });
  const revoke = (id: string) => api(`/projects/${projectId}/preview-links/${id}`, { method: 'DELETE' }).catch((e) => toast.error(errorMessage(e))).finally(() => qc.invalidateQueries({ queryKey: key }));
  const active = (links.data ?? []).filter((l) => !l.revokedAt && new Date(l.expiresAt) > new Date());
  return (
    <section aria-labelledby="share-h">
      <h3 id="share-h" className="mb-2 flex items-center gap-1.5 font-semibold"><Link2 className="h-4 w-4" /> ลิงก์แชร์ฉบับร่าง</h3>
      <p className="mb-2 text-slate-500">ให้ผู้อื่นดูฉบับร่างปัจจุบันแบบอ่านอย่างเดียว ไม่ต้องเข้าสู่ระบบ (ไม่ถูกจัดทำดัชนีโดยเครื่องมือค้นหา)</p>
      <div className="flex flex-wrap items-center gap-2">
        <label className="flex items-center gap-1">หมดอายุใน
          <select className="field-input w-auto" value={days} onChange={(e) => setDays(Number(e.target.value))}>
            {[1, 3, 7, 14, 30].map((d) => <option key={d} value={d}>{d} วัน</option>)}
          </select>
        </label>
        <Button variant="secondary" onClick={() => create.mutate()} loading={create.isPending}>สร้างลิงก์</Button>
      </div>
      {created ? (
        <div className="mt-2 flex items-center gap-2 rounded-lg bg-slate-100 p-2 dark:bg-navy-800">
          <input readOnly aria-label="ลิงก์แชร์" className="flex-1 bg-transparent font-mono text-xs" value={created} onFocus={(e) => e.currentTarget.select()} />
          <Button size="sm" variant="ghost" onClick={() => copy(created)}><Copy className="h-4 w-4" /> คัดลอก</Button>
        </div>
      ) : null}
      {created ? <p className="mt-1 text-xs text-slate-500">ลิงก์นี้แสดงเพียงครั้งเดียว กรุณาคัดลอกเก็บไว้</p> : null}
      {active.length > 0 ? (
        <ul className="mt-2 space-y-1">
          {active.map((l) => (
            <li key={l.id} className="flex items-center gap-2 text-xs">
              <span>rev {l.revision} · หมดอายุ {formatDate(l.expiresAt)}</span>
              <Button size="sm" variant="ghost" className="text-red-700" onClick={() => revoke(l.id)}>ยกเลิกลิงก์</Button>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}
