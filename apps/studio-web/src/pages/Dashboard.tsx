import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { DropdownMenu } from 'radix-ui';
import {
  Archive, ArchiveRestore, Copy, LayoutTemplate, LogOut, MoreHorizontal, Pencil, Plus, RotateCcw, Search, Star, Trash2, FolderKanban, MailWarning, Settings,
} from 'lucide-react';
import { PageRenderer } from '@studio/renderer';
import { api, errorMessage, type Envelope } from '../lib/api.ts';
import { useAuthActions, useCurrentWorkspace, useMe } from '../lib/auth.tsx';
import { useProjectMutation, useProjects, useTemplate, useTemplates, useWorkspace, type ProjectFilters } from '../lib/queries.ts';
import type { Project } from '../lib/types.ts';
import { Button } from '../components/ui/Button.tsx';
import { Dialog } from '../components/ui/Dialog.tsx';
import { Field } from '../components/ui/Field.tsx';
import { toast } from '../components/ui/Toast.tsx';
import { EmptyState, ErrorState, Logo, Spinner, ThemeToggle, formatBytes, formatDate } from '../components/ui/misc.tsx';

const STATUS_TABS: Array<{ value: ProjectFilters['status']; label: string }> = [
  { value: 'active', label: 'ใช้งานอยู่' },
  { value: 'archived', label: 'เก็บถาวร' },
  { value: 'trashed', label: 'ถังขยะ' },
];

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export default function Dashboard() {
  const me = useMe().data!;
  const ws = useCurrentWorkspace();
  const summary = useWorkspace(ws?.id);
  const { logout } = useAuthActions();
  const navigate = useNavigate();
  const [searchInput, setSearchInput] = useState('');
  const search = useDebounced(searchInput.trim(), 300);
  const [filters, setFilters] = useState<Omit<ProjectFilters, 'search'>>({ status: 'active', favorites: false, sort: 'updated' });
  const projects = useProjects(ws?.id, { ...filters, search });
  const [createOpen, setCreateOpen] = useState(false);
  const canEdit = ws?.role !== 'viewer';
  useEffect(() => {
    // First visit after sign-up: open the create dialog once.
    try {
      if (sessionStorage.getItem('studio-welcome') === '1') {
        sessionStorage.removeItem('studio-welcome');
        setCreateOpen(true);
      }
    } catch {
      /* storage unavailable */
    }
  }, []);

  if (!ws) {
    return <div className="p-8"><ErrorState message="บัญชีนี้ยังไม่มี Workspace" /></div>;
  }

  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-navy-950/85">
        <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6">
          <Logo />
          <span className="hidden truncate text-sm text-slate-500 sm:inline">/ {ws.organization?.name} · {ws.name}</span>
          <div className="ml-auto flex items-center gap-2">
            {summary.data ? (
              <span className="hidden rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600 md:inline dark:bg-navy-800 dark:text-slate-300">
                แผน {summary.data.organization.plan?.name ?? '-'} · โปรเจกต์ {summary.data.usage.projects.used}/{summary.data.usage.projects.limit} · พื้นที่ {formatBytes(summary.data.usage.storage_bytes.used)}/{formatBytes(summary.data.usage.storage_bytes.limit)}
              </span>
            ) : null}
            {me.user.isPlatformAdmin ? (
              <Link to="/app/admin/settings" className="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-sm hover:bg-slate-100 dark:hover:bg-navy-800">
                <Settings className="h-4 w-4" /> ตั้งค่าระบบ
              </Link>
            ) : null}
            <ThemeToggle />
            <span className="hidden text-sm sm:inline">{me.user.name}</span>
            <Button
              variant="ghost"
              size="icon"
              aria-label="ออกจากระบบ"
              title="ออกจากระบบ"
              onClick={async () => {
                await logout();
                navigate('/login');
              }}
            >
              <LogOut className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        {!me.user.emailVerified ? <VerifyBanner /> : null}

        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold">โปรเจกต์ของฉัน</h1>
            <p className="text-sm text-slate-500 dark:text-slate-400">สร้าง แก้ไข และจัดการเว็บไซต์/แอปของคุณ</p>
          </div>
          {canEdit ? (
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> สร้างโปรเจกต์
            </Button>
          ) : null}
        </div>

        <div className="mt-6 flex flex-wrap items-center gap-3">
          <div className="relative min-w-56 flex-1 sm:max-w-sm">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="ค้นหาโปรเจกต์…"
              aria-label="ค้นหาโปรเจกต์"
              className="field-input h-10 pl-9"
            />
          </div>
          <div role="tablist" aria-label="สถานะโปรเจกต์" className="flex rounded-lg border border-slate-300 p-0.5 dark:border-slate-700">
            {STATUS_TABS.map((t) => (
              <button
                key={t.value}
                role="tab"
                type="button"
                aria-selected={filters.status === t.value}
                onClick={() => setFilters((f) => ({ ...f, status: t.value }))}
                className={`rounded-md px-3 py-1.5 text-sm ${filters.status === t.value ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-navy-800'}`}
              >
                {t.label}
              </button>
            ))}
          </div>
          <button
            type="button"
            aria-pressed={filters.favorites}
            onClick={() => setFilters((f) => ({ ...f, favorites: !f.favorites }))}
            className={`inline-flex h-10 items-center gap-1.5 rounded-lg border px-3 text-sm ${filters.favorites ? 'border-amber-400 bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' : 'border-slate-300 dark:border-slate-700'}`}
          >
            <Star className={`h-4 w-4 ${filters.favorites ? 'fill-amber-400 text-amber-500' : ''}`} /> รายการโปรด
          </button>
          <label className="ml-auto flex items-center gap-2 text-sm">
            <span className="text-slate-500">เรียงตาม</span>
            <select
              className="field-input h-10 w-auto"
              value={filters.sort}
              onChange={(e) => setFilters((f) => ({ ...f, sort: e.target.value as ProjectFilters['sort'] }))}
            >
              <option value="updated">แก้ไขล่าสุด</option>
              <option value="created">สร้างล่าสุด</option>
              <option value="name">ชื่อ</option>
            </select>
          </label>
        </div>

        <section className="mt-6" aria-live="polite">
          {projects.isLoading ? (
            <Spinner />
          ) : projects.error ? (
            <ErrorState message={errorMessage(projects.error)} onRetry={() => projects.refetch()} />
          ) : projects.data!.data.length === 0 ? (
            <EmptyState
              icon={<FolderKanban className="h-10 w-10" />}
              title={search ? 'ไม่พบโปรเจกต์ที่ค้นหา' : filters.status === 'trashed' ? 'ถังขยะว่างเปล่า' : filters.status === 'archived' ? 'ยังไม่มีโปรเจกต์ที่เก็บถาวร' : 'ยังไม่มีโปรเจกต์'}
              description={filters.status === 'active' && !search ? 'เริ่มต้นจากเทมเพลตสำเร็จรูป หรือหน้าว่าง แล้วแก้ไขด้วยการลากวาง' : undefined}
              action={filters.status === 'active' && !search && canEdit ? <Button onClick={() => setCreateOpen(true)}><Plus className="h-4 w-4" /> สร้างโปรเจกต์แรก</Button> : undefined}
            />
          ) : (
            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {projects.data!.data.map((p) => (
                <ProjectCard key={p.id} project={p} wsId={ws.id} canEdit={canEdit} isAdmin={ws.role === 'admin'} />
              ))}
            </ul>
          )}
        </section>
      </main>

      <CreateProjectDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        wsId={ws.id}
      />
    </div>
  );
}

