import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Eye, History, RotateCcw, Save } from 'lucide-react';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import { keys, useRevisions, type SchemaHead } from '../../lib/queries.ts';
import { Button } from '../../components/ui/Button.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { EmptyState, ErrorState, Spinner, formatDate } from '../../components/ui/misc.tsx';
import { useEditor } from '../store.ts';

const SOURCE_LABEL: Record<string, string> = {
  blank: 'สร้างใหม่', template: 'จากเทมเพลต', editor: 'แก้ไข', restore: 'กู้คืน', api: 'นำเข้า/API', clone: 'คัดลอก', ai: 'AI',
};

export function VersionsPanel() {
  const projectId = useEditor((s) => s.projectId);
  const canEdit = useEditor((s) => s.canEdit);
  const serverRevision = useEditor((s) => s.serverRevision);
  const unsaved = useEditor((s) => s.pending.length > 0 || s.inflight !== null);
  const revisions = useRevisions(projectId, true);
  const qc = useQueryClient();
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  const refreshHead = async () => {
    const head = (await api<Envelope<SchemaHead>>(`/projects/${projectId}/schema`)).data;
    qc.setQueryData(keys.schema(projectId), head);
    useEditor.getState().rebase(head.document, head.revision, false);
    qc.invalidateQueries({ queryKey: keys.revisions(projectId) });
  };

  return (
    <div className="space-y-4 text-sm">
      {canEdit ? (
        <form
          className="space-y-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700"
          onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            try {
              await api(`/projects/${projectId}/revisions`, { method: 'POST', body: { baseRevision: serverRevision, message: message.trim() } });
              setMessage('');
              toast.success('บันทึกเวอร์ชันแล้ว');
              qc.invalidateQueries({ queryKey: keys.revisions(projectId) });
            } catch (err) {
              toast.error(errorMessage(err));
            } finally {
              setBusy(false);
            }
          }}
        >
          <label className="field-label" htmlFor="version-message">ตั้งชื่อเวอร์ชันปัจจุบัน</label>
          <input id="version-message" className="field-input" value={message} onChange={(e) => setMessage(e.target.value)} maxLength={200} placeholder="เช่น พร้อมส่งตรวจ" />
          <Button type="submit" size="sm" className="w-full justify-center" loading={busy} disabled={!message.trim() || unsaved}>
            <Save className="h-4 w-4" /> บันทึกเวอร์ชัน
          </Button>
          {unsaved ? <p className="text-xs text-amber-600">รอให้บันทึกอัตโนมัติเสร็จก่อน</p> : null}
        </form>
      ) : null}

      {revisions.isLoading ? (
        <Spinner />
      ) : revisions.error ? (
        <ErrorState message={errorMessage(revisions.error)} onRetry={() => revisions.refetch()} />
      ) : revisions.data!.data.length === 0 ? (
        <EmptyState icon={<History className="h-8 w-8" />} title="ยังไม่มีเวอร์ชัน" />
      ) : (
        <ol className="space-y-2">
          {revisions.data!.data.map((r) => (
            <li key={r.revision} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
              <div className="flex items-center gap-2">
                <span className="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium dark:bg-navy-800">rev {r.revision}</span>
                <span className="text-xs text-slate-500">{SOURCE_LABEL[r.source] ?? r.source}</span>
                {r.revision === serverRevision ? <span className="ml-auto text-xs text-brand-700 dark:text-brand-300">ปัจจุบัน</span> : null}
              </div>
              {r.message ? <p className="mt-1 font-medium">{r.message}</p> : null}
              <p className="mt-1 text-xs text-slate-500">{formatDate(r.createdAt)} · {r.author?.name ?? 'ระบบ'}</p>
              <div className="mt-2 flex gap-2">
                <Link to={`/app/projects/${projectId}/preview?revision=${r.revision}`} target="_blank" className="inline-flex items-center gap-1 text-xs text-brand-700 hover:underline dark:text-brand-300">
                  <Eye className="h-3.5 w-3.5" /> ดูตัวอย่าง
                </Link>
                {canEdit && r.revision !== serverRevision ? (
                  <button
                    type="button"
                    disabled={unsaved}
                    className="inline-flex items-center gap-1 text-xs text-slate-700 hover:underline disabled:opacity-50 dark:text-slate-200"
                    onClick={async () => {
                      if (!window.confirm(`กู้คืนเป็น rev ${r.revision}? ระบบจะสร้างเวอร์ชันใหม่ (ไม่ลบประวัติ)`)) return;
                      try {
                        await api(`/projects/${projectId}/revisions/${r.revision}/restore`, { method: 'POST', body: { baseRevision: serverRevision } });
                        await refreshHead();
                        toast.success(`กู้คืนจาก rev ${r.revision} แล้ว`);
                      } catch (e) {
                        toast.error(errorMessage(e));
                      }
                    }}
                  >
                    <RotateCcw className="h-3.5 w-3.5" /> กู้คืน
                  </button>
                ) : null}
              </div>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}
