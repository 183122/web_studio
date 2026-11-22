import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ImagePlus, Trash2, Upload } from 'lucide-react';
import { api, errorMessage } from '../../lib/api.ts';
import { keys, useAssets } from '../../lib/queries.ts';
import type { Asset } from '../../lib/types.ts';
import { Button } from '../../components/ui/Button.tsx';
import { toast } from '../../components/ui/Toast.tsx';
import { EmptyState, ErrorState, Spinner, formatBytes } from '../../components/ui/misc.tsx';
import { useEditor } from '../store.ts';

const ACCEPT = 'image/jpeg,image/png,image/webp';

export function useUpload(workspaceId: string) {
  const qc = useQueryClient();
  const [uploading, setUploading] = useState(0);
  const upload = async (files: FileList | File[]) => {
    const list = Array.from(files);
    setUploading((n) => n + list.length);
    const results: Asset[] = [];
    for (const file of list) {
      const body = new FormData();
      body.append('file', file);
      try {
        const res = await api<{ data: Asset; meta?: { deduplicated: boolean } }>(`/workspaces/${workspaceId}/assets`, { method: 'POST', body });
        results.push(res.data);
        toast.success(res.meta?.deduplicated ? `${file.name}: มีไฟล์นี้อยู่แล้ว ใช้ไฟล์เดิม` : `อัปโหลด ${file.name} แล้ว`);
      } catch (e) {
        toast.error(`${file.name}: ${errorMessage(e)}`);
      } finally {
        setUploading((n) => n - 1);
      }
    }
    await qc.invalidateQueries({ queryKey: keys.assets(workspaceId) });
    qc.invalidateQueries({ queryKey: keys.workspace(workspaceId) });
    return results;
  };
  return { upload, uploading };
}

/** Grid of workspace assets; used by the Assets panel and the image picker. */
export function AssetGrid({ onPick, compact = false }: { onPick?: (a: Asset) => void; compact?: boolean }) {
  const workspaceId = useEditor((s) => s.workspaceId);
  const canEdit = useEditor((s) => s.canEdit);
  const assets = useAssets(workspaceId);
  const qc = useQueryClient();
  const { upload, uploading } = useUpload(workspaceId);
  const input = useRef<HTMLInputElement>(null);
  const [over, setOver] = useState(false);

  return (
    <div className="space-y-3">
      {canEdit ? (
        <div
          onDragOver={(e) => {
            e.preventDefault();
            setOver(true);
          }}
          onDragLeave={() => setOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setOver(false);
            if (e.dataTransfer.files.length) void upload(e.dataTransfer.files);
          }}
          className={`rounded-xl border-2 border-dashed p-4 text-center text-xs ${over ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-slate-300 dark:border-slate-700'}`}
        >
          <Upload className="mx-auto mb-1 h-5 w-5 text-slate-400" aria-hidden />
          <p className="text-slate-500">ลากไฟล์มาวาง หรือ</p>
          <Button size="sm" variant="secondary" className="mt-2" loading={uploading > 0} onClick={() => input.current?.click()}>
            เลือกไฟล์
          </Button>
          <input
            ref={input}
            type="file"
            accept={ACCEPT}
            multiple
            className="sr-only"
            aria-label="อัปโหลดรูปภาพ"
            data-testid="asset-upload-input"
            onChange={(e) => {
              if (e.target.files?.length) void upload(e.target.files);
              e.target.value = '';
            }}
          />
          <p className="mt-2 text-[11px] text-slate-400">JPEG, PNG, WebP สูงสุด 10 MB · ระบบลบข้อมูล EXIF/ตำแหน่งให้อัตโนมัติ</p>
        </div>
      ) : null}
      {assets.isLoading ? (
        <Spinner />
      ) : assets.error ? (
        <ErrorState message={errorMessage(assets.error)} onRetry={() => assets.refetch()} />
      ) : assets.data!.data.length === 0 ? (
        <EmptyState icon={<ImagePlus className="h-8 w-8" />} title="ยังไม่มีรูปภาพ" />
      ) : (
        <ul className={`grid gap-2 ${compact ? 'grid-cols-4' : 'grid-cols-2'}`}>
          {assets.data!.data.map((a) => (
            <li key={a.id} className="group relative overflow-hidden rounded-lg border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-navy-800">
              <button type="button" onClick={() => onPick?.(a)} className="block w-full" title={a.name} aria-label={onPick ? `เลือก ${a.name}` : a.name}>
                {a.url ? <img src={a.url} alt="" className="aspect-square w-full object-cover" loading="lazy" /> : <div className="flex aspect-square items-center justify-center text-xs text-slate-400">{a.scanStatus}</div>}
              </button>
              {!compact ? (
                <div className="flex items-center justify-between gap-1 px-1.5 py-1 text-[11px]">
                  <span className="truncate" title={a.name}>{a.name}</span>
                  <span className="shrink-0 text-slate-400">{formatBytes(a.sizeBytes)}</span>
                </div>
              ) : null}
              {canEdit && !compact ? (
                <button
                  type="button"
                  aria-label={`ลบ ${a.name}`}
                  className="absolute right-1 top-1 rounded bg-white/90 p-1 text-red-600 opacity-0 shadow group-hover:opacity-100 focus:opacity-100"
                  onClick={async () => {
                    if (!window.confirm(`ลบรูป "${a.name}"? หน้าที่ใช้รูปนี้อยู่จะบันทึกไม่ได้จนกว่าจะเปลี่ยนรูป`)) return;
                    try {
                      await api(`/assets/${a.id}`, { method: 'DELETE' });
                      qc.invalidateQueries({ queryKey: keys.assets(workspaceId) });
                    } catch (e) {
                      toast.error(errorMessage(e));
                    }
                  }}
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function AssetsPanel() {
  const doc = useEditor((s) => s.doc)!;
  const selectedId = useEditor((s) => s.selectedId);
  const dispatch = useEditor((s) => s.dispatch);
  const selected = selectedId ? doc.components[selectedId] : null;
  const target = selected && (selected.type === 'Image' ? 'src' : selected.type === 'Section' ? 'backgroundImage' : selected.type === 'Navbar' ? 'logo' : null);
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500 dark:text-slate-400">
        {target ? 'คลิกรูปเพื่อใช้กับคอมโพเนนต์ที่เลือก' : 'เลือกคอมโพเนนต์รูปภาพ ส่วนของหน้า หรือแถบเมนู แล้วคลิกรูปเพื่อใช้งาน'}
      </p>
      <AssetGrid
        onPick={(a) => {
          if (!selected || !target) return;
          dispatch([{ type: 'component.update', id: selected.id, props: { [target]: a.ref } }], { label: 'เปลี่ยนรูปภาพ' });
        }}
      />
    </div>
  );
}
