import { Dialog as D } from 'radix-ui';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';

export function Dialog({
  open, onOpenChange, title, description, children, wide = false,
}: { open: boolean; onOpenChange: (o: boolean) => void; title: string; description?: string; children: ReactNode; wide?: boolean }) {
  return (
    <D.Root open={open} onOpenChange={onOpenChange}>
      <D.Portal>
        <D.Overlay className="fixed inset-0 z-40 bg-navy-950/50 backdrop-blur-[2px]" />
        <D.Content
          className={`fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100vw-32px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl dark:bg-navy-900 ${wide ? 'max-w-4xl' : 'max-w-md'}`}
        >
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <D.Title className="text-lg font-semibold">{title}</D.Title>
              {description ? <D.Description className="mt-1 text-sm text-slate-500 dark:text-slate-400">{description}</D.Description> : null}
            </div>
            <D.Close className="rounded-lg p-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label="ปิด">
              <X className="h-5 w-5" />
            </D.Close>
          </div>
          {children}
        </D.Content>
      </D.Portal>
    </D.Root>
  );
}