function VerifyBanner() {
  const [sent, setSent] = useState(false);
  return (
    <div className="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
      <MailWarning className="h-4 w-4" aria-hidden />
      <span className="flex-1">กรุณายืนยันอีเมลของคุณ (จำเป็นก่อนการเผยแพร่เว็บไซต์)</span>
      <Button
        size="sm"
        variant="secondary"
        disabled={sent}
        onClick={async () => {
          try {
            await api('/auth/email/verification-notification', { method: 'POST' });
            setSent(true);
            toast.success('ส่งอีเมลยืนยันแล้ว');
          } catch (e) {
            toast.error(errorMessage(e));
          }
        }}
      >
        {sent ? 'ส่งแล้ว' : 'ส่งอีเมลยืนยันอีกครั้ง'}
      </Button>
    </div>
  );
}

const GRADIENTS = ['from-brand-600 to-navy-700', 'from-sky-600 to-indigo-700', 'from-amber-500 to-rose-600', 'from-emerald-500 to-teal-700', 'from-fuchsia-600 to-purple-800'];

function ProjectCard({ project, wsId, canEdit, isAdmin }: { project: Project; wsId: string; canEdit: boolean; isAdmin: boolean }) {
  const navigate = useNavigate();
  const [renaming, setRenaming] = useState(false);
  const gradient = GRADIENTS[project.id.charCodeAt(project.id.length - 1) % GRADIENTS.length];
  const run = useProjectMutation(async (fn: () => Promise<unknown>) => fn(), wsId);
  const act = (fn: () => Promise<unknown>, ok: string) =>
    run.mutate(fn, { onSuccess: () => toast.success(ok), onError: (e) => toast.error(errorMessage(e)) });

  const open = () => !project.isTrashed && navigate(`/app/projects/${project.id}`);

  return (
    <li className="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md dark:border-slate-800 dark:bg-navy-900">
      <button type="button" onClick={open} disabled={project.isTrashed} className={`flex h-32 items-end bg-gradient-to-br p-4 text-left text-white ${gradient} disabled:cursor-default disabled:opacity-60`} aria-label={`เปิดโปรเจกต์ ${project.name}`}>
        <span className="text-3xl font-semibold opacity-90">{project.name.slice(0, 2)}</span>
      </button>
      <div className="flex flex-1 flex-col gap-1 p-4">
        <div className="flex items-start gap-2">
          <h2 className="flex-1 truncate font-semibold" title={project.name}>
            {project.isTrashed ? project.name : <Link to={`/app/projects/${project.id}`} className="hover:underline">{project.name}</Link>}
          </h2>
          {!project.isTrashed ? (
            <button
              type="button"
              aria-pressed={project.isFavorite}
              aria-label={project.isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด'}
              onClick={() => act(() => api(`/projects/${project.id}/favorite`, { method: project.isFavorite ? 'DELETE' : 'PUT' }), project.isFavorite ? 'นำออกจากรายการโปรดแล้ว' : 'เพิ่มในรายการโปรดแล้ว')}
              className="rounded p-1 text-slate-400 hover:text-amber-500"
            >
              <Star className={`h-4 w-4 ${project.isFavorite ? 'fill-amber-400 text-amber-500' : ''}`} />
            </button>
          ) : null}
          <DropdownMenu.Root>
            <DropdownMenu.Trigger className="rounded p-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-navy-800" aria-label={`ตัวเลือกของ ${project.name}`}>
              <MoreHorizontal className="h-4 w-4" />
            </DropdownMenu.Trigger>
            <DropdownMenu.Portal>
              <DropdownMenu.Content align="end" sideOffset={4} className="z-50 min-w-48 rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-xl dark:border-slate-700 dark:bg-navy-900">
                {project.isTrashed ? (
                  isAdmin ? <MenuItem icon={RotateCcw} label="กู้คืน" onSelect={() => act(() => api(`/projects/${project.id}/restore`, { method: 'POST' }), 'กู้คืนโปรเจกต์แล้ว')} /> : <div className="px-3 py-2 text-slate-400">ไม่มีการดำเนินการ</div>
                ) : (
                  <>
                    <MenuItem icon={Pencil} label="เปิดตัวแก้ไข" onSelect={open} />
                    {canEdit ? <MenuItem icon={Pencil} label="เปลี่ยนชื่อ" onSelect={() => setRenaming(true)} /> : null}
                    {canEdit ? <MenuItem icon={Copy} label="ทำสำเนา" onSelect={() => act(() => api(`/projects/${project.id}/duplicate`, { method: 'POST' }), 'ทำสำเนาแล้ว')} /> : null}
                    {canEdit ? (
                      project.status === 'archived'
                        ? <MenuItem icon={ArchiveRestore} label="นำออกจากที่เก็บถาวร" onSelect={() => act(() => api(`/projects/${project.id}/unarchive`, { method: 'POST' }), 'นำออกจากที่เก็บถาวรแล้ว')} />
                        : <MenuItem icon={Archive} label="เก็บถาวร" onSelect={() => act(() => api(`/projects/${project.id}/archive`, { method: 'POST' }), 'เก็บถาวรแล้ว')} />
                    ) : null}
                    {isAdmin ? (
                      <MenuItem
                        icon={Trash2}
                        label="ย้ายไปถังขยะ"
                        danger
                        onSelect={() => {
                          if (window.confirm(`ย้าย "${project.name}" ไปถังขยะ? (กู้คืนได้ภายหลัง)`)) act(() => api(`/projects/${project.id}`, { method: 'DELETE' }), 'ย้ายไปถังขยะแล้ว');
                        }}
                      />
                    ) : null}
                  </>
                )}
              </DropdownMenu.Content>
            </DropdownMenu.Portal>
          </DropdownMenu.Root>
        </div>
        <p className="line-clamp-2 min-h-10 text-sm text-slate-500 dark:text-slate-400">{project.description || project.template?.name || 'โปรเจกต์ว่าง'}</p>
        <p className="mt-auto pt-2 text-xs text-slate-400">
          {project.status === 'archived' ? 'เก็บถาวร · ' : ''}แก้ไข {formatDate(project.updatedAt)}{project.revision ? ` · rev ${project.revision}` : ''}
        </p>
      </div>
      <RenameDialog project={project} open={renaming} onOpenChange={setRenaming} wsId={wsId} />
    </li>
  );
}

function MenuItem({ icon: Icon, label, onSelect, danger }: { icon: typeof Pencil; label: string; onSelect: () => void; danger?: boolean }) {
  return (
    <DropdownMenu.Item
      onSelect={onSelect}
      className={`flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 outline-none data-[highlighted]:bg-slate-100 dark:data-[highlighted]:bg-navy-800 ${danger ? 'text-red-600' : ''}`}
    >
      <Icon className="h-4 w-4" aria-hidden /> {label}
    </DropdownMenu.Item>
  );
}

function RenameDialog({ project, open, onOpenChange, wsId }: { project: Project; open: boolean; onOpenChange: (o: boolean) => void; wsId: string }) {
  const [name, setName] = useState(project.name);
  const [description, setDescription] = useState(project.description);
  const save = useProjectMutation(() => api(`/projects/${project.id}`, { method: 'PATCH', body: { name, description } }), wsId);
  return (
    <Dialog open={open} onOpenChange={onOpenChange} title="แก้ไขข้อมูลโปรเจกต์">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate(undefined, {
            onSuccess: () => {
              toast.success('บันทึกแล้ว');
              onOpenChange(false);
            },
            onError: (err) => toast.error(errorMessage(err)),
          });
        }}
      >
        <Field label="ชื่อโปรเจกต์" value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} />
        <Field label="คำอธิบาย" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => onOpenChange(false)}>ยกเลิก</Button>
          <Button type="submit" loading={save.isPending} disabled={!name.trim()}>บันทึก</Button>
        </div>
      </form>
    </Dialog>
  );
}

