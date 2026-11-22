import { create } from 'zustand';
import { CheckCircle2, AlertTriangle, Info, X } from 'lucide-react';

type Kind = 'success' | 'error' | 'info';
interface ToastItem { id: number; kind: Kind; message: string }
interface ToastState { items: ToastItem[]; push: (kind: Kind, message: string) => void; dismiss: (id: number) => void }

let seq = 0;
export const useToasts = create<ToastState>((set, get) => ({
  items: [],
  push: (kind, message) => {
    const id = ++seq;
    set({ items: [...get().items.slice(-3), { id, kind, message }] });
    setTimeout(() => get().dismiss(id), kind === 'error' ? 7000 : 3500);
  },
  dismiss: (id) => set({ items: get().items.filter((t) => t.id !== id) }),
}));

export const toast = {
  success: (m: string) => useToasts.getState().push('success', m),
  error: (m: string) => useToasts.getState().push('error', m),
  info: (m: string) => useToasts.getState().push('info', m),
};

const ICON = { success: CheckCircle2, error: AlertTriangle, info: Info };
const TONE = {
  success: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100',
  error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100',
  info: 'border-slate-200 bg-white text-slate-900 dark:border-slate-700 dark:bg-navy-900 dark:text-slate-100',
};

export function Toaster() {
  const { items, dismiss } = useToasts();
  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[min(380px,calc(100vw-32px))] flex-col gap-2" aria-live="polite" role="status">
      {items.map((t) => {
        const Icon = ICON[t.kind];
        return (
          <div key={t.id} className={`pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg ${TONE[t.kind]}`}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <p className="flex-1">{t.message}</p>
            <button type="button" onClick={() => dismiss(t.id)} aria-label="ปิดการแจ้งเตือน" className="opacity-60 hover:opacity-100">
              <X className="h-4 w-4" />
            </button>
          </div>
        );
      })}
    </div>
  );
}
