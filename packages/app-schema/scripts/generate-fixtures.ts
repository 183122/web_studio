/**
 * Generates parity fixtures consumed by BOTH the Vitest suite and the PHP
 * CommandApplier/SemanticValidator tests. Expected output is produced by the
 * TypeScript reference implementation and committed; PHP must reproduce it.
 */
import { writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { applyCommands, CommandError } from '../src/commands.ts';
import { validateDefinition } from '../src/validate.ts';
import { validateFieldValue } from '../src/rules.ts';
import type { AppCommand, AppDefinition } from '../src/types.ts';
import { sampleDoc } from '../test/fixture-doc.ts';
import { canonicalJson, canonicalize } from '../src/canonical.ts';

const out = fileURLToPath(new URL('../fixtures/', import.meta.url));
mkdirSync(out, { recursive: true });

const node = (id: string, type: string, props: Record<string, unknown> = {}, children: string[] = []) => ({ id, type, props, styles: {}, children });

const commandCases: Array<{ name: string; commands: AppCommand[] }> = [
  { name: 'insert heading at index 1', commands: [{ type: 'component.insert', parentId: 'c_section', index: 1, nodes: [node('c_new', 'Heading', { text: 'ใหม่', level: 'h2' })] }] },
  { name: 'insert subtree with clamped index', commands: [{ type: 'component.insert', parentId: 'p_home', index: 999, nodes: [node('c_card', 'Card', {}, ['c_card_t']), node('c_card_t', 'Text', { text: 'ในการ์ด' })] }] },
  { name: 'remove container subtree', commands: [{ type: 'component.remove', id: 'c_box' }] },
  { name: 'move button to section start', commands: [{ type: 'component.move', id: 'c_btn', parentId: 'c_section', index: 0 }] },
  { name: 'move within same parent', commands: [{ type: 'component.move', id: 'c_heading', parentId: 'c_section', index: 2 }] },
  { name: 'update props and styles with unset', commands: [{ type: 'component.update', id: 'c_heading', name: 'Hero title', props: { text: 'สวัสดีชาวโลก' }, styles: { base: { fontSize: null, color: '$primary' }, mobile: { fontSize: null }, tablet: { textAlign: 'center' } } }] },
  { name: 'image asset change updates derived assets', commands: [{ type: 'component.update', id: 'c_img', props: { src: 'https://example.com/a.jpg' } }] },
  { name: 'add page', commands: [{ type: 'page.add', index: 1, page: { id: 'pg_contact', name: 'ติดต่อ', path: '/contact', rootId: 'p_contact', seo: { title: 'ติดต่อ', description: '', noindex: false } }, nodes: [node('p_contact', 'Page', {}, ['c_contact_h']), node('c_contact_h', 'Heading', { text: 'ติดต่อเรา', level: 'h1' })] }] },
  { name: 'remove page', commands: [{ type: 'page.remove', id: 'pg_about' }] },
  { name: 'update page path and seo', commands: [{ type: 'page.update', id: 'pg_about', changes: { path: '/about-us', seo: { noindex: true } } }] },
  { name: 'tokens update', commands: [{ type: 'tokens.update', changes: { colors: { primary: '#123456' }, fonts: { heading: 'Prompt' }, radius: '4px' } }] },
  { name: 'site update', commands: [{ type: 'site.update', changes: { name: 'ใหม่', seo: { description: 'คำอธิบาย' }, settings: { notFoundPageId: 'pg_about' } } }] },
  { name: 'add model with permission', commands: [
    { type: 'model.upsert', index: 1, model: { id: 'm_course', name: 'courses', label: 'หลักสูตร', displayFieldId: 'f_ctitle', fields: [{ id: 'f_ctitle', name: 'title', label: 'ชื่อหลักสูตร', type: 'text', required: true, unique: true }] } },
    { type: 'permission.set', permission: { modelId: 'm_course', rules: { public: ['read'], member: ['read'], admin: ['create', 'read', 'update', 'delete'] } } },
  ] },
  { name: 'update model fields', commands: [
    { type: 'model.upsert', index: 0, model: { id: 'm_reg', name: 'registrations', label: 'ผู้สมัครอบรม', displayFieldId: 'f_name', fields: [
      { id: 'f_name', name: 'full_name', label: 'ชื่อ', type: 'text', required: true, unique: false, maxLength: 120 },
      { id: 'f_email', name: 'email', label: 'อีเมล', type: 'email', required: true, unique: true },
      { id: 'f_seats', name: 'seats', label: 'จำนวนที่นั่ง', type: 'integer', required: false, unique: false, min: 1, max: 10 },
      { id: 'f_status', name: 'status', label: 'สถานะ', type: 'select', required: false, unique: false, options: ['pending', 'confirmed', 'cancelled', 'waitlist'] },
      { id: 'f_org', name: 'organization', label: 'หน่วยงาน', type: 'text', required: false, unique: false },
    ] } },
  ] },
  { name: 'change permissions', commands: [{ type: 'permission.set', permission: { modelId: 'm_reg', rules: { public: ['create', 'read'], member: [], admin: ['read'] } } }] },
  { name: 'upsert and remove workflow', commands: [
    { type: 'workflow.upsert', index: 0, workflow: { id: 'w_notify', name: 'แจ้งผู้ดูแล', enabled: false, trigger: { event: 'record.updated', modelId: 'm_reg' }, conditions: [{ fieldId: 'f_seats', op: 'gt', value: 3 }], actions: [{ type: 'email.send', to: 'admin@example.com', subject: 'จองเกิน', body: '{{full_name}} จอง {{seats}} ที่' }] } },
    { type: 'workflow.remove', id: 'w_welcome' },
  ] },
  { name: 'remove data bindings then model', commands: [
    { type: 'component.remove', id: 'c_form' },
    { type: 'component.remove', id: 'c_table' },
    { type: 'workflow.remove', id: 'w_welcome' },
    { type: 'permission.remove', modelId: 'm_reg' },
    { type: 'model.remove', id: 'm_reg' },
  ] },
  { name: 'insert form field into form', commands: [{ type: 'component.insert', parentId: 'c_form', index: 2, nodes: [node('c_ff_seats', 'FormField', { fieldId: 'f_seats', label: '', placeholder: '', helpText: '' })] }] },
  { name: 'batch of several commands', commands: [
    { type: 'component.insert', parentId: 'c_box', index: 0, nodes: [node('c_div', 'Divider')] },
    { type: 'component.move', id: 'c_text', parentId: 'c_box', index: 1 },
    { type: 'component.update', id: 'c_btn', props: { label: 'ลงทะเบียน', newTab: true } },
  ] },
];

const errorCases: Array<{ name: string; commands: AppCommand[]; code: string }> = [
  { name: 'insert into non-container', code: 'not_a_container', commands: [{ type: 'component.insert', parentId: 'c_heading', index: 0, nodes: [node('c_x', 'Text', { text: 'x' })] }] },
  { name: 'insert duplicate id', code: 'duplicate_id', commands: [{ type: 'component.insert', parentId: 'c_box', index: 0, nodes: [node('c_btn', 'Text', { text: 'x' })] }] },
  { name: 'move into own descendant', code: 'cycle', commands: [{ type: 'component.move', id: 'c_section', parentId: 'c_box', index: 0 }] },
  { name: 'remove page root as component', code: 'invalid_target', commands: [{ type: 'component.remove', id: 'p_home' }] },
  { name: 'remove home page', code: 'home_page', commands: [{ type: 'page.remove', id: 'pg_home' }] },
  { name: 'update missing component', code: 'not_found', commands: [{ type: 'component.update', id: 'c_nope', props: { text: 'x' } }] },
  { name: 'remove model in use', code: 'model_in_use', commands: [{ type: 'model.remove', id: 'm_reg' }] },
  { name: 'remove missing permission', code: 'not_found', commands: [{ type: 'permission.remove', modelId: 'm_none' }] },
  { name: 'unknown token', code: 'unknown_token', commands: [{ type: 'tokens.update', changes: { colors: { neon: '#ffffff' } as never } }] },
];

const invalidDocs: Array<{ name: string; mutate: (d: AppDefinition) => void; codes: string[] }> = [
  { name: 'javascript url', codes: ['invalid_prop'], mutate: (d) => { d.components.c_btn.props.href = 'javascript:alert(1)'; } },
  { name: 'protocol relative url', codes: ['invalid_prop'], mutate: (d) => { d.components.c_btn.props.href = '//evil.example'; } },
  { name: 'data image', codes: ['invalid_prop', 'assets_mismatch'], mutate: (d) => { d.components.c_img.props.src = 'data:image/svg+xml,<svg onload=alert(1)>'; } },
  { name: 'unknown component', codes: ['unknown_component'], mutate: (d) => { d.components.c_text.type = 'Script'; } },
  { name: 'unknown prop', codes: ['unknown_prop'], mutate: (d) => { d.components.c_text.props.html = '<b>x</b>'; } },
  { name: 'css injection', codes: ['invalid_style'], mutate: (d) => { d.components.c_text.styles = { base: { backgroundColor: 'red;background:url(x)' } }; } },
  { name: 'unknown style', codes: ['invalid_style'], mutate: (d) => { d.components.c_text.styles = { base: { position: 'fixed' } }; } },
  { name: 'cycle', codes: ['multiple_parents', 'cycle'], mutate: (d) => { d.components.c_box.children.push('c_section'); } },
  { name: 'orphan', codes: ['orphan'], mutate: (d) => { d.components.c_section.children = ['c_heading', 'c_text']; } },
  { name: 'missing child', codes: ['missing_child'], mutate: (d) => { d.components.c_box.children.push('c_ghost'); } },
  { name: 'route conflict', codes: ['route_conflict'], mutate: (d) => { d.pages[1].path = '/'; } },
  { name: 'children on leaf', codes: ['children_not_allowed', 'cycle', 'multiple_parents'], mutate: (d) => { d.components.c_heading.children = ['c_btn']; } },
  { name: 'missing home', codes: ['missing_home'], mutate: (d) => { d.settings.homePageId = 'pg_none'; } },
  { name: 'assets mismatch', codes: ['assets_mismatch'], mutate: (d) => { d.assets = []; } },
  { name: 'text with newline in single-line', codes: ['invalid_prop'], mutate: (d) => { d.components.c_heading.props.text = 'a\nb'; } },
  { name: 'model without permission', codes: ['missing_permission'], mutate: (d) => { d.permissions = []; } },
  { name: 'duplicate field name and reserved name', codes: ['duplicate_field', 'reserved_name'], mutate: (d) => { d.dataModels[0].fields[1].name = 'full_name'; d.dataModels[0].fields[2].name = 'id'; } },
  { name: 'select without options', codes: ['invalid_field', 'invalid_value'], mutate: (d) => { delete d.dataModels[0].fields[3].options; } },
  { name: 'display field missing', codes: ['missing_field'], mutate: (d) => { d.dataModels[0].displayFieldId = 'f_nope'; } },
  { name: 'form field outside form', codes: ['field_outside_form'], mutate: (d) => { d.components.c_form.children = ['c_ff_email']; d.components.p_about.children.push('c_ff_name'); } },
  { name: 'form field from another model', codes: ['missing_field'], mutate: (d) => { d.components.c_ff_name.props.fieldId = 'f_unknown'; } },
  { name: 'form unknown model', codes: ['missing_field', 'missing_model'], mutate: (d) => { d.components.c_form.props.modelId = 'm_nope'; } },
  { name: 'workflow invalid recipient and placeholder', codes: ['invalid_recipient', 'unknown_placeholder'], mutate: (d) => { d.workflows[0].actions[1] = { type: 'email.send', to: 'field:f_name', subject: '{{nope}}', body: 'x' }; } },
  { name: 'workflow setField invalid value', codes: ['invalid_value'], mutate: (d) => { d.workflows[0].actions[0] = { type: 'record.setField', fieldId: 'f_status', value: 'hacked' }; } },
  { name: 'condition missing value', codes: ['invalid_condition'], mutate: (d) => { d.workflows[0].conditions = [{ fieldId: 'f_seats', op: 'gt' }]; } },
  { name: 'v1 document needs upcast', codes: ['schema.const'], mutate: (d) => { (d as { schemaVersion: number }).schemaVersion = 1; } },
  { name: 'redirect conflicts page', codes: ['route_conflict'], mutate: (d) => { d.routes = [{ id: 'r1', from: '/about', to: '/', status: 301 }]; } },
];

const cases = commandCases.map((c) => {
  const r = applyCommands(sampleDoc(), c.commands);
  const issues = validateDefinition(r.doc);
  if (issues.length) throw new Error(`${c.name} produced invalid doc: ${JSON.stringify(issues)}`);
  const undone = applyCommands(r.doc, r.inverse).doc;
  if (canonicalJson(undone) !== canonicalJson(sampleDoc())) throw new Error(`${c.name}: inverse did not restore`);
  return { name: c.name, commands: c.commands, expected: canonicalize(r.doc) };
});

const errors = errorCases.map((c) => {
  try {
    applyCommands(sampleDoc(), c.commands);
  } catch (e) {
    if (e instanceof CommandError && e.code === c.code) return { name: c.name, commands: c.commands, errorCode: c.code };
    throw e;
  }
  throw new Error(`${c.name}: expected error ${c.code}`);
});

const invalid = invalidDocs.map((c) => {
  const d = sampleDoc();
  c.mutate(d);
  const codes = [...new Set(validateDefinition(d).map((i) => i.code))].sort();
  const expected = [...c.codes].sort();
  if (codes.join() !== expected.join()) throw new Error(`${c.name}: expected ${expected} got ${codes}`);
  return { name: c.name, document: d, codes: expected };
});

// Field value table shared with PHP (FieldValue).
const fieldValueCases: Array<{ field: Record<string, unknown>; value: unknown; ok: boolean }> = [
  { field: { type: 'text', required: true }, value: null, ok: false },
  { field: { type: 'text', required: false }, value: null, ok: true },
  { field: { type: 'text', required: false }, value: 'บรรทัด\nสอง', ok: false },
  { field: { type: 'longtext', required: false }, value: 'บรรทัด\nสอง', ok: true },
  { field: { type: 'text', required: false, maxLength: 3 }, value: 'สวัสดี', ok: false },
  { field: { type: 'integer', required: false, min: 1, max: 10 }, value: 5, ok: true },
  { field: { type: 'integer', required: false }, value: 2.5, ok: false },
  { field: { type: 'number', required: false }, value: '5', ok: false },
  { field: { type: 'number', required: false, max: 1 }, value: 1.5, ok: false },
  { field: { type: 'boolean', required: false }, value: 'true', ok: false },
  { field: { type: 'date', required: false }, value: '2026-02-29', ok: false },
  { field: { type: 'date', required: false }, value: '2028-02-29', ok: true },
  { field: { type: 'datetime', required: false }, value: '2026-11-22T10:30:00+07:00', ok: true },
  { field: { type: 'email', required: false }, value: 'a@b.co', ok: true },
  { field: { type: 'email', required: false }, value: 'not-an-email', ok: false },
  { field: { type: 'phone', required: false }, value: '+66 81-234-5678', ok: true },
  { field: { type: 'url', required: false }, value: 'javascript:alert(1)', ok: false },
  { field: { type: 'url', required: false }, value: 'https://example.com/x', ok: true },
  { field: { type: 'select', required: false, options: ['a', 'b'] }, value: 'c', ok: false },
];
for (const c of fieldValueCases) {
  const ok = validateFieldValue(c.field as never, c.value) === null;
  if (ok !== c.ok) throw new Error(`field value case ${JSON.stringify(c)} disagrees`);
}
writeFileSync(`${out}/field-values.json`, JSON.stringify(fieldValueCases, null, 2) + '\n');

writeFileSync(`${out}/initial.json`, JSON.stringify(sampleDoc(), null, 2) + '\n');
writeFileSync(`${out}/commands.json`, JSON.stringify(cases, null, 2) + '\n');
writeFileSync(`${out}/command-errors.json`, JSON.stringify(errors, null, 2) + '\n');
writeFileSync(`${out}/invalid-documents.json`, JSON.stringify(invalid, null, 2) + '\n');
console.log(`fixtures: ${cases.length} command cases, ${errors.length} error cases, ${invalid.length} invalid documents`);
