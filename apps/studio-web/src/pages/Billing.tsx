import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CreditCard, FileText, TriangleAlert } from 'lucide-react';
import { api, ApiError, errorMessage, type Envelope } from '../lib/api.ts';
import { useCurrentWorkspace } from '../lib/auth.tsx';
import { Button } from '../components/ui/Button.tsx';
import { Field } from '../components/ui/Field.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { ErrorState, Logo, Spinner, ThemeToggle, formatBytes, formatDate } from '../components/ui/misc.tsx';

interface PlanRow { code: string; name: string; price: number; currency: string; trialDays: number; entitlements: Record<string, number>; amounts: { subtotal_minor: number; vat_minor: number; total_minor: number } }
interface InvoiceRow { id: string; number: string | null; kind: string; status: 'open' | 'paid' | 'void' | 'uncollectible'; total: number; currency: string; periodStart: string; periodEnd: string; paidAt: string | null; createdAt: string; planName: string }
interface BillingData {
  organization: { id: string; name: string; status: string; trialUsed: boolean };
  currentPlan: string;
  plans: PlanRow[];
  subscription: { plan: string; status: string; currentPeriodEnd: string | null; cancelAtPeriodEnd: boolean; nextRetryAt: string | null; dunningAttempts: number } | null;
  usage: Record<string, { used: number; limit: number }>;
  profile: { legalName: string; taxId: string | null; branch: string | null; address: string; email: string } | null;
  invoices: InvoiceRow[];
  provider: { name: string; testMode: boolean; activated: boolean };
}

