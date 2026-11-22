import { useRef, useState } from 'react';
import { Link } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, FolderOpen, Paperclip, Trash2 } from 'lucide-react';
import { api, errorMessage, type Envelope } from '../../lib/api.ts';
import { Button } from '../ui/Button.tsx';
import { toast } from '../ui/Toast.tsx';
import { formatBytes } from '../ui/misc.tsx';

interface Attachment { id: string; name: string; mime: string; size: number; status: 'available' | 'missing' | 'access_lost'; downloadUrl: string }
const STATUS: Record<string, string> = { missing: 'ไฟล์ถูกลบใน Google Drive', access_lost: 'เข้าถึงไม่ได้ (การเชื่อมต่อ Google ถูกเพิกถอน)' };

/** M24 UI: record attachments stored in the workspace's Google Drive. */
export function Attachments({ projectId, modelId, recordId, env, canEdit }: { projectId: string; modelId: string; recordId: string; env: 'preview' | 'production'; canEdit: boolean }) {
  const qc = useQueryClient();
  const q = env === 'production' ? '?env=production' : '';
  const key = ['attachments', recordId];
  const list = useQuery({ queryKey: key, queryFn: async () => (await api<Envelope<Attachment[]>>(`/projects/${projectId}/data/${modelId}/records/${recordId}/attachments${q}`)).data });
  const drive = useQuery({ queryKey: ['drive', projectId], queryFn: async () => (await api<Envelope<{ folder: { id: string; name: string } | null }>>(`/projects/${projectId}/drive`)).data });
  const [busy, setBusy] = useState(false);
  const [folderName, setFolderName] = useState('');
  const input = useRef<HTMLInputElement>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: key });

  const upload = async (file: File) => {
    setBusy(true);
    const body = new FormData();
    body.append('file', file);
    try {
      await api(`/projects/${projectId}/data/${modelId}/records/${recordId}/attachments${q}`, { method: 'POST', body });
      toast.success('อัปโหลดไปยัง Google Drive แล้ว');
    } catch (e) {
      toast.error(errorMessage(e));
    } finally {
      setBusy(false);
      if (input.current) input.current.value = '';
      refresh();
    }
  };
  const download = async (a: Attachment) => {
    // Check access first so a deleted file or revoked connection is explained instead of a broken download;
    // then let the browser download directly (server sends the filename and streams the file).
    try {
      await api(`${a.downloadUrl.replace(/^\/api\/v1/, '')}?check=1`);
      const link = document.createElement('a');
      link.href = a.downloadUrl;
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch (e) {
      toast.error(errorMessage(e));
    } finally {
      refresh();
    }
  };

  return (
    <section aria-label="ไฟล์แนบ" className="mt-4 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">
      <h3 className="mb-2 flex items-center gap-1.5 font-medium"><Paperclip className="h-4 w-4" /> ไฟล์แนบ (Google Drive)</h3>
      {drive.data && !drive.data.folder ? (
        canEdit ? (
          <form className="mb-2 flex flex-wrap items-end gap-2" onSubmit={(e) => {
            e.preventDefault();
            api(`/projects/${projectId}/drive/folder`, { method: 'PUT', body: { newName: folderName } })
              .then(() => { toast.success('สร้างโฟลเดอร์แล้ว'); qc.invalidateQueries({ queryKey: ['drive', projectId] }); }, (err) => toast.error(errorMessage(err)));
          }}>
            <label className="flex-1"><span className="field-label">สร้างโฟลเดอร์ใน Google Drive สำหรับไฟล์แนบของโปรเจกต์นี้</span>
              <input className="field-input" value={folderName} onChange={(e) => setFolderName(e.target.value)} placeholder="เช่น ไฟล์แนบผู้สมัคร" />
            </label>
            <Button type="submit" size="sm" variant="secondary" disabled={!folderName.trim()}><FolderOpen className="h-4 w-4" /> สร้างโฟลเดอร์</Button>
            <p className="w-full text-xs text-slate-500">ต้องเชื่อมต่อ Google (เปิดฟีเจอร์ Drive) ที่ <Link to="/app/integrations" className="underline">หน้าการเชื่อมต่อ</Link> ก่อน</p>
          </form>
        ) : <p className="text-slate-500">ยังไม่ได้ตั้งค่าโฟลเดอร์ Google Drive</p>
      ) : drive.data?.folder ? <p className="mb-2 text-xs text-slate-500">โฟลเดอร์: {drive.data.folder.name}</p> : null}
      <ul className="space-y-1">
        {(list.data ?? []).map((a) => (
          <li key={a.id} className="flex items-center gap-2" data-testid="attachment">
            <button type="button" className="inline-flex min-w-0 items-center gap-1 truncate text-brand-700 underline" onClick={() => download(a)}><Download className="h-3.5 w-3.5 shrink-0" /> {a.name}</button>
            <span className="shrink-0 text-xs text-slate-500">{formatBytes(a.size)}</span>
            {a.status !== 'available' ? <span className="shrink-0 text-xs text-red-700">{STATUS[a.status]}</span> : null}
            {canEdit ? <Button size="sm" variant="ghost" className="ml-auto" aria-label={`ลบ ${a.name}`} onClick={() => window.confirm(`ลบ ${a.name} ออกจาก Google Drive?`) && api(`/projects/${projectId}/attachments/${a.id}`, { method: 'DELETE' }).then(refresh, (e) => toast.error(errorMessage(e)))}><Trash2 className="h-3.5 w-3.5" /></Button> : null}
          </li>
        ))}
      </ul>
      {canEdit && drive.data?.folder ? (
        <label className="mt-2 inline-flex">
          <span className="sr-only">แนบไฟล์</span>
          <input ref={input} type="file" aria-label="แนบไฟล์" disabled={busy} accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.txt,.csv,.docx,.xlsx,.pptx,.zip"
            onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} className="text-xs" />
        </label>
      ) : null}
      <p className="mt-1 text-xs text-slate-500">สูงสุด 10 MB · PDF, รูปภาพ, Office, TXT/CSV, ZIP</p>
    </section>
  );
}
