import { useState } from 'react';
import { Link } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Building2, Flag, LayoutTemplate, Settings } from 'lucide-react';
import type { AppDefinition } from '@studio/app-schema';
import { PageRenderer } from '@studio/renderer';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { useMe } from '../lib/auth.tsx';
import { Button } from '../components/ui/Button.tsx';
import { Dialog } from '../components/ui/Dialog.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { Logo, Spinner, ThemeToggle, formatDate } from '../components/ui/misc.tsx';

interface OrgRow { id: string; name: string; status: string; suspendedReason: string | null; plan: string; owner: string; members: number; projects: number; createdAt: string }
interface OrgDetail extends OrgRow {
  usage: Record<string, { used: number; limit: number }>;
  subscription: { status: string; plan: string; currentPeriodEnd: string | null } | null;
  projectsList: Array<{ id: string; name: string; status: string }>;
  domains: string[];
  supportGrants: Array<{ id: string; status: string; reason: string; minutes: number; expiresAt: string | null; createdAt: string }>;
  recentAudit: Array<{ action: string; at: string }>;
}
interface AbuseRow { id: string; url: string; category: string; details: string; status: string; organizationId: string | null; organization: string | null; createdAt: string }

const CATEGORY: Record<string, string> = { phishing: 'ฟิชชิง', malware: 'มัลแวร์', spam: 'สแปม', illegal: 'ผิดกฎหมาย', other: 'อื่น ๆ' };

