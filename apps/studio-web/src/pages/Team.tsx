import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import { ArrowLeft, Download, KeyRound, LifeBuoy, Lock, Monitor, ShieldCheck, UserPlus } from 'lucide-react';
import { api, ApiError, errorMessage, type Envelope } from '../lib/api.ts';
import { ME_KEY, useCurrentWorkspace, useMe } from '../lib/auth.tsx';
import { Button } from '../components/ui/Button.tsx';
import { Field } from '../components/ui/Field.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { Logo, Spinner, ThemeToggle, formatDate } from '../components/ui/misc.tsx';

const ROLE: Record<string, string> = { admin: 'ผู้ดูแล', editor: 'ผู้แก้ไข', developer: 'นักพัฒนา', viewer: 'ดูอย่างเดียว' };

/** Team (members, invitations, support access) and personal security (2FA, sessions). */
export default function Team() {
  const ws = useCurrentWorkspace();
  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-navy-950/85">
        <div className="mx-auto flex max-w-4xl items-center gap-3 px-4 py-3 sm:px-6">
          <Link to="/app" className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="กลับไปแดชบอร์ด"><ArrowLeft className="h-4 w-4" /></Link>
          <Logo compact />
          <h1 className="font-semibold">ทีมและความปลอดภัย</h1>
          <span className="ml-auto" />
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
        {ws ? <Members workspaceId={ws.id} workspaceName={ws.name} canAdmin={ws.role === 'admin'} /> : null}
        {ws?.organization && ['owner', 'admin'].includes(ws.organization.role) ? <SupportRequests orgId={ws.organization.id} /> : null}
        <TwoFactor />
        <Sessions />
        <Privacy />
      </main>
    </div>
  );
}

function Card({ id, title, icon, children }: { id: string; title: string; icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <section aria-labelledby={id} className="rounded-2xl border border-slate-200 bg-white p-6 text-sm dark:border-slate-800 dark:bg-navy-900">
      <h2 id={id} className="mb-3 flex items-center gap-2 text-lg font-semibold">{icon}{title}</h2>
      {children}
    </section>
  );
}

