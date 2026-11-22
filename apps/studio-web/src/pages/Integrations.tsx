import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, FileSpreadsheet, FolderOpen, Link2, Unplug } from 'lucide-react';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { useCurrentWorkspace } from '../lib/auth.tsx';
import { Button } from '../components/ui/Button.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { ErrorState, Logo, Spinner, ThemeToggle, formatDate } from '../components/ui/misc.tsx';

interface GoogleStatus {
  platformConfigured: boolean;
  canManage: boolean;
  features: string[];
  connection: { email: string | null; status: 'active' | 'revoked' | 'error'; features: string[]; connectedBy: string | null; connectedAt: string; lastUsedAt: string | null; lastError: string | null } | null;
}

const FEATURE_INFO: Record<string, { label: string; detail: string; icon: typeof FileSpreadsheet }> = {
  sheets: { label: 'Google Sheets', detail: 'อ่าน/เขียนสเปรดชีตที่คุณใส่ลิงก์ไว้ในการซิงก์เท่านั้น', icon: FileSpreadsheet },
  drive: { label: 'Google Drive', detail: 'เข้าถึงเฉพาะโฟลเดอร์และไฟล์ที่ระบบนี้สร้าง (drive.file) — ไม่เห็นไฟล์อื่นใน Drive ของคุณ', icon: FolderOpen },
};

const RESULT: Record<string, string> = {
  connected: 'เชื่อมต่อ Google สำเร็จ',
  partial: 'เชื่อมต่อแล้ว แต่ไม่ได้อนุญาตทุกฟีเจอร์ที่เลือก — ฟีเจอร์ที่ไม่ได้อนุญาตจะใช้ไม่ได้',
};
const REASON: Record<string, string> = {
  denied: 'คุณไม่ได้อนุญาตการเข้าถึงในหน้าของ Google',
  state: 'คำขอเชื่อมต่อหมดอายุหรือไม่ถูกต้อง กรุณาลองใหม่',
  session: 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบแล้วลองใหม่',
  forbidden: 'เฉพาะผู้ดูแล Workspace เท่านั้นที่เชื่อมต่อได้',
  auth: 'Google ปฏิเสธการยืนยันตัวตน กรุณาลองใหม่',
  not_configured: 'ผู้ดูแลระบบยังไม่ได้ตั้งค่า Google OAuth',
};

