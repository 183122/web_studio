import { useParams, useNavigate } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { ME_KEY, selectWorkspace, useMe } from '../lib/auth.tsx';
import { Button } from '../components/ui/Button.tsx';
import { ErrorState, FullPageSpinner, Logo } from '../components/ui/misc.tsx';

const ROLE: Record<string, string> = { admin: 'ผู้ดูแล', editor: 'ผู้แก้ไข', developer: 'นักพัฒนา', viewer: 'ดูอย่างเดียว' };

export default function AcceptInvite() {
  const { token = '' } = useParams();
  const me = useMe().data;
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const inv = useQuery({ queryKey: ['invite', token], queryFn: async () => (await api<Envelope<{ workspace: string; organization: string; invitedBy: string; role: string; email: string; emailMatches: boolean }>>(`/invitations/${token}`)).data, retry: false });
  if (inv.error) return <div className="p-8"><ErrorState message={errorMessage(inv.error)} /></div>;
  if (!inv.data) return <FullPageSpinner />;
  const d = inv.data;
  return (
    <main className="mx-auto mt-12 max-w-md rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-navy-900">
      <Logo />
      <h1 className="mt-4 text-lg font-semibold">คำเชิญเข้าร่วม Workspace</h1>
      <p className="mt-2">{d.invitedBy} เชิญคุณเข้าร่วม <b>{d.workspace}</b> ({d.organization}) ในบทบาท <b>{ROLE[d.role]}</b></p>
      <p className="mt-1 text-sm text-slate-500">ส่งถึง {d.email}</p>
      {d.emailMatches ? (
        <Button className="mt-4" loading={busy} onClick={async () => {
          setBusy(true);
          try {
            const r = await api<Envelope<{ workspaceId: string }>>(`/invitations/${token}/accept`, { method: 'POST' });
            selectWorkspace(r.data.workspaceId);
            await qc.invalidateQueries({ queryKey: ME_KEY });
            navigate('/app', { replace: true });
          } catch (e) {
            setError(errorMessage(e));
            setBusy(false);
          }
        }}>ตอบรับคำเชิญ</Button>
      ) : <p role="alert" className="mt-4 text-red-700">คุณเข้าสู่ระบบด้วย {me?.user.email} ซึ่งไม่ตรงกับอีเมลที่ได้รับเชิญ กรุณาออกจากระบบแล้วเข้าสู่ระบบด้วยบัญชีที่ถูกต้อง</p>}
      {error ? <p role="alert" className="mt-2 text-red-700">{error}</p> : null}
    </main>
  );
}
