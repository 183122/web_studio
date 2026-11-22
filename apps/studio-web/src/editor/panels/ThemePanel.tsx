import { COLOR_TOKENS } from '@studio/app-schema';
import { useEditor } from '../store.ts';

const COLOR_LABELS: Record<string, string> = {
  primary: 'สีหลัก', secondary: 'สีรอง', accent: 'สีเน้น', background: 'พื้นหลัง', surface: 'พื้นผิว/การ์ด', text: 'ตัวอักษร', muted: 'ตัวอักษรรอง',
};
const FONTS = ['Kanit', 'Sarabun', 'Prompt', 'Noto Sans Thai', 'system'] as const;
const RADII = ['0', '4px', '8px', '12px', '16px', '24px'];

/** Contrast ratio (WCAG 2.x) between two hex colors. */
function contrast(a: string, b: string): number {
  const lum = (hex: string) => {
    const h = hex.replace('#', '');
    const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h.slice(0, 6);
    const [r, g, bl] = [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  };
  const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
  return (x + 0.05) / (y + 0.05);
}

export function ThemePanel() {
  const tokens = useEditor((s) => s.doc!.designTokens);
  const dispatch = useEditor((s) => s.dispatch);
  const canEdit = useEditor((s) => s.canEdit);
  const textRatio = contrast(tokens.colors.text, tokens.colors.background);
  const buttonRatio = contrast('#ffffff', tokens.colors.primary);

  return (
    <div className="space-y-5 text-sm">
      <section>
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">สี</h3>
        <div className="space-y-2">
          {COLOR_TOKENS.map((key) => (
            <label key={key} className="flex items-center gap-2">
              <input
                type="color"
                disabled={!canEdit}
                value={(tokens.colors as Record<string, string>)[key].slice(0, 7)}
                onChange={(e) => dispatch([{ type: 'tokens.update', changes: { colors: { [key]: e.target.value } } }], { label: 'เปลี่ยนสีธีม', coalesceKey: `color:${key}` })}
                className="h-8 w-10 cursor-pointer rounded border border-slate-300 bg-transparent dark:border-slate-600"
              />
              <span className="flex-1">{COLOR_LABELS[key]}</span>
              <code className="text-xs text-slate-500">${key}</code>
            </label>
          ))}
        </div>
        <ul className="mt-3 space-y-1 text-xs">
          <li className={textRatio >= 4.5 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600'}>
            ความคมชัดตัวอักษร/พื้นหลัง {textRatio.toFixed(2)}:1 {textRatio >= 4.5 ? '✓ ผ่าน WCAG AA' : '✗ ต่ำกว่า 4.5:1'}
          </li>
          <li className={buttonRatio >= 4.5 ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-600'}>
            ปุ่มหลัก (ตัวอักษรขาว) {buttonRatio.toFixed(2)}:1 {buttonRatio >= 4.5 ? '✓ ผ่าน WCAG AA' : '⚠ ควร ≥ 4.5:1'}
          </li>
        </ul>
      </section>
      <section className="space-y-2">
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">ตัวอักษร</h3>
        {(['heading', 'body'] as const).map((k) => (
          <label key={k} className="block">
            <span className="field-label">{k === 'heading' ? 'หัวข้อ' : 'เนื้อหา'}</span>
            <select
              className="field-input"
              disabled={!canEdit}
              value={tokens.fonts[k]}
              onChange={(e) => dispatch([{ type: 'tokens.update', changes: { fonts: { [k]: e.target.value as (typeof FONTS)[number] } } }], { label: 'เปลี่ยนฟอนต์' })}
            >
              {FONTS.map((f) => <option key={f} value={f}>{f}</option>)}
            </select>
          </label>
        ))}
      </section>
      <section>
        <label className="block">
          <span className="field-label">ความโค้งมุม ($radius)</span>
          <select className="field-input" disabled={!canEdit} value={tokens.radius} onChange={(e) => dispatch([{ type: 'tokens.update', changes: { radius: e.target.value } }], { label: 'เปลี่ยนความโค้งมุม' })}>
            {RADII.map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
        </label>
      </section>
    </div>
  );
}
