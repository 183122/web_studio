/**
 * Generates parity fixtures consumed by BOTH the Vitest suite and the PHP
 * CommandApplier/SemanticValidator tests. Expected output is produced by the
 * TypeScript reference implementation and committed; PHP must reproduce it.
 */
import { writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { applyCommands, CommandError } from '../src/commands.ts';
import { validateDefinition } from '../src/validate.ts';
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

writeFileSync(`${out}/initial.json`, JSON.stringify(sampleDoc(), null, 2) + '\n');
writeFileSync(`${out}/commands.json`, JSON.stringify(cases, null, 2) + '\n');
writeFileSync(`${out}/command-errors.json`, JSON.stringify(errors, null, 2) + '\n');
writeFileSync(`${out}/invalid-documents.json`, JSON.stringify(invalid, null, 2) + '\n');
console.log(`fixtures: ${cases.length} command cases, ${errors.length} error cases, ${invalid.length} invalid documents`);
