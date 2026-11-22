import { useState } from 'react';
import { FileText, Home, Plus, Trash2 } from 'lucide-react';
import { Button } from '../../components/ui/Button.tsx';
import { Dialog } from '../../components/ui/Dialog.tsx';
import { Field } from '../../components/ui/Field.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { addPage, reportRejected, slugify } from '../actions.ts';
import { useEditor } from '../store.ts';

export function PagesPanel() {
  const doc = useEditor((s) => s.doc)!;
  const pageId = useEditor((s) => s.pageId);
  const setPage = useEditor((s) => s.setPage);
  const dispatch = useEditor((s) => s.dispatch);
  const canEdit = useEditor((s) => s.canEdit);
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [path, setPath] = useState('');

  return (
    <div className="space-y-3">
      <ul className="space-y-1">
        {doc.pages.map((p) => (
          <li key={p.id} className={`group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm ${p.id === pageId ? 'bg-brand-100 text-brand-900 dark:bg-brand-900/40 dark:text-brand-100' : 'hover:bg-slate-100 dark:hover:bg-navy-800'}`}>
            {p.id === doc.settings.homePageId ? <Home className="h-4 w-4 shrink-0" aria-label="หน้าแรก" /> : <FileText className="h-4 w-4 shrink-0" aria-hidden />}
            <button type="button" onClick={() => setPage(p.id)} className="min-w-0 flex-1 text-left" aria-current={p.id === pageId ? 'page' : undefined}>
              <span className="block truncate">{p.name}</span>
              <span className="block truncate text-xs text-slate-500">{p.path}</span>
            </button>
            {canEdit && p.id !== doc.settings.homePageId ? (
              <button
                type="button"
                className="rounded p-1 text-slate-400 opacity-0 hover:text-red-600 group-hover:opacity-100 focus:opacity-100"
                aria-label={`ลบหน้า ${p.name}`}
                onClick={() => {
                  if (!window.confirm(`ลบหน้า "${p.name}"? (เลิกทำได้ด้วย Ctrl+Z)`)) return;
                  if (!dispatch([{ type: 'page.remove', id: p.id }], { label: 'ลบหน้า' })) reportRejected();
                }}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            ) : null}
          </li>
        ))}
      </ul>
      {canEdit ? (
        <Button variant="secondary" size="sm" className="w-full justify-center" onClick={() => { setName(''); setPath(''); setOpen(true); }}>
          <Plus className="h-4 w-4" /> เพิ่มหน้า
        </Button>
      ) : null}
      <Dialog open={open} onOpenChange={setOpen} title="เพิ่มหน้าใหม่">
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            const finalPath = path || `/${slugify(name, doc.pages.length + 1)}`;
            if (doc.pages.some((p) => p.path === finalPath)) {
              toast.error(`มีหน้าที่ใช้ URL ${finalPath} แล้ว`);
              return;
            }
            if (addPage(name.trim(), finalPath)) setOpen(false);
          }}
        >
          <Field label="ชื่อหน้า" value={name} onChange={(e) => setName(e.target.value)} required maxLength={80} autoFocus />
          <Field
            label="URL (path)"
            value={path}
            placeholder={`/${slugify(name, doc.pages.length + 1)}`}
            onChange={(e) => setPath(e.target.value.toLowerCase())}
            pattern="^/([a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*)?$"
            hint="ตัวอักษรภาษาอังกฤษพิมพ์เล็ก ตัวเลข และขีดกลาง เช่น /about-us"
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)}>ยกเลิก</Button>
            <Button type="submit" disabled={!name.trim()}>เพิ่มหน้า</Button>
          </div>
        </form>
      </Dialog>
    </div>
  );
}