function CreateProjectDialog({ open, onOpenChange, wsId }: { open: boolean; onOpenChange: (o: boolean) => void; wsId: string }) {
  const navigate = useNavigate();
  const templates = useTemplates();
  const [selected, setSelected] = useState<string | null>('training-registration');
  const [name, setName] = useState('');
  const preview = useTemplate(selected === 'ai' ? null : selected);
  const [aiPrompt, setAiPrompt] = useState('');
  const create = useProjectMutation(
    () => api<Envelope<Project>>(`/workspaces/${wsId}/projects`, { method: 'POST', body: { name: name.trim(), templateSlug: selected && selected !== 'ai' ? selected : undefined } }),
    wsId,
  );
  const chosen = useMemo(() => templates.data?.find((t) => t.slug === selected), [templates.data, selected]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange} title="สร้างโปรเจกต์ใหม่" description="เลือกเทมเพลตเพื่อเริ่มต้นอย่างรวดเร็ว หรือเริ่มจากหน้าว่าง" wide>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate(undefined, {
            onSuccess: (res) => {
              const p = (res as Envelope<Project>).data;
              toast.success('สร้างโปรเจกต์แล้ว');
              onOpenChange(false);
              navigate(`/app/projects/${p.id}`, { state: selected === 'ai' ? { aiPrompt: aiPrompt.trim() } : undefined });
            },
            onError: (err) => toast.error(errorMessage(err)),
          });
        }}
        className="grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]"
      >
        <div className="space-y-4">
          <Field label="ชื่อโปรเจกต์" value={name} onChange={(e) => setName(e.target.value)} placeholder={chosen?.name ?? 'เว็บไซต์ของฉัน'} required maxLength={120} autoFocus />
          <fieldset>
            <legend className="field-label text-sm">เทมเพลต</legend>
            <div className="space-y-2">
              <TemplateOption checked={selected === 'ai'} onChange={() => setSelected('ai')} title="✨ อธิบายด้วยคำพูด (AI)" description="บอกว่าต้องการแอปแบบไหน ระบบจะวางแผนหน้าเว็บ ตารางข้อมูล และฟอร์มให้ตรวจก่อนสร้าง" />
              <TemplateOption checked={selected === null} onChange={() => setSelected(null)} title="หน้าว่าง" description="เริ่มต้นจากศูนย์" />
              {templates.isLoading ? <Spinner /> : null}
              {templates.data?.map((t) => (
                <TemplateOption key={t.slug} checked={selected === t.slug} onChange={() => setSelected(t.slug)} title={t.name} description={t.description} />
              ))}
            </div>
          </fieldset>
        </div>
        <div className="flex min-h-80 flex-col">
          <p className="field-label text-sm">ตัวอย่าง</p>
          <div className="relative flex-1 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-navy-900" aria-hidden={selected !== 'ai'}>
            {selected === 'ai' ? (
              <div className="flex h-full flex-col gap-2 p-4">
                <label htmlFor="ai-create-prompt" className="field-label text-sm">อธิบายแอปที่ต้องการ</label>
                <textarea id="ai-create-prompt" className="field-input flex-1" rows={8} maxLength={4000} value={aiPrompt} onChange={(e) => setAiPrompt(e.target.value)} placeholder="เช่น ระบบจองคิวร้านซ่อมรถ ลูกค้ากรอกชื่อ เบอร์โทร รุ่นรถ และวันที่ต้องการ แล้วส่งอีเมลยืนยัน" />
                <p className="text-xs text-slate-500">ระบบจะแสดงแผนและรายการเปลี่ยนแปลงให้ตรวจสอบก่อนเสมอ</p>
              </div>
            ) : selected === null ? (
              <div className="flex h-full items-center justify-center text-sm text-slate-400"><LayoutTemplate className="mr-2 h-5 w-5" /> หน้าว่าง</div>
            ) : preview.data ? (
              <div className="pointer-events-none absolute left-0 top-0 w-[1280px] origin-top-left" style={{ transform: 'scale(0.34)' }}>
                <PageRenderer doc={preview.data.document} pageId={preview.data.document.settings.homePageId} breakpoint="base" mode="live" />
              </div>
            ) : (
              <Spinner />
            )}
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="secondary" onClick={() => onOpenChange(false)}>ยกเลิก</Button>
            <Button type="submit" loading={create.isPending} disabled={!name.trim() || (selected === 'ai' && !aiPrompt.trim())}>สร้างโปรเจกต์</Button>
          </div>
        </div>
      </form>
    </Dialog>
  );
}

function TemplateOption({ checked, onChange, title, description }: { checked: boolean; onChange: () => void; title: string; description: string }) {
  return (
    <label className={`flex cursor-pointer gap-3 rounded-xl border p-3 text-sm transition ${checked ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/20' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'}`}>
      <input type="radio" name="template" checked={checked} onChange={onChange} className="mt-1 accent-brand-700" />
      <span>
        <span className="block font-medium">{title}</span>
        <span className="block text-xs text-slate-500 dark:text-slate-400">{description}</span>
      </span>
    </label>
  );
}
