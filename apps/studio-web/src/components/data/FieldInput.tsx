import type { FieldDefinition } from '@studio/app-schema';

/** Accessible input for one data-model field (generated admin forms). Values are kept as raw strings/booleans. */
export function FieldInput({ field, value, onChange, error, id }: { field: FieldDefinition; value: unknown; onChange: (v: unknown) => void; error?: string; id: string }) {
  const common = { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? `${id}-err` : undefined, className: `field-input ${error ? 'border-red-500' : ''}` };
  let control;
  switch (field.type) {
    case 'longtext':
      control = <textarea {...common} rows={3} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />;
      break;
    case 'boolean':
      control = <input id={id} type="checkbox" className="h-4 w-4 accent-brand-700" checked={value === true} onChange={(e) => onChange(e.target.checked)} />;
      break;
    case 'select':
      control = (
        <select {...common} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}>
          <option value="">— ไม่ระบุ —</option>
          {(field.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
      );
      break;
    default: {
      const type = { email: 'email', phone: 'tel', url: 'url', number: 'number', integer: 'number', date: 'date', datetime: 'datetime-local' }[field.type as string] ?? 'text';
      control = <input {...common} type={type} value={value === null || value === undefined ? '' : String(value)} onChange={(e) => onChange(e.target.value)} step={field.type === 'number' ? 'any' : undefined} />;
    }
  }
  return (
    <div>
      <label htmlFor={id} className="field-label text-sm">
        {field.label}
        {field.required ? <span className="text-red-600"> *</span> : null}
        <span className="ml-1 text-[11px] font-normal text-slate-400">{field.name} · {field.type}</span>
      </label>
      {control}
      {error ? <p id={`${id}-err`} role="alert" className="mt-1 text-xs text-red-600">{error}</p> : null}
    </div>
  );
}

export function displayValue(field: FieldDefinition | undefined, v: unknown): string {
  if (v === null || v === undefined || v === '') return '—';
  if (field?.type === 'boolean') return v ? 'ใช่' : 'ไม่ใช่';
  return String(v);
}