function Members({ workspaceId, workspaceName, canAdmin }: { workspaceId: string; workspaceName: string; canAdmin: boolean }) {
  const qc = useQueryClient();
  const key = ['members', workspaceId];
  const q = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<{ members: Array<{ userId: string; name: string; email: string; role: string; twoFactor: boolean }>; invitations: Array<{ id: string; email: string; role: string; expiresAt: string }> }>>(`/workspaces/${workspaceId}/members`)).data });
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('editor');
  const [error, setError] = useState('');
  const refresh = () => qc.invalidateQueries({ queryKey: key });
  const call = (p: Promise<unknown>, ok?: string) => p.then(() => { if (ok) toast.success(ok); refresh(); }, (e) => toast.error(errorMessage(e)));
  return (
    <Card id="members-h" title={`สมาชิก · ${workspaceName}`} icon={<UserPlus className="h-5 w-5" />}>
      {canAdmin ? (
        <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={(e) => {
          e.preventDefault();
          setError('');
          api(`/workspaces/${workspaceId}/invitations`, { method: 'POST', body: { email, role } }).then(() => { toast.success(`ส่งคำเชิญถึง ${email} แล้ว`); setEmail(''); refresh(); }, (err) => setError(errorMessage(err)));
        }}>
          <label className="flex-1"><span className="field-label">อีเมล</span><input className="field-input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="name@example.com" /></label>
          <label><span className="field-label">บทบาท</span><select className="field-input w-auto" value={role} onChange={(e) => setRole(e.target.value)}>{Object.entries(ROLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          <Button type="submit" size="sm" disabled={!email}>ส่งคำเชิญ</Button>
          {error ? <p role="alert" className="w-full text-red-700">{error}</p> : null}
        </form>
      ) : null}
      {q.isLoading ? <Spinner /> : (
        <>
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {q.data?.members.map((m) => (
              <li key={m.userId} className="flex flex-wrap items-center gap-2 py-2">
                <span className="font-medium">{m.name}</span><span className="text-slate-500">{m.email}</span>
                {m.twoFactor ? <span className="rounded-full bg-emerald-100 px-2 text-xs text-emerald-800">2FA</span> : null}
                <span className="ml-auto" />
                {canAdmin ? (
                  <>
                    <select aria-label={`บทบาทของ ${m.email}`} className="field-input h-8 w-auto py-0" value={m.role} onChange={(e) => call(api(`/workspaces/${workspaceId}/members/${m.userId}`, { method: 'PATCH', body: { role: e.target.value } }), 'เปลี่ยนบทบาทแล้ว')}>
                      {Object.entries(ROLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <Button size="sm" variant="ghost" onClick={() => window.confirm(`นำ ${m.email} ออกจาก Workspace?`) && call(api(`/workspaces/${workspaceId}/members/${m.userId}`, { method: 'DELETE' }), 'นำออกแล้ว')}>นำออก</Button>
                  </>
                ) : <span>{ROLE[m.role]}</span>}
              </li>
            ))}
          </ul>
          {q.data && q.data.invitations.length > 0 ? (
            <div className="mt-3"><p className="font-medium">คำเชิญที่รอตอบรับ</p>
              <ul>{q.data.invitations.map((i) => (
                <li key={i.id} className="flex items-center gap-2 py-1"><span>{i.email}</span><span className="text-slate-500">{ROLE[i.role]} · หมดอายุ {formatDate(i.expiresAt)}</span>
                  {canAdmin ? <Button size="sm" variant="ghost" onClick={() => call(api(`/workspaces/${workspaceId}/invitations/${i.id}`, { method: 'DELETE' }), 'ยกเลิกคำเชิญแล้ว')}>ยกเลิก</Button> : null}</li>
              ))}</ul>
            </div>
          ) : null}
        </>
      )}
    </Card>
  );
}

function SupportRequests({ orgId }: { orgId: string }) {
  const q = useQuery({ queryKey: ['support', orgId], queryFn: async () => (await api<Envelope<Array<{ id: string; requestedBy: string; reason: string; minutes: number; status: string; expiresAt: string | null; createdAt: string }>>>(`/organizations/${orgId}/support-requests`)).data });
  const decide = (id: string, decision: string) => api(`/organizations/${orgId}/support-requests/${id}`, { method: 'POST', body: { decision } })
    .then(() => { toast.success('บันทึกแล้ว'); q.refetch(); }, (e) => toast.error(errorMessage(e)));
  const STATUS: Record<string, string> = { pending: 'รออนุมัติ', approved: 'อนุมัติแล้ว', denied: 'ปฏิเสธ', revoked: 'เพิกถอนแล้ว', expired: 'หมดอายุ' };
  return (
    <Card id="support-h" title="คำขอเข้าถึงจากฝ่ายสนับสนุน" icon={<LifeBuoy className="h-5 w-5" />}>
      <p className="mb-2 text-slate-500">ฝ่ายสนับสนุนดูข้อมูลขององค์กรได้เฉพาะเมื่อคุณอนุมัติ แบบอ่านอย่างเดียวและมีเวลาจำกัด ทุกการเข้าถึงถูกบันทึก</p>
      {(q.data ?? []).length === 0 ? <p className="text-slate-500">ไม่มีคำขอ</p> : (
        <ul className="space-y-2">{q.data!.map((g) => (
          <li key={g.id} className="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <p><b>{g.requestedBy}</b> ขอเข้าดู {g.minutes} นาที · <span data-testid="support-status">{STATUS[g.status]}</span>{g.status === 'approved' && g.expiresAt ? ` ถึง ${formatDate(g.expiresAt)}` : ''}</p>
            <p className="text-slate-600 dark:text-slate-300">เหตุผล: {g.reason}</p>
            <div className="mt-2 flex gap-2">
              {g.status === 'pending' ? <><Button size="sm" onClick={() => decide(g.id, 'approve')}>อนุมัติ</Button><Button size="sm" variant="ghost" onClick={() => decide(g.id, 'deny')}>ปฏิเสธ</Button></> : null}
              {g.status === 'approved' ? <Button size="sm" variant="danger" onClick={() => decide(g.id, 'revoke')}>เพิกถอนทันที</Button> : null}
            </div>
          </li>
        ))}</ul>
      )}
    </Card>
  );
}

function TwoFactor() {
  const me = useMe().data;
  const qc = useQueryClient();
  const [password, setPassword] = useState('');
  const [setup, setSetup] = useState<{ secret: string; otpauthUri: string } | null>(null);
  const [qr, setQr] = useState('');
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [error, setError] = useState('');
  useEffect(() => {
    if (setup) QRCode.toDataURL(setup.otpauthUri, { margin: 1, width: 180 }).then(setQr, () => setQr(''));
  }, [setup]);
  const fail = (e: unknown) => setError(e instanceof ApiError && Object.keys(e.fields).length ? Object.values(e.fields)[0][0] : errorMessage(e));
  const enabled = me?.user.twoFactorEnabled;
  return (
    <Card id="tfa-h" title="การยืนยันตัวตนสองขั้นตอน (2FA)" icon={<ShieldCheck className="h-5 w-5" />}>
      {codes ? (
        <div role="status" className="rounded-xl bg-amber-50 p-4 text-amber-900 dark:bg-amber-950 dark:text-amber-100">
          <p className="font-medium">รหัสกู้คืน (แสดงครั้งเดียว — เก็บไว้ในที่ปลอดภัย แต่ละรหัสใช้ได้ครั้งเดียว)</p>
          <ul data-testid="recovery-codes" className="mt-2 grid grid-cols-2 gap-1 font-mono">{codes.map((c) => <li key={c}>{c}</li>)}</ul>
          <Button size="sm" variant="secondary" className="mt-2" onClick={() => setCodes(null)}>บันทึกแล้ว</Button>
        </div>
      ) : enabled ? (
        <div className="space-y-2">
          <p className="text-emerald-700" data-testid="tfa-status">เปิดใช้งานแล้ว — ต้องใช้รหัสจากแอปทุกครั้งที่เข้าสู่ระบบ</p>
          <div className="flex flex-wrap items-end gap-2">
            <div className="w-64"><Field label="รหัสผ่านปัจจุบัน" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></div>
            <Button size="sm" variant="secondary" disabled={!password} onClick={() => api<Envelope<{ recoveryCodes: string[] }>>('/auth/two-factor/recovery-codes', { method: 'POST', body: { password } }).then((r) => { setCodes(r.data.recoveryCodes); setPassword(''); }, fail)}>สร้างรหัสกู้คืนใหม่</Button>
            <Button size="sm" variant="danger" disabled={!password} onClick={() => window.confirm('ปิดการยืนยันสองขั้นตอน? บัญชีจะปลอดภัยน้อยลง') && api('/auth/two-factor/disable', { method: 'POST', body: { password } }).then(() => { toast.success('ปิดแล้ว'); setPassword(''); qc.invalidateQueries({ queryKey: ME_KEY }); }, fail)}>ปิด 2FA</Button>
          </div>
        </div>
      ) : setup ? (
        <div className="space-y-3">
          <p>1) สแกน QR ด้วยแอปยืนยันตัวตน (เช่น Google Authenticator, Microsoft Authenticator) หรือกรอกรหัสด้วยตนเอง</p>
          {qr ? <img src={qr} alt="QR code สำหรับแอปยืนยันตัวตน" width={180} height={180} className="rounded-lg border border-slate-200" /> : null}
          <p>รหัสสำหรับกรอกเอง: <code data-testid="tfa-secret" className="break-all">{setup.secret}</code></p>
          <p>2) กรอกรหัส 6 หลักที่แอปแสดงเพื่อยืนยัน</p>
          <div className="flex items-end gap-2">
            <div className="w-40"><Field label="รหัสยืนยัน" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} autoComplete="one-time-code" /></div>
            <Button size="sm" disabled={code.length !== 6} onClick={() => api<Envelope<{ recoveryCodes: string[] }>>('/auth/two-factor/confirm', { method: 'POST', body: { code } }).then((r) => { setCodes(r.data.recoveryCodes); setSetup(null); setCode(''); qc.invalidateQueries({ queryKey: ME_KEY }); }, fail)}>ยืนยันและเปิดใช้</Button>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap items-end gap-2">
          <p className="w-full text-slate-500">เพิ่มความปลอดภัยด้วยรหัสจากแอปในโทรศัพท์ นอกเหนือจากรหัสผ่าน</p>
          <div className="w-64"><Field label="รหัสผ่านปัจจุบัน" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></div>
          <Button size="sm" disabled={!password} onClick={() => api<Envelope<{ secret: string; otpauthUri: string }>>('/auth/two-factor', { method: 'POST', body: { password } }).then((r) => { setSetup(r.data); setPassword(''); setError(''); }, fail)}><KeyRound className="h-4 w-4" /> เริ่มตั้งค่า 2FA</Button>
        </div>
      )}
      {error ? <p role="alert" className="mt-2 text-red-700">{error}</p> : null}
    </Card>
  );
}

