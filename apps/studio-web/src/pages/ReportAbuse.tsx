import { useState } from 'react';
import { api, errorMessage } from '../lib/api.ts';
import { Button } from '../components/ui/Button.tsx';
import { Logo } from '../components/ui/misc.tsx';

/** Public abuse report form for sites hosted on the platform. */
export default function ReportAbuse() {
  const [v, setV] = useState({ url: '', category: 'phishing', details: '', email: '' });
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    setBusy(true);
    setError('');
    try {
      await api('/abuse-reports', { method: 'POST', body: { ...v, email: v.email || null } });
      setSent(true);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <main className="mx-auto mt-10 max-w-lg rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
      <Logo />
      <h1 className="mt-4 text-xl font-semibold">รายงานเว็บไซต์ที่ไม่เหมาะสม</h1>
      {sent ? <p role="status" className="mt-4 text-emerald-700">ได้รับรายงานแล้ว ทีมงานจะตรวจสอบโดยเร็ว ขอบคุณครับ</p> : (
        <form className="mt-4 space-y-3 text-sm" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
          <label className="block"><span className="field-label">ที่อยู่เว็บไซต์ (URL)</span><input className="field-input" required value={v.url} onChange={(e) => setV({ ...v, url: e.target.value })} placeholder="https://…" /></label>
          <label className="block"><span className="field-label">ประเภท</span>
            <select className="field-input" value={v.category} onChange={(e) => setV({ ...v, category: e.target.value })}>
              <option value="phishing">หลอกลวง/ฟิชชิง</option><option value="malware">มัลแวร์</option><option value="spam">สแปม</option><option value="illegal">เนื้อหาผิดกฎหมาย</option><option value="other">อื่น ๆ</option>
            </select>
          </label>
          <label className="block"><span className="field-label">รายละเอียด</span><textarea className="field-input" rows={4} required minLength={10} value={v.details} onChange={(e) => setV({ ...v, details: e.target.value })} /></label>
          <label className="block"><span className="field-label">อีเมลติดต่อกลับ (ไม่บังคับ)</span><input className="field-input" type="email" value={v.email} onChange={(e) => setV({ ...v, email: e.target.value })} /></label>
          {error ? <p role="alert" className="text-red-700">{error}</p> : null}
          <Button type="submit" loading={busy}>ส่งรายงาน</Button>
        </form>
      )}
    </main>
  );
}
