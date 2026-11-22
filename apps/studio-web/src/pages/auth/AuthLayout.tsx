import type { ReactNode } from 'react';
import { Logo, ThemeToggle } from '../../components/ui/misc.tsx';

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <div className="flex min-h-full flex-col bg-gradient-to-br from-brand-50 via-white to-slate-100 dark:from-navy-950 dark:via-navy-950 dark:to-navy-900">
      <header className="flex items-center justify-between px-6 py-4">
        <Logo />
        <ThemeToggle />
      </header>
      <main className="flex flex-1 items-center justify-center px-4 pb-16">
        <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white/90 p-8 shadow-xl dark:border-slate-800 dark:bg-navy-900/90">
          <h1 className="text-2xl font-semibold">{title}</h1>
          {subtitle ? <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p> : null}
          <div className="mt-6">{children}</div>
        </div>
      </main>
    </div>
  );
}