function Sessions() {
  const q = useQuery({ queryKey: ['sessions'], queryFn: async () => (await api<Envelope<Array<{ id: string; ip: string; userAgent: string; lastActiveAt: string; current: boolean }>>>('/auth/sessions')).data });
  const [password, setPassword] = useState('');
  return (
    <Card id="sess-h" title="อุปกรณ์ที่เข้าสู่ระบบ" icon={<Monitor className="h-5 w-5" />}>
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {(q.data ?? []).map((s) => (
          <li key={s.id} className="flex flex-wrap items-center gap-2 py-2">
            <span className="min-w-0 flex-1 truncate" title={s.userAgent}>{s.userAgent || 'ไม่ทราบอุปกรณ์'}</span>
            <span className="text-slate-500">{s.ip} · {formatDate(s.lastActiveAt)}</span>
            {s.current ? <span className="rounded-full bg-brand-100 px-2 text-xs text-brand-800">อุปกรณ์นี้</span>
              : <Button size="sm" variant="ghost" onClick={() => api(`/auth/sessions/${s.id}`, { method: 'DELETE' }).then(() => { toast.success('ออกจากระบบอุปกรณ์นั้นแล้ว'); q.refetch(); }, (e) => toast.error(errorMessage(e)))}>ออกจากระบบ</Button>}
          </li>
        ))}
      </ul>
      <div className="mt-3 flex flex-wrap items-end gap-2">
        <div className="w-64"><Field label="รหัสผ่านปัจจุบัน" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></div>
        <Button size="sm" variant="secondary" disabled={!password} onClick={() => api<Envelope<{ revoked: number }>>('/auth/sessions/logout-others', { method: 'POST', body: { password } }).then((r) => { toast.success(`ออกจากระบบอุปกรณ์อื่น ${r.data.revoked} เครื่องแล้ว`); setPassword(''); q.refetch(); }, (e) => toast.error(errorMessage(e)))}>ออกจากระบบทุกอุปกรณ์อื่น</Button>
      </div>
    </Card>
  );
}