/** Platform operator console: organizations, support access requests, suspension, abuse reports. */
export default function AdminConsole() {
  const me = useMe().data;
  const [tab, setTab] = useState<'orgs' | 'abuse' | 'templates'>('orgs');
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const orgs = useQuery({ queryKey: ['admin-orgs', search], queryFn: async () => (await api<{ data: OrgRow[]; meta: { total: number } }>(`/admin/organizations?search=${encodeURIComponent(search)}`)), enabled: !!me?.user.isPlatformAdmin });
  const abuse = useQuery({ queryKey: ['admin-abuse'], queryFn: async () => (await api<Envelope<AbuseRow[]>>('/admin/abuse-reports')).data, enabled: !!me?.user.isPlatformAdmin && tab === 'abuse' });

  if (!me) return <Spinner />;
  if (!me.user.isPlatformAdmin) return <div className="p-10 text-center"><p className="text-4xl font-semibold">404</p><p className="mt-2 text-slate-500">ไม่พบหน้าที่ต้องการ</p></div>;

  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-navy-950/85">
        <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:px-6">
          <Link to="/app" className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="กลับไปแดชบอร์ด"><ArrowLeft className="h-4 w-4" /></Link>
          <Logo compact />
          <h1 className="font-semibold">ผู้ดูแลแพลตฟอร์ม</h1>
          <nav className="ml-4 flex gap-1 text-sm" aria-label="เมนูผู้ดูแล">
            <button type="button" aria-pressed={tab === 'orgs'} onClick={() => setTab('orgs')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 ${tab === 'orgs' ? 'bg-brand-700 text-white' : 'hover:bg-slate-100 dark:hover:bg-navy-800'}`}><Building2 className="h-4 w-4" /> องค์กร</button>
            <button type="button" aria-pressed={tab === 'abuse'} onClick={() => setTab('abuse')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 ${tab === 'abuse' ? 'bg-brand-700 text-white' : 'hover:bg-slate-100 dark:hover:bg-navy-800'}`}><Flag className="h-4 w-4" /> รายงานการละเมิด</button>
            <button type="button" aria-pressed={tab === 'templates'} onClick={() => setTab('templates')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 ${tab === 'templates' ? 'bg-brand-700 text-white' : 'hover:bg-slate-100 dark:hover:bg-navy-800'}`}><LayoutTemplate className="h-4 w-4" /> เทมเพลตรอตรวจ</button>
            <Link to="/app/admin/settings" className="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 hover:bg-slate-100 dark:hover:bg-navy-800"><Settings className="h-4 w-4" /> ตั้งค่าระบบ</Link>
          </nav>
          <span className="ml-auto" />
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
        {tab === 'orgs' ? (
          <>
            <input type="search" className="field-input mb-3 w-80" placeholder="ค้นหาชื่อองค์กรหรืออีเมลเจ้าของ" aria-label="ค้นหาองค์กร" value={search} onChange={(e) => setSearch(e.target.value)} />
            {orgs.isLoading ? <Spinner /> : (
              <table className="w-full text-left text-sm">
                <thead className="text-slate-500"><tr><th className="py-1">องค์กร</th><th>เจ้าของ</th><th>แพ็กเกจ</th><th>โปรเจกต์</th><th>สมาชิก</th><th>สถานะ</th><th>สร้างเมื่อ</th></tr></thead>
                <tbody>
                  {(orgs.data?.data ?? []).map((o) => (
                    <tr key={o.id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="py-2"><button type="button" className="text-brand-700 underline" onClick={() => setOpen(o.id)}>{o.name}</button></td>
                      <td>{o.owner}</td><td>{o.plan}</td><td>{o.projects}</td><td>{o.members}</td>
                      <td className={o.status === 'suspended' ? 'text-red-700' : ''}>{o.status === 'suspended' ? 'ระงับ' : 'ปกติ'}</td>
                      <td>{formatDate(o.createdAt)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </>
        ) : tab === 'templates' ? <TemplateQueue /> : (
          abuse.isLoading ? <Spinner /> : (abuse.data ?? []).length === 0 ? <p className="text-slate-500">ไม่มีรายงานที่รอดำเนินการ</p> : (
            <ul className="space-y-2 text-sm">
              {abuse.data!.map((r) => <AbuseItem key={r.id} r={r} onOpenOrg={setOpen} onDone={() => abuse.refetch()} />)}
            </ul>
          )
        )}
      </main>
      {open ? <OrgDialog id={open} onClose={() => { setOpen(null); orgs.refetch(); }} /> : null}
    </div>
  );
}

function AbuseItem({ r, onOpenOrg, onDone }: { r: AbuseRow; onOpenOrg: (id: string) => void; onDone: () => void }) {
  const resolve = (status: 'actioned' | 'dismissed') => {
    const resolution = window.prompt(status === 'actioned' ? 'บันทึกการดำเนินการ' : 'เหตุผลที่ไม่ดำเนินการ');
    if (!resolution) return;
    api(`/admin/abuse-reports/${r.id}`, { method: 'POST', body: { status, resolution } }).then(() => { toast.success('บันทึกแล้ว'); onDone(); }, (e) => toast.error(errorMessage(e)));
  };
  return (
    <li className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
      <div className="flex flex-wrap items-center gap-2">
        <span className="rounded-full bg-red-100 px-2 text-xs text-red-800">{CATEGORY[r.category]}</span>
        <code className="break-all">{r.url}</code>
        {r.organizationId ? <button type="button" className="text-brand-700 underline" onClick={() => onOpenOrg(r.organizationId!)}>{r.organization}</button> : <span className="text-slate-500">ไม่พบองค์กร</span>}
        <span className="ml-auto text-xs text-slate-500">{formatDate(r.createdAt)}</span>
      </div>
      <p className="mt-1 whitespace-pre-wrap">{r.details}</p>
      <div className="mt-2 flex gap-2"><Button size="sm" variant="secondary" onClick={() => resolve('actioned')}>ดำเนินการแล้ว</Button><Button size="sm" variant="ghost" onClick={() => resolve('dismissed')}>ไม่ดำเนินการ</Button></div>
    </li>
  );
}

function OrgDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const qc = useQueryClient();
  const key = ['admin-org', id];
  const org = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<OrgDetail>>(`/admin/organizations/${id}`)).data });
  const [reason, setReason] = useState('');
  const [plan, setPlan] = useState('pro');
  const [credits, setCredits] = useState(500);
  const [minutes, setMinutes] = useState(60);
  const act = (path: string, body: object, ok: string) => api(`/admin/organizations/${id}/${path}`, { method: 'POST', body: { ...body, reason } })
    .then(() => { toast.success(ok); qc.invalidateQueries({ queryKey: key }); }, (e) => toast.error(errorMessage(e)));
  const o = org.data;
  const activeGrant = o?.supportGrants.find((g) => g.status === 'approved');
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()} title={o ? `องค์กร: ${o.name}` : 'องค์กร'} wide>
      {!o ? <Spinner /> : (
        <div className="space-y-4 text-sm">
          <p>เจ้าของ {o.owner} · แพ็กเกจ <b>{o.plan}</b>{o.subscription ? ` (${o.subscription.status})` : ''} · สถานะ <b className={o.status === 'suspended' ? 'text-red-700' : ''}>{o.status === 'suspended' ? `ระงับ — ${o.suspendedReason}` : 'ปกติ'}</b></p>
          <div className="grid gap-2 sm:grid-cols-4">{Object.entries(o.usage).map(([k, u]) => <div key={k} className="rounded-lg bg-slate-50 p-2 dark:bg-navy-800"><p className="text-xs text-slate-500">{k}</p><p>{u.used.toLocaleString()} / {u.limit.toLocaleString()}</p></div>)}</div>
          <label className="block"><span className="field-label">เหตุผล (บังคับ — บันทึกใน audit log)</span><input className="field-input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="เช่น ชดเชยระบบล่มวันที่ …" /></label>
          <div className="flex flex-wrap items-end gap-2">
            <select aria-label="แพ็กเกจ" className="field-input w-auto" value={plan} onChange={(e) => setPlan(e.target.value)}><option value="free">free</option><option value="pro">pro</option><option value="business">business</option></select>
            <Button size="sm" variant="secondary" disabled={reason.length < 5} onClick={() => act('plan', { plan }, 'เปลี่ยนแพ็กเกจแล้ว')}>เปลี่ยนแพ็กเกจ</Button>
            <input aria-label="เครดิต AI" type="number" min={1} className="field-input w-28" value={credits} onChange={(e) => setCredits(Number(e.target.value))} />
            <Button size="sm" variant="secondary" disabled={reason.length < 5} onClick={() => act('credits', { credits }, 'เพิ่มเครดิตแล้ว')}>เพิ่มเครดิต AI เดือนนี้</Button>
            {o.status === 'suspended'
              ? <Button size="sm" variant="secondary" disabled={reason.length < 5} onClick={() => act('suspension', { suspend: false }, 'คืนสถานะแล้ว')}>คืนสถานะ</Button>
              : <Button size="sm" variant="danger" disabled={reason.length < 5} onClick={() => window.confirm('ระงับองค์กรนี้? เว็บไซต์ทั้งหมดจะออฟไลน์และเผยแพร่ไม่ได้ (ข้อมูลไม่ถูกลบ)') && act('suspension', { suspend: true }, 'ระงับแล้ว')}>ระงับองค์กร</Button>}
          </div>
          <section className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <h3 className="font-medium">สิทธิ์ฝ่ายสนับสนุน (อ่านอย่างเดียว ต้องให้องค์กรอนุมัติ)</h3>
            {activeGrant ? (
              <div className="mt-1">
                <p className="text-emerald-700">ได้รับอนุมัติถึง {formatDate(activeGrant.expiresAt)}</p>
                <ul className="mt-1 list-disc pl-5">{o.projectsList.map((p) => <li key={p.id}><Link className="text-brand-700 underline" to={`/app/projects/${p.id}`}>{p.name}</Link> · <Link className="underline" to={`/app/projects/${p.id}/data`}>ข้อมูล</Link></li>)}</ul>
              </div>
            ) : (
              <div className="mt-2 flex flex-wrap items-end gap-2">
                <select aria-label="ระยะเวลา" className="field-input w-auto" value={minutes} onChange={(e) => setMinutes(Number(e.target.value))}>{[30, 60, 120, 240].map((m) => <option key={m} value={m}>{m} นาที</option>)}</select>
                <Button size="sm" disabled={reason.length < 10} onClick={() => act('support-requests', { minutes }, 'ส่งคำขอถึงเจ้าขององค์กรแล้ว')}>ขอสิทธิ์เข้าดู</Button>
                <span className="text-xs text-slate-500">เหตุผลอย่างน้อย 10 ตัวอักษร · เจ้าของจะได้รับอีเมลและต้องอนุมัติเอง</span>
              </div>
            )}
            <ul className="mt-2 text-xs text-slate-500">{o.supportGrants.map((g) => <li key={g.id}>{formatDate(g.createdAt)} · {g.status} · {g.minutes} นาที · {g.reason}</li>)}</ul>
          </section>
          <details><summary className="cursor-pointer">กิจกรรมล่าสุด</summary><ul className="mt-1 text-xs">{o.recentAudit.map((a, i) => <li key={i}>{formatDate(a.at)} · {a.action}</li>)}</ul></details>
        </div>
      )}
    </Dialog>
  );
}

interface SubmissionRow { id: string; templateId: string | null; name: string; description: string; category: string; license: string; changelog: string | null; organization: string; submittedBy: string; sanitization: { emailActions: number; workflows: number } | null; assetCount: number; createdAt: string }

/** Moderation queue: preview the sanitized document, approve (publishes a new version) or reject with a reason. */
function TemplateQueue() {
  const q = useQuery({ queryKey: ['admin-template-queue'], queryFn: async () => (await api<Envelope<SubmissionRow[]>>('/admin/template-submissions')).data });
  const [open, setOpen] = useState<string | null>(null);
  const detail = useQuery({ queryKey: ['admin-template-sub', open], queryFn: async () => (await api<Envelope<SubmissionRow & { document: AppDefinition }>>(`/admin/template-submissions/${open}`)).data, enabled: !!open });
  const decide = (id: string, decision: 'approve' | 'reject') => {
    const reason = window.prompt(decision === 'approve' ? 'หมายเหตุถึงผู้ส่ง' : 'เหตุผลที่ไม่อนุมัติ (ผู้ส่งจะเห็น)');
    if (!reason) return;
    api(`/admin/template-submissions/${id}`, { method: 'POST', body: { decision, reason } }).then(() => { toast.success('บันทึกแล้ว'); setOpen(null); q.refetch(); }, (e) => toast.error(errorMessage(e)));
  };
  if (q.isLoading) return <Spinner />;
  if (!q.data?.length) return <p className="text-slate-500">ไม่มีเทมเพลตรอตรวจ</p>;
  const doc = detail.data?.document;
  return (
    <div className="grid gap-4 lg:grid-cols-[22rem_1fr]">
      <ul className="space-y-2 text-sm">
        {q.data.map((s) => (
          <li key={s.id}>
            <button type="button" onClick={() => setOpen(s.id)} className={`w-full rounded-xl border p-3 text-left ${open === s.id ? 'border-brand-600' : 'border-slate-200 dark:border-slate-700'}`}>
              <p className="font-medium">{s.name} {s.templateId ? <span className="text-xs text-slate-500">(เวอร์ชันใหม่)</span> : null}</p>
              <p className="text-xs text-slate-500">{s.organization} · {s.submittedBy} · {formatDate(s.createdAt)}</p>
            </button>
          </li>
        ))}
      </ul>
      {open && detail.data ? (
        <div className="space-y-3 text-sm">
          <p><b>{detail.data.name}</b> · {detail.data.category} · สัญญาอนุญาต {detail.data.license === 'free' ? 'ใช้ได้ทุกวัตถุประสงค์' : 'ใช้ส่วนตัว'} · รูป {detail.data.assetCount} ไฟล์</p>
          <p>{detail.data.description}</p>
          {detail.data.changelog ? <p className="text-slate-500">มีอะไรใหม่: {detail.data.changelog}</p> : null}
          {detail.data.sanitization ? <p className="text-xs text-slate-500">นำออกอัตโนมัติ: อีเมลที่อยู่คงที่ {detail.data.sanitization.emailActions} · workflow ที่ว่าง {detail.data.sanitization.workflows}</p> : null}
          <p className="text-xs text-slate-500">หน้า: {doc?.pages.map((p) => p.path).join(', ')} · ตาราง: {doc?.dataModels.map((m) => m.label).join(', ') || '—'} · workflow: {doc?.workflows.length ?? 0}</p>
          <div className="relative h-96 overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="ตัวอย่างเทมเพลต">
            {doc ? <div className="pointer-events-none absolute left-0 top-0 w-[1280px] origin-top-left" style={{ transform: 'scale(0.5)' }}><PageRenderer doc={doc} pageId={doc.settings.homePageId} breakpoint="base" mode="live" /></div> : null}
          </div>
          <div className="flex gap-2"><Button size="sm" onClick={() => decide(open, 'approve')}>อนุมัติและเผยแพร่</Button><Button size="sm" variant="ghost" onClick={() => decide(open, 'reject')}>ไม่อนุมัติ</Button></div>
        </div>
      ) : <p className="text-slate-500">เลือกรายการเพื่อดูตัวอย่าง</p>}
    </div>
  );
}