const baht = (minor: number) => (minor / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const SUB_STATUS: Record<string, string> = { trialing: 'ทดลองใช้', active: 'ใช้งานอยู่', past_due: 'ชำระเงินไม่สำเร็จ — กำลังลองเรียกเก็บใหม่', canceled: 'ยกเลิกแล้ว', expired: 'หมดอายุ' };
const INV_STATUS: Record<string, string> = { open: 'รอชำระ', paid: 'ชำระแล้ว', uncollectible: 'เรียกเก็บไม่ได้', void: 'ยกเลิก' };
const USAGE_LABEL: Record<string, string> = { projects: 'โปรเจกต์', records: 'ข้อมูล (records)', storage_bytes: 'พื้นที่จัดเก็บ', custom_domains: 'โดเมนของตัวเอง', members: 'สมาชิก', ai_credits: 'เครดิต AI เดือนนี้', runtime_requests: 'คำขอเว็บไซต์เดือนนี้', build_minutes: 'นาทีการเผยแพร่เดือนนี้' };
const ENT_LABEL: Array<[string, string, (v: number) => string]> = [
  ['max_projects', 'โปรเจกต์', String], ['max_records', 'ข้อมูล', (v) => v.toLocaleString('th-TH')], ['storage_bytes', 'พื้นที่', formatBytes],
  ['max_custom_domains', 'โดเมนของตัวเอง', String], ['max_members', 'สมาชิก', String], ['ai_credits_monthly', 'เครดิต AI/เดือน', (v) => v.toLocaleString('th-TH')],
];

/** Organization billing (M03): plan, usage, upgrade, tax details, invoices. */
export default function Billing() {
  const ws = useCurrentWorkspace();
  const orgId = ws?.organization?.id;
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const key = ['billing', orgId];
  const returning = params.get('checkout') === 'return';
  const billing = useQuery({
    queryKey: key,
    queryFn: async () => (await api<Envelope<BillingData>>(`/organizations/${orgId}/billing`)).data,
    enabled: !!orgId,
    // After returning from checkout, poll until the provider's webhook confirms (or fails) the payment.
    refetchInterval: (q) => (returning && q.state.data?.invoices[0]?.status === 'open' ? 2000 : false),
  });
  const refresh = () => qc.invalidateQueries({ queryKey: key });
  const checkout = useMutation({
    mutationFn: async (plan: string) => (await api<Envelope<{ url: string }>>(`/organizations/${orgId}/billing/checkout`, { method: 'POST', body: { plan } })).data.url,
    onSuccess: (url) => window.location.assign(url),
    onError: (e) => toast.error(errorMessage(e)),
  });
  const act = (path: string, body?: unknown, ok?: string) => api(`/organizations/${orgId}/billing/${path}`, { method: 'POST', body }).then(() => { if (ok) toast.success(ok); refresh(); }, (e) => toast.error(errorMessage(e)));

  const b = billing.data;
  const latest = b?.invoices[0];
  useEffect(() => {
    if (returning && latest && latest.status !== 'open') {
      if (latest.status === 'paid') toast.success('ชำระเงินสำเร็จ แพ็กเกจของคุณถูกอัปเดตแล้ว');
      setParams({}, { replace: true });
    }
  }, [returning, latest, setParams]);

  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-navy-950/85">
        <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3 sm:px-6">
          <Link to="/app" className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="กลับไปแดชบอร์ด"><ArrowLeft className="h-4 w-4" /></Link>
          <Logo compact />
          <h1 className="font-semibold">การเรียกเก็บเงิน · {ws?.organization?.name}</h1>
          <span className="ml-auto" />
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
        {billing.isLoading ? <Spinner /> : billing.error ? (
          <ErrorState message={billing.error instanceof ApiError && billing.error.status === 403 ? 'เฉพาะเจ้าของ ผู้ดูแล หรือผู้ดูแลการเงินขององค์กรเท่านั้น' : errorMessage(billing.error)} />
        ) : b ? (
          <>
            {b.provider.testMode ? <p role="note" className="rounded-xl bg-sky-50 p-3 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-100">โหมดทดสอบ: การชำระเงินเป็นการจำลอง ไม่มีการตัดเงินจริง — การเปิดใช้ผู้ให้บริการชำระเงินจริงต้องได้รับอนุมัติก่อน</p> : null}
            {returning && latest?.status === 'open' ? <p role="status" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">กำลังรอการยืนยันการชำระเงินจากผู้ให้บริการ… (แพ็กเกจจะเปลี่ยนเมื่อได้รับการยืนยันเท่านั้น)</p> : null}
            {b.subscription?.status === 'past_due' ? (
              <p role="alert" className="flex items-start gap-2 rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-100"><TriangleAlert className="h-4 w-4 shrink-0" />
                ชำระค่าบริการรอบล่าสุดไม่สำเร็จ ระบบจะลองใหม่{b.subscription.nextRetryAt ? ` วันที่ ${formatDate(b.subscription.nextRetryAt)}` : ''} หากไม่สำเร็จ แพ็กเกจจะถูกปรับเป็น Free (ข้อมูลไม่ถูกลบ)</p>
            ) : null}

            <section aria-labelledby="cur-h" className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
              <h2 id="cur-h" className="flex items-center gap-2 text-lg font-semibold"><CreditCard className="h-5 w-5" /> แพ็กเกจปัจจุบัน: <span data-testid="current-plan">{b.plans.find((p) => p.code === b.currentPlan)?.name}</span></h2>
              {b.subscription ? (
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                  สถานะ: <span data-testid="subscription-status">{SUB_STATUS[b.subscription.status] ?? b.subscription.status}</span>
                  {b.subscription.currentPeriodEnd && ['active', 'trialing', 'past_due'].includes(b.subscription.status) ? ` · ${b.subscription.cancelAtPeriodEnd ? 'สิ้นสุด' : b.subscription.status === 'trialing' ? 'ทดลองถึง' : 'ต่ออายุ'} ${formatDate(b.subscription.currentPeriodEnd)}` : ''}
                </p>
              ) : null}
              {b.subscription && ['active', 'past_due'].includes(b.subscription.status) ? (
                <div className="mt-3">
                  {b.subscription.cancelAtPeriodEnd
                    ? <Button size="sm" variant="secondary" onClick={() => act('cancel', { resume: true }, 'ยกเลิกการยกเลิกแล้ว แพ็กเกจจะต่ออายุตามปกติ')}>ใช้ต่อ (ไม่ยกเลิก)</Button>
                    : <Button size="sm" variant="ghost" onClick={() => window.confirm('ยกเลิกเมื่อสิ้นรอบบิล? หลังจากนั้นจะเป็นแพ็กเกจ Free (ข้อมูลไม่ถูกลบ)') && act('cancel', undefined, 'จะยกเลิกเมื่อสิ้นรอบบิล')}>ยกเลิกแพ็กเกจ</Button>}
                </div>
              ) : null}
              <dl className="mt-4 grid gap-3 sm:grid-cols-3">
                {Object.entries(b.usage).map(([k, u]) => {
                  const pct = u.limit > 0 ? Math.min(100, Math.round((u.used / u.limit) * 100)) : 100;
                  const fmt = (v: number) => (k === 'storage_bytes' ? formatBytes(v) : v.toLocaleString('th-TH'));
                  return (
                    <div key={k} className="rounded-xl bg-slate-50 p-3 dark:bg-navy-800">
                      <dt className="text-xs text-slate-500">{USAGE_LABEL[k] ?? k}</dt>
                      <dd className={`text-sm font-medium ${pct >= 100 ? 'text-red-700' : pct >= 80 ? 'text-amber-700' : ''}`}>{fmt(u.used)} / {fmt(u.limit)}</dd>
                      <div className="mt-1 h-1.5 rounded bg-slate-200 dark:bg-navy-950" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={USAGE_LABEL[k] ?? k}>
                        <div className={`h-1.5 rounded ${pct >= 100 ? 'bg-red-600' : pct >= 80 ? 'bg-amber-500' : 'bg-brand-600'}`} style={{ width: `${pct}%` }} />
                      </div>
                    </div>
                  );
                })}
              </dl>
            </section>

            <section aria-labelledby="plans-h">
              <h2 id="plans-h" className="mb-3 text-lg font-semibold">แพ็กเกจ</h2>
              <div className="grid gap-4 md:grid-cols-3">
                {b.plans.map((p) => {
                  const current = p.code === b.currentPlan;
                  return (
                    <div key={p.code} className={`rounded-2xl border p-5 ${current ? 'border-brand-600 ring-1 ring-brand-600' : 'border-slate-200 dark:border-slate-800'} bg-white dark:bg-navy-900`} data-testid={`plan-${p.code}`}>
                      <h3 className="font-semibold">{p.name}</h3>
                      <p className="mt-1 text-2xl font-semibold">{p.price === 0 ? 'ฟรี' : `฿${baht(p.price)}`}<span className="text-sm font-normal text-slate-500">{p.price ? ' /เดือน' : ''}</span></p>
                      {p.price ? <p className="text-xs text-slate-500">+ VAT 7% = ฿{baht(p.amounts.total_minor)}</p> : null}
                      <ul className="mt-3 space-y-1 text-sm">
                        {ENT_LABEL.map(([k, label, f]) => p.entitlements[k] !== undefined ? <li key={k}>{label}: {f(p.entitlements[k])}</li> : null)}
                      </ul>
                      <div className="mt-4 flex flex-wrap gap-2">
                        {current ? <span className="text-sm text-brand-700">แพ็กเกจปัจจุบัน</span> : p.price > 0 ? (
                          <>
                            <Button size="sm" loading={checkout.isPending && checkout.variables === p.code} onClick={() => checkout.mutate(p.code)}>อัปเกรดเป็น {p.name}</Button>
                            {p.trialDays > 0 && !b.organization.trialUsed && !b.subscription ? <Button size="sm" variant="secondary" onClick={() => act('trial', { plan: p.code }, `เริ่มทดลองใช้ ${p.name} ${p.trialDays} วันแล้ว`)}>ทดลองฟรี {p.trialDays} วัน</Button> : null}
                          </>
                        ) : null}
                      </div>
                    </div>
                  );
                })}
              </div>
              <p className="mt-2 text-xs text-slate-500">ราคายังไม่รวมภาษีมูลค่าเพิ่ม 7% · อัปเกรดแล้วเริ่มรอบบิลใหม่ทันที · ดาวน์เกรด/ยกเลิกมีผลเมื่อสิ้นรอบ และข้อมูลไม่ถูกลบ</p>
            </section>

            <ProfileForm orgId={b.organization.id} profile={b.profile} onSaved={refresh} />

            <section aria-labelledby="inv-h" className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
              <h2 id="inv-h" className="mb-3 flex items-center gap-2 text-lg font-semibold"><FileText className="h-5 w-5" /> ใบแจ้งหนี้และใบเสร็จ</h2>
              {b.invoices.length === 0 ? <p className="text-sm text-slate-500">ยังไม่มีรายการ</p> : (
                <table className="w-full text-left text-sm">
                  <thead className="text-slate-500"><tr><th className="py-1">เลขที่</th><th>แพ็กเกจ</th><th>รอบบิล</th><th className="pr-6 text-right">ยอดรวม</th><th>สถานะ</th><th /></tr></thead>
                  <tbody>
                    {b.invoices.map((i) => (
                      <tr key={i.id} className="border-t border-slate-100 dark:border-slate-800" data-testid="invoice-row">
                        <td className="py-2">{i.number ?? '—'}</td><td>{i.planName}</td>
                        <td>{formatDate(i.periodStart)} – {formatDate(i.periodEnd)}</td>
                        <td className="pr-6 text-right">฿{baht(i.total)}</td>
                        <td className={i.status === 'paid' ? 'text-emerald-700' : i.status === 'open' ? 'text-amber-700' : 'text-red-700'}>{INV_STATUS[i.status]}</td>
                        <td><a className="text-brand-700 underline" href={`/api/v1/organizations/${b.organization.id}/invoices/${i.id}/print`} target="_blank" rel="noreferrer">{i.status === 'paid' ? 'ใบกำกับภาษี' : 'ดู'}</a></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </section>
          </>
        ) : null}
      </main>
    </div>
  );
}

function ProfileForm({ orgId, profile, onSaved }: { orgId: string; profile: BillingData['profile']; onSaved: () => void }) {
  const [v, setV] = useState({ legalName: profile?.legalName ?? '', taxId: profile?.taxId ?? '', branch: profile?.branch ?? 'สำนักงานใหญ่', address: profile?.address ?? '', email: profile?.email ?? '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      await api(`/organizations/${orgId}/billing/profile`, { method: 'PUT', body: { ...v, taxId: v.taxId || null } });
      toast.success('บันทึกข้อมูลใบกำกับภาษีแล้ว');
      onSaved();
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries((e.details.fields ?? {}) as Record<string, string[]>).map(([k, m]) => [k, m[0]])));
      toast.error(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const set = (k: keyof typeof v) => (e: React.ChangeEvent<HTMLInputElement>) => setV((s) => ({ ...s, [k]: e.target.value }));
  return (
    <section aria-labelledby="tax-h" className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
      <h2 id="tax-h" className="text-lg font-semibold">ข้อมูลสำหรับออกใบกำกับภาษี</h2>
      <p className="mb-3 text-sm text-slate-500">ข้อมูลนี้จะถูกบันทึกลงในใบแจ้งหนี้ที่ออกหลังจากนี้ (ใบที่ออกไปแล้วไม่เปลี่ยน)</p>
      <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void save(); }}>
        <Field label="ชื่อนิติบุคคล / ชื่อผู้ซื้อ" value={v.legalName} onChange={set('legalName')} error={errors.legalName} />
        <Field label="เลขประจำตัวผู้เสียภาษี (13 หลัก)" inputMode="numeric" value={v.taxId} onChange={set('taxId')} error={errors.taxId} />
        <Field label="สาขา" value={v.branch} onChange={set('branch')} error={errors.branch} />
        <Field label="อีเมลรับเอกสาร" type="email" value={v.email} onChange={set('email')} error={errors.email} />
        <div className="sm:col-span-2"><Field label="ที่อยู่" value={v.address} onChange={set('address')} error={errors.address} /></div>
        <div><Button type="submit" size="sm" loading={busy}>บันทึก</Button></div>
      </form>
    </section>
  );
}