/** Workspace integrations (M22): connect/disconnect Google with explicit, least-privilege features. */
export default function Integrations() {
  const ws = useCurrentWorkspace();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const key = ['google-status', ws?.id];
  const status = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<GoogleStatus>>(`/workspaces/${ws!.id}/integrations/google`)).data, enabled: !!ws });
  const [features, setFeatures] = useState<string[]>(['sheets', 'drive']);
  const [confirm, setConfirm] = useState(false);

  useEffect(() => {
    const result = params.get('google');
    if (!result) return;
    if (result === 'error') toast.error(REASON[params.get('reason') ?? ''] ?? 'เชื่อมต่อ Google ไม่สำเร็จ');
    else toast.success(RESULT[result] ?? 'เชื่อมต่อแล้ว');
    setParams({}, { replace: true });
  }, [params, setParams]);

  const connect = useMutation({
    mutationFn: async () => (await api<Envelope<{ url: string }>>(`/workspaces/${ws!.id}/integrations/google/connect`, { method: 'POST', body: { features } })).data.url,
    onSuccess: (url) => { window.location.assign(url); },
    onError: (e) => toast.error(errorMessage(e)),
  });
  const disconnect = useMutation({
    mutationFn: async () => (await api<Envelope<{ revokedAtGoogle: boolean }>>(`/workspaces/${ws!.id}/integrations/google`, { method: 'DELETE' })).data,
    onSuccess: (d) => {
      setConfirm(false);
      toast.success(d.revokedAtGoogle ? 'ยกเลิกการเชื่อมต่อและเพิกถอนสิทธิ์ที่ Google แล้ว' : 'ยกเลิกการเชื่อมต่อแล้ว (ลบโทเค็นในระบบแล้ว แต่ Google ไม่ตอบรับการเพิกถอน — ตรวจสอบได้ที่ myaccount.google.com/permissions)');
      qc.invalidateQueries({ queryKey: key });
    },
    onError: (e) => toast.error(errorMessage(e)),
  });

  const s = status.data;
  const c = s?.connection;
  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-navy-950/85">
        <div className="mx-auto flex max-w-4xl items-center gap-3 px-4 py-3 sm:px-6">
          <Link to="/app" className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="กลับไปแดชบอร์ด"><ArrowLeft className="h-4 w-4" /></Link>
          <Logo compact />
          <h1 className="font-semibold">การเชื่อมต่อ · {ws?.name}</h1>
          <span className="ml-auto" />
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <section aria-labelledby="google-h" className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
          <div className="flex flex-wrap items-start gap-3">
            <div>
              <h2 id="google-h" className="flex items-center gap-2 text-lg font-semibold"><Link2 className="h-5 w-5" /> Google</h2>
              <p className="mt-1 text-sm text-slate-500">เชื่อมต่อบัญชี Google หนึ่งบัญชีต่อ Workspace เพื่อซิงก์ข้อมูลกับ Google Sheets และเก็บไฟล์แนบใน Google Drive</p>
            </div>
            {c ? (
              <span data-testid="google-connection-status" className={`ml-auto rounded-full px-3 py-1 text-xs ${c.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'}`}>
                {c.status === 'active' ? 'เชื่อมต่อแล้ว' : 'การเชื่อมต่อใช้ไม่ได้ — กรุณาเชื่อมต่อใหม่'}
              </span>
            ) : null}
          </div>

          {status.isLoading ? <Spinner /> : status.error ? <ErrorState message={errorMessage(status.error)} /> : s ? (
            <div className="mt-5 space-y-4 text-sm">
              {c ? (
                <div className="rounded-xl bg-slate-50 p-4 dark:bg-navy-800">
                  <p>บัญชี: <b>{c.email}</b></p>
                  <p className="text-slate-500">อนุญาต: {c.features.map((f) => FEATURE_INFO[f]?.label ?? f).join(', ') || '—'} · เชื่อมต่อโดย {c.connectedBy ?? '—'} เมื่อ {formatDate(c.connectedAt)}{c.lastUsedAt ? ` · ใช้งานล่าสุด ${formatDate(c.lastUsedAt)}` : ''}</p>
                  {c.lastError ? <p role="alert" className="mt-1 text-red-700">{c.lastError}</p> : null}
                </div>
              ) : null}

              {!s.platformConfigured ? (
                <p role="status" className="rounded-xl bg-amber-50 p-4 text-amber-900 dark:bg-amber-950 dark:text-amber-100">ผู้ดูแลแพลตฟอร์มยังไม่ได้ตั้งค่า Google OAuth client — การเชื่อมต่อ Google จึงยังไม่เปิดใช้งาน</p>
              ) : !s.canManage ? (
                <p className="text-slate-500">เฉพาะผู้ดูแล Workspace เท่านั้นที่เชื่อมต่อหรือยกเลิกการเชื่อมต่อได้</p>
              ) : (
                <>
                  <fieldset className="space-y-2">
                    <legend className="mb-1 font-medium">{c ? 'เชื่อมต่อใหม่ / เปลี่ยนสิทธิ์' : 'เลือกสิ่งที่อนุญาตให้ระบบเข้าถึง'}</legend>
                    {s.features.map((f) => {
                      const info = FEATURE_INFO[f];
                      return (
                        <label key={f} className="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                          <input type="checkbox" className="mt-1 h-4 w-4 accent-brand-700" checked={features.includes(f)}
                            onChange={(e) => setFeatures((cur) => (e.target.checked ? [...cur, f] : cur.filter((x) => x !== f)))} />
                          <span><span className="flex items-center gap-1.5 font-medium"><info.icon className="h-4 w-4" /> {info.label}</span><span className="text-slate-500">{info.detail}</span></span>
                        </label>
                      );
                    })}
                  </fieldset>
                  <div className="flex flex-wrap items-center gap-2">
                    <Button onClick={() => connect.mutate()} loading={connect.isPending} disabled={features.length === 0}>{c ? 'เชื่อมต่อใหม่กับ Google' : 'เชื่อมต่อกับ Google'}</Button>
                    {c ? (
                      confirm ? (
                        <>
                          <span className="text-red-700">การซิงก์ทั้งหมดจะหยุด และระบบจะลบโทเค็นพร้อมเพิกถอนสิทธิ์ที่ Google</span>
                          <Button variant="danger" size="sm" loading={disconnect.isPending} onClick={() => disconnect.mutate()}>ยืนยันยกเลิกการเชื่อมต่อ</Button>
                          <Button variant="ghost" size="sm" onClick={() => setConfirm(false)}>ยกเลิก</Button>
                        </>
                      ) : <Button variant="secondary" onClick={() => setConfirm(true)}><Unplug className="h-4 w-4" /> ยกเลิกการเชื่อมต่อ</Button>
                    ) : null}
                  </div>
                  <p className="text-xs text-slate-500">ระบบจะพาไปหน้าของ Google เพื่อขออนุญาต — ไม่มีการเก็บรหัสผ่าน Google ของคุณ และโทเค็นถูกเข้ารหัสก่อนบันทึก</p>
                </>
              )}
            </div>
          ) : null}
        </section>
        <p className="mt-4 text-sm text-slate-500">ตั้งค่าการซิงก์ Google Sheets, ไฟล์แนบ และ Webhooks ได้ที่หน้า "ข้อมูล" ของแต่ละโปรเจกต์</p>
      </main>
    </div>
  );
}
