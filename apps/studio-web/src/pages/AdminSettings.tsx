import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, Copy, KeyRound, Lock, ShieldCheck } from 'lucide-react';
import { api, ApiError, errorMessage, type Envelope } from '../lib/api.ts';
import { useMe } from '../lib/auth.tsx';
import { Button } from '../components/ui/Button.tsx';
import { Field } from '../components/ui/Field.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { ErrorState, FullPageSpinner, Logo, ThemeToggle, formatDate } from '../components/ui/misc.tsx';

interface GoogleOAuthSummary {
  configured: boolean;
  source: 'environment' | 'database' | null;
  environmentManaged: boolean;
  clientId: string | null;
  secretLast4: string | null;
  updatedAt: string | null;
  updatedBy: string | null;
  authorizedOrigin: string;
  redirectUri: string;
  scopes: string[];
}

const URL = '/admin/settings/google-oauth';

function fieldErrors(e: unknown): Record<string, string> {
  if (!(e instanceof ApiError)) return {};
  const f = (e.details.fields ?? {}) as Record<string, string | string[]>;
  return Object.fromEntries(Object.entries(f).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
}

function CopyValue({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-slate-500">{label}</p>
      <div className="mt-1 flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 dark:bg-navy-800">
        <code className="min-w-0 flex-1 break-all text-xs">{value}</code>
        <Button size="sm" variant="ghost" aria-label={`คัดลอก ${label}`}
          onClick={() => navigator.clipboard?.writeText(value).then(() => toast.success('คัดลอกแล้ว'), () => toast.error('คัดลอกไม่สำเร็จ'))}>
          <Copy className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}

/** Platform operator settings (visible only to platform admins; the API returns 404 to everyone else). */
export default function AdminSettings() {
  const me = useMe().data;
  const qc = useQueryClient();
  const google = useQuery({
    queryKey: ['admin', 'google-oauth'],
    queryFn: async () => (await api<Envelope<GoogleOAuthSummary>>(URL)).data,
    enabled: !!me?.user.isPlatformAdmin,
  });
  const [clientId, setClientId] = useState<string | null>(null);
  const [secret, setSecret] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [confirmRemove, setConfirmRemove] = useState(false);

  const done = (data: GoogleOAuthSummary, msg: string) => {
    qc.setQueryData(['admin', 'google-oauth'], data);
    setSecret('');
    setPassword('');
    setErrors({});
    setClientId(null);
    setConfirmRemove(false);
    toast.success(msg);
  };
  const save = useMutation({
    mutationFn: async () => (await api<Envelope<GoogleOAuthSummary>>(URL, { method: 'PUT', body: { clientId: clientId ?? google.data?.clientId ?? '', clientSecret: secret || undefined, password } })).data,
    onSuccess: (d) => done(d, 'บันทึกการตั้งค่า Google OAuth แล้ว'),
    onError: (e) => { setErrors(fieldErrors(e)); toast.error(errorMessage(e)); },
  });
  const remove = useMutation({
    mutationFn: async () => (await api<Envelope<GoogleOAuthSummary>>(URL, { method: 'DELETE', body: { password } })).data,
    onSuccess: (d) => done(d, 'ลบการตั้งค่าแล้ว'),
    onError: (e) => { setErrors(fieldErrors(e)); toast.error(errorMessage(e)); },
  });

  if (!me) return <FullPageSpinner />;
  if (!me.user.isPlatformAdmin) {
    return <div className="p-10 text-center"><p className="text-4xl font-semibold">404</p><p className="mt-2 text-slate-500">ไม่พบหน้าที่ต้องการ</p><Link className="mt-4 inline-block text-brand-700 underline" to="/app">กลับหน้าหลัก</Link></div>;
  }
  const g = google.data;
  const locked = g?.environmentManaged ?? false;
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate(); };

  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-navy-950/85">
        <div className="mx-auto flex max-w-4xl items-center gap-3 px-4 py-3 sm:px-6">
          <Link to="/app" className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="กลับไปแดชบอร์ด"><ArrowLeft className="h-4 w-4" /></Link>
          <Logo compact />
          <h1 className="font-semibold">ตั้งค่าระบบ (ผู้ดูแลแพลตฟอร์ม)</h1>
          <span className="ml-auto" />
          <ThemeToggle />
        </div>
      </header>

      <main className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
        <section aria-labelledby="g-h" className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
          <div className="flex flex-wrap items-start gap-3">
            <div>
              <h2 id="g-h" className="flex items-center gap-2 text-lg font-semibold"><KeyRound className="h-5 w-5" /> Google OAuth client</h2>
              <p className="mt-1 text-sm text-slate-500">ใช้สำหรับให้ผู้ใช้เชื่อมต่อ Google Sheets / Drive กับแอปของตน — ค่า secret ถูกเข้ารหัสก่อนบันทึกและจะไม่ถูกแสดงอีก</p>
            </div>
            {g ? (
              <span data-testid="google-status" className={`ml-auto rounded-full px-3 py-1 text-xs ${g.configured ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
                {g.configured ? (g.source === 'environment' ? 'ตั้งค่าแล้ว (environment)' : 'ตั้งค่าแล้ว') : 'ยังไม่ได้ตั้งค่า'}
              </span>
            ) : null}
          </div>

          {google.isLoading ? <p className="mt-4 text-sm text-slate-500">กำลังโหลด…</p> : google.error ? <div className="mt-4"><ErrorState message={errorMessage(google.error)} /></div> : g ? (
            <>
              <div className="mt-5 rounded-xl bg-sky-50 p-4 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-100">
                <p className="font-medium">ขั้นตอนใน Google Cloud Console</p>
                <ol className="mt-2 list-decimal space-y-1 pl-5">
                  <li>APIs &amp; Services → OAuth consent screen: ตั้งชื่อแอป อีเมลติดต่อ และโดเมน</li>
                  <li>เปิดใช้ Google Sheets API และ Google Drive API</li>
                  <li>Credentials → Create credentials → OAuth client ID → ประเภท <b>Web application</b></li>
                  <li>ใส่ค่า Authorized JavaScript origin และ Authorized redirect URI ด้านล่าง แล้วคัดลอก Client ID / Client secret มากรอกที่นี่</li>
                </ol>
                <div className="mt-3 grid gap-3">
                  <CopyValue label="Authorized JavaScript origin" value={g.authorizedOrigin} />
                  <CopyValue label="Authorized redirect URI" value={g.redirectUri} />
                </div>
                <p className="mt-2 text-xs">สิทธิ์ (scopes) ที่ระบบจะขอ: {g.scopes.join(', ')}</p>
              </div>

              {locked ? (
                <div className="mt-5 flex gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700">
                  <Lock className="h-5 w-5 shrink-0 text-slate-500" />
                  <div>
                    <p className="font-medium">กำหนดผ่าน environment variables ของเซิร์ฟเวอร์</p>
                    <p className="text-slate-500">GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET มีลำดับความสำคัญสูงกว่าค่าในหน้านี้ ต้องแก้ไขที่การตั้งค่าเซิร์ฟเวอร์</p>
                    <p className="mt-2">Client ID: <code className="break-all">{g.clientId ?? '—'}</code>{g.secretLast4 ? <> · secret ลงท้าย <code>…{g.secretLast4}</code></> : null}</p>
                  </div>
                </div>
              ) : (
                <form className="mt-5 grid gap-4" onSubmit={submit} noValidate>
                  {g.configured ? (
                    <p className="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400">
                      <CheckCircle2 className="h-4 w-4" /> secret ลงท้าย <code>…{g.secretLast4}</code>{g.updatedAt ? ` · แก้ไขล่าสุด ${formatDate(g.updatedAt)}${g.updatedBy ? ` โดย ${g.updatedBy}` : ''}` : ''}
                    </p>
                  ) : null}
                  <Field label="Client ID" value={clientId ?? g.clientId ?? ''} onChange={(e) => setClientId(e.target.value.trim())}
                    placeholder="1234567890-xxxx.apps.googleusercontent.com" autoComplete="off" spellCheck={false} error={errors.clientId} />
                  <Field label="Client secret" type="password" value={secret} onChange={(e) => setSecret(e.target.value.trim())}
                    placeholder={g.configured ? 'เว้นว่างไว้เพื่อใช้ค่าเดิม' : 'GOCSPX-…'} autoComplete="new-password" spellCheck={false} error={errors.clientSecret}
                    hint="ค่านี้จะไม่ถูกแสดงอีกหลังบันทึก — หากต้องการเปลี่ยน ให้สร้าง secret ใหม่ใน Google Cloud Console แล้วกรอกที่นี่" />
                  <Field label="ยืนยันรหัสผ่านของคุณ" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" error={errors.password}
                    hint="ต้องยืนยันรหัสผ่านทุกครั้งที่เปลี่ยนค่าลับของระบบ" />
                  <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" loading={save.isPending} disabled={!password}><ShieldCheck className="h-4 w-4" /> บันทึก</Button>
                    {g.configured ? (
                      confirmRemove ? (
                        <>
                          <span className="text-sm text-red-700">ลบแล้วผู้ใช้จะเชื่อมต่อ Google ใหม่ไม่ได้</span>
                          <Button variant="danger" size="sm" loading={remove.isPending} disabled={!password} onClick={() => remove.mutate()}>ยืนยันการลบ</Button>
                          <Button variant="ghost" size="sm" onClick={() => setConfirmRemove(false)}>ยกเลิก</Button>
                        </>
                      ) : <Button variant="ghost" className="text-red-700" onClick={() => setConfirmRemove(true)}>ลบการตั้งค่า</Button>
                    ) : null}
                  </div>
                </form>
              )}
              <p className="mt-5 border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-slate-800">
                หมายเหตุ: ขั้นตอนเชื่อมต่อบัญชี Google และซิงก์ Google Sheets จะเปิดใช้งานใน Phase 5 — หน้านี้เตรียม client ไว้ล่วงหน้า
              </p>
            </>
          ) : null}
        </section>
      </main>
    </div>
  );
}
