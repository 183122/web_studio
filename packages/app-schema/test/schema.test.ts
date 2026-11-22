import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import {
  applyCommand,
  applyCommands,
  canonicalJson,
  canonicalize,
  CommandError,
  createBlankDefinition,
  createNode,
  cloneWithNewIds,
  collectSubtree,
  isSafeHref,
  validateCommandShape,
  validateDefinition,
  type AppCommand,
} from '../src/index.ts';
import { sampleDoc } from './fixture-doc.ts';

const fixture = (name: string) => JSON.parse(readFileSync(new URL(`../fixtures/${name}`, import.meta.url), 'utf8'));

describe('structural + semantic validation', () => {
  it('accepts the sample document and a blank definition', () => {
    expect(validateDefinition(sampleDoc())).toEqual([]);
    expect(validateDefinition(createBlankDefinition('p1', 'เว็บไซต์ใหม่'))).toEqual([]);
  });

  it('rejects unknown top-level keys and unsupported schema versions', () => {
    const d = { ...sampleDoc(), schemaVersion: 2 };
    expect(validateDefinition(d).some((i) => i.code === 'schema.const')).toBe(true);
    const e = { ...sampleDoc(), evil: true };
    expect(validateDefinition(e).some((i) => i.code === 'schema.additionalProperties')).toBe(true);
  });

  it('rejects data models until Phase 2 defines them', () => {
    const d = { ...sampleDoc(), dataModels: [{ name: 'x' }] };
    expect(validateDefinition(d).length).toBeGreaterThan(0);
  });

  for (const c of fixture('invalid-documents.json') as Array<{ name: string; document: unknown; codes: string[] }>) {
    it(`flags invalid document: ${c.name}`, () => {
      const codes = [...new Set(validateDefinition(c.document).map((i) => i.code))].sort();
      expect(codes).toEqual(c.codes);
    });
  }

  it('enforces maximum nesting depth', () => {
    const d = sampleDoc();
    let parent = 'c_box';
    for (let i = 0; i < 40; i++) {
      const id = `c_deep${i}`;
      d.components[id] = { id, type: 'Container', props: {}, styles: {}, children: [] };
      d.components[parent].children.push(id);
      parent = id;
    }
    expect(validateDefinition(d).some((i) => i.code === 'too_deep')).toBe(true);
  });
});

describe('href safety', () => {
  it.each([
    ['/', true], ['/about-us?x=1#top', true], ['#contact', true], ['https://example.com/a?b=c', true],
    ['mailto:hello@example.com', true], ['tel:+66 2 123 4567', true],
    ['javascript:alert(1)', false], ['JAVASCRIPT:alert(1)', false], ['//evil.com', false], ['data:text/html,x', false],
    ['/\\evil', false], ['https://exa mple.com', false], ['', false], ['vbscript:x', false],
  ])('%s → %s', (href, ok) => expect(isSafeHref(href)).toBe(ok));
});

describe('command reducer', () => {
  for (const c of fixture('commands.json') as Array<{ name: string; commands: AppCommand[]; expected: unknown }>) {
    it(`applies: ${c.name} (and inverse restores original)`, () => {
      const original = sampleDoc();
      const r = applyCommands(original, c.commands);
      expect(canonicalize(r.doc)).toEqual(c.expected);
      expect(validateDefinition(r.doc)).toEqual([]);
      expect(canonicalJson(applyCommands(r.doc, r.inverse).doc)).toBe(canonicalJson(sampleDoc()));
      // input never mutated
      expect(canonicalJson(original)).toBe(canonicalJson(sampleDoc()));
    });
  }

  for (const c of fixture('command-errors.json') as Array<{ name: string; commands: AppCommand[]; errorCode: string }>) {
    it(`rejects: ${c.name}`, () => {
      try {
        applyCommands(sampleDoc(), c.commands);
        expect.unreachable();
      } catch (e) {
        expect(e).toBeInstanceOf(CommandError);
        expect((e as CommandError).code).toBe(c.errorCode);
      }
    });
  }

  it('validates command shapes', () => {
    expect(validateCommandShape({ type: 'component.remove', id: 'c_x' })).toEqual([]);
    expect(validateCommandShape({ type: 'component.remove', id: 'c x' }).length).toBeGreaterThan(0);
    expect(validateCommandShape({ type: 'sql.execute', query: 'DROP TABLE users' }).length).toBeGreaterThan(0);
  });

  it('duplicates a subtree with fresh ids', () => {
    const d = sampleDoc();
    const copy = cloneWithNewIds(collectSubtree(d, 'c_box'));
    const r = applyCommand(d, { type: 'component.insert', parentId: 'c_section', index: 3, nodes: copy });
    expect(validateDefinition(r.doc)).toEqual([]);
    expect(Object.keys(r.doc.components).length).toBe(Object.keys(d.components).length + 2);
  });

  it('creates nodes with registry defaults and no empty style maps', () => {
    const btn = createNode('Button');
    expect(btn.props).toMatchObject({ label: 'คลิกที่นี่', href: '#', variant: 'primary', newTab: false });
    expect(btn.styles).toEqual({});
  });
});