function Privacy() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['consent'], queryFn: async () => (await api<Envelope<{ termsVersion: string | null; privacyVersion: string | null; consentedAt: string | null; marketing: boolean }>>('/auth/privacy/consent')).data });
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  return (
    <Card id="privacy-h" title="ข้อมูลส่วนบุคคล (PDPA)" icon={<Lock className="h-5 w-5" />}>
      {q.data ? (
        <div className="space-y-2">
          <p className="text-slate-500">ยอมรับข้อกำหนดเวอร์ชัน {q.data.termsVersion ?? '—'} และนโยบายความเป็นส่วนตัวเวอร์ชัน {q.data.privacyVersion ?? '—'}{q.data.consentedAt ? ` เมื่อ ${formatDate(q.data.consentedAt)}` : ''}</p>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={q.data.marketing} onChange={(e) => api('/auth/privacy/consent', { method: 'PUT', body: { marketing: e.target.checked } }).then(() => q.refetch(), (err) => toast.error(errorMessage(err)))} />
            ยินยอมรับข่าวสารและข้อเสนอทางอีเมล (ถอนความยินยอมได้ทุกเมื่อ)
          </label>
        </div>
      ) : null}
      <div className="mt-4">
        <a className="inline-flex items-center gap-1.5 text-brand-700 underline" href="/api/v1/auth/privacy/export"><Download className="h-4 w-4" /> ดาวน์โหลดข้อมูลส่วนบุคคลของฉัน (JSON)</a>
      </div>
      <details className="mt-4 rounded-lg border border-red-200 p-3 dark:border-red-900">
        <summary className="cursor-pointer font-medium text-red-700">ลบบัญชี</summary>
        <p className="mt-2 text-slate-600 dark:text-slate-300">ข้อมูลบัญชีจะถูกลบ/ทำให้ไม่สามารถระบุตัวตนได้ทันที องค์กรที่คุณเป็นเจ้าของคนเดียวจะถูกลบถาวรหลัง 30 วัน (รวมโปรเจกต์และข้อมูลทั้งหมด) หากองค์กรมีสมาชิกคนอื่น ต้องโอนความเป็นเจ้าของก่อน</p>
        <div className="mt-2 flex flex-wrap items-end gap-2">
          <div className="w-56"><Field label="รหัสผ่าน" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></div>
          <div className="w-56"><Field label='พิมพ์ "DELETE" เพื่อยืนยัน' value={confirm} onChange={(e) => setConfirm(e.target.value)} /></div>
          <Button variant="danger" size="sm" disabled={!password || confirm !== 'DELETE'} onClick={() => api('/auth/privacy/delete-account', { method: 'POST', body: { password, confirm } }).then(() => { qc.clear(); navigate('/login', { replace: true }); }, (e) => setError(errorMessage(e)))}>ลบบัญชีถาวร</Button>
        </div>
        {error ? <p role="alert" className="mt-2 text-red-700">{error}</p> : null}
      </details>
    </Card>
  );
}
