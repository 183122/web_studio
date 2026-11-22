import { useState } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { Button } from '../components/ui/Button.tsx';
import { ErrorState, FullPageSpinner, Logo } from '../components/ui/misc.tsx';

/** Hosted checkout of the sandbox payment provider. Simulates the customer's payment; no money moves. */
export default function TestCheckout() {
  const { ref = '' } = useParams();
  const [params] = useSearchParams();
  const inv = useQuery({ queryKey: ['test-checkout', ref], queryFn: async () => (await api<Envelope<{ organization: string; planName: string; total: number; subtotal: number; vat: number; status: string }>>(`/billing/test-checkout/${ref}`)).data, retry: false });
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState('');
  const back = params.get('return') ?? '/app/billing';
  const pay = async (outcome: string) => {
    setBusy(outcome);
    setError('');
    try {
      await api(`/billing/test-checkout/${ref}/complete`, { method: 'POST', body: { outcome } });
      window.location.assign(back.startsWith(window.location.origin) || back.startsWith('/') ? back : '/app/billing');
    } catch (e) {
      setError(errorMessage(e));
      setBusy(null);
    }
  };
  if (inv.error) return <div className="p-8"><ErrorState message={errorMessage(inv.error)} /></div>;
  if (!inv.data) return <FullPageSpinner />;
  const d = inv.data;
  return (
    <main className="mx-auto mt-12 max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-navy-900">
      <Logo />
      <p className="mt-4 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-900">หน้าชำระเงินจำลอง (โหมดทดสอบ) — ไม่มีการตัดเงินจริง</p>
      <h1 className="mt-4 text-lg font-semibold">ชำระค่าแพ็กเกจ {d.planName}</h1>
      <p className="text-sm text-slate-500">{d.organization}</p>
      <dl className="mt-3 space-y-1 text-sm">
        <div className="flex justify-between"><dt>ค่าบริการ</dt><dd>฿{(d.subtotal / 100).toFixed(2)}</dd></div>
        <div className="flex justify-between"><dt>VAT 7%</dt><dd>฿{(d.vat / 100).toFixed(2)}</dd></div>
        <div className="flex justify-between font-semibold"><dt>รวม</dt><dd>฿{(d.total / 100).toFixed(2)}</dd></div>
      </dl>
      {d.status !== 'open' ? <p className="mt-4 text-sm text-slate-500">รายการนี้ปิดไปแล้ว</p> : (
        <div className="mt-5 grid gap-2">
          <Button loading={busy === 'success'} disabled={!!busy} onClick={() => pay('success')}>ชำระด้วยบัตรทดสอบ (สำเร็จ)</Button>
          <Button variant="secondary" loading={busy === 'success_decline_renewals'} disabled={!!busy} onClick={() => pay('success_decline_renewals')}>ชำระสำเร็จ แต่บัตรจะถูกปฏิเสธในรอบถัดไป</Button>
          <Button variant="ghost" loading={busy === 'decline'} disabled={!!busy} onClick={() => pay('decline')}>บัตรถูกปฏิเสธ</Button>
        </div>
      )}
      {error ? <p role="alert" className="mt-3 text-sm text-red-700">{error}</p> : null}
    </main>
  );
}
