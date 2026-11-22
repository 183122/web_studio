import { Loader2, Moon, Sun } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

export function Spinner({ label = 'กำลังโหลด…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 p-8 text-sm text-slate-500" role="status">
      <Loader2 className="h-5 w-5 animate-spin" aria-hidden />
      <span>{label}</span>
    </div>
  );
}

export function FullPageSpinner() {
  return (
    <div className="flex h-full items-center justify-center">
      <Spinner />
    </div>
  );
}

export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-slate-300 px-6 py-14 text-center dark:border-slate-700">
      {icon ? <div className="text-brand-700 dark:text-brand-300">{icon}</div> : null}
      <h3 className="text-base font-semibold">{title}</h3>
      {description ? <p className="max-w-md text-sm text-slate-500 dark:text-slate-400">{description}</p> : null}
      {action}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
      <p>{message}</p>
      {onRetry ? (
        <button type="button" onClick={onRetry} className="mt-2 font-medium underline">
          ลองอีกครั้ง
        </button>
      ) : null}
    </div>
  );
}

export function Logo({ compact = false }: { compact?: boolean }) {
  return (
    <span className="inline-flex items-center gap-2 font-semibold">
      <svg viewBox="0 0 32 32" className="h-7 w-7" aria-hidden>
        <rect width="32" height="32" rx="8" fill="#0f766e" />
        <path d="M9 22l7-12 7 12h-4l-3-5.5-3 5.5z" fill="#fff" />
      </svg>
      {compact ? null : <span>AI Studio Cloud</span>}
    </span>
  );
}

const THEME_KEY = 'studio-theme';
export function applyStoredTheme() {
  let theme: string | null = null;
  try {
    theme = localStorage.getItem(THEME_KEY);
  } catch {
    /* storage unavailable */
  }
  const dark = theme ? theme === 'dark' : window.matchMedia?.('(prefers-color-scheme: dark)').matches;
  document.documentElement.classList.toggle('dark', !!dark);
}

export function ThemeToggle() {
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    try {
      localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
    } catch {
      /* ignore */
    }
  }, [dark]);
  return (
    <button
      type="button"
      onClick={() => setDark((d) => !d)}
      className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-navy-800"
      aria-label={dark ? 'เปลี่ยนเป็นโหมดสว่าง' : 'เปลี่ยนเป็นโหมดมืด'}
      title={dark ? 'โหมดสว่าง' : 'โหมดมืด'}
    >
      {dark ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
    </button>
  );
}

export function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 ** 3) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  return `${(n / 1024 ** 3).toFixed(1)} GB`;
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '';
  return new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}
