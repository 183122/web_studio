import { beforeEach, describe, expect, it } from 'vitest';
import { applyCommands, canonicalJson, createNode } from '@studio/app-schema';
import { sampleDoc } from '../../../../packages/app-schema/test/fixture-doc.ts';
import { useEditor } from './store.ts';
import { moveIndex } from './dnd.ts';
import { safeNext } from '../lib/auth.tsx';

const s = () => useEditor.getState();

beforeEach(() => {
  s().load({ projectId: 'p1', workspaceId: 'w1', canEdit: true, doc: sampleDoc(), revision: 7 });
});

describe('editor store', () => {
  it('applies commands, queues them for saving and supports undo/redo', () => {
    const node = createNode('Text', { id: 'c_new' });
    expect(s().dispatch([{ type: 'component.insert', parentId: 'c_section', index: 0, nodes: [node] }])).toBe(true);
    expect(s().doc!.components.c_section.children[0]).toBe('c_new');
    expect(s().pending).toHaveLength(1);
    expect(s().saveState).toBe('pending');

    s().undo();
    expect(s().doc!.components.c_new).toBeUndefined();
    expect(s().pending).toHaveLength(2); // the inverse is a normal command that must be saved too
    s().redo();
    expect(s().doc!.components.c_new).toBeDefined();
    expect(s().past).toHaveLength(1);
  });

  it('rejects commands that would produce an invalid document without changing state', () => {
    const before = canonicalJson(s().doc);
    expect(s().dispatch([{ type: 'component.update', id: 'c_btn', props: { href: 'javascript:alert(1)' } }])).toBe(false);
    expect(canonicalJson(s().doc)).toBe(before);
    expect(s().issues[0].code).toBe('invalid_prop');
    expect(s().pending).toHaveLength(0);
    expect(s().dispatch([{ type: 'component.move', id: 'c_section', parentId: 'c_box', index: 0 }])).toBe(false);
    expect(s().issues[0].code).toBe('cycle');
  });

  it('coalesces rapid typing into one undo step', () => {
    for (const t of ['ส', 'สว', 'สวั', 'สวัส']) {
      s().dispatch([{ type: 'component.update', id: 'c_heading', props: { text: t } }], { coalesceKey: 'c_heading:text' });
    }
    expect(s().past).toHaveLength(1);
    s().undo();
    expect(s().doc!.components.c_heading.props.text).toBe('สวัสดี');
  });

  it('does not edit in read-only mode', () => {
    s().load({ projectId: 'p1', workspaceId: 'w1', canEdit: false, doc: sampleDoc(), revision: 1 });
    expect(s().dispatch([{ type: 'site.update', changes: { name: 'x' } }])).toBe(false);
    expect(s().saveState).toBe('readonly');
  });

  it('save lifecycle: batches pending commands and tracks revisions', () => {
    s().dispatch([{ type: 'site.update', changes: { name: 'A' } }]);
    const batch = s().beginSave();
    expect(batch).toHaveLength(1);
    expect(s().saveState).toBe('saving');
    s().dispatch([{ type: 'site.update', changes: { name: 'B' } }]);
    expect(s().pending).toHaveLength(1);
    s().saveSucceeded(8);
    expect(s().serverRevision).toBe(8);
    expect(s().saveState).toBe('pending');
  });

  it('rebases unsaved commands onto a newer server head (conflict merge)', () => {
    s().dispatch([{ type: 'component.update', id: 'c_text', props: { text: 'ของฉัน' } }]);
    s().beginSave();
    s().saveFailed('conflict', '409');
    const remote = applyCommands(sampleDoc(), [{ type: 'component.update', id: 'c_heading', props: { text: 'ของเขา' } }]).doc;
    expect(s().rebase(remote, 9, true).ok).toBe(true);
    expect(s().doc!.components.c_heading.props.text).toBe('ของเขา');
    expect(s().doc!.components.c_text.props.text).toBe('ของฉัน');
    expect(s().serverRevision).toBe(9);
    expect(s().pending).toHaveLength(1);
    expect(s().saveState).toBe('pending');
  });

  it('reports when unsaved commands cannot be re-applied', () => {
    s().dispatch([{ type: 'component.update', id: 'c_text', props: { text: 'x' } }]);
    const remote = applyCommands(sampleDoc(), [{ type: 'component.remove', id: 'c_text' }]).doc;
    expect(s().rebase(remote, 9, true).ok).toBe(false);
  });
});

describe('drag and drop index math', () => {
  it('adjusts indices for moves within the same parent', () => {
    const doc = sampleDoc(); // c_section children: heading, text, box
    expect(moveIndex(doc, 'c_heading', { parentId: 'c_section', index: 3 })).toBe(2);
    expect(moveIndex(doc, 'c_heading', { parentId: 'c_section', index: 1 })).toBeNull();
    expect(moveIndex(doc, 'c_box', { parentId: 'c_section', index: 0 })).toBe(0);
    expect(moveIndex(doc, 'c_text', { parentId: 'c_box', index: 0 })).toBe(0);
  });
});

describe('safeNext', () => {
  it('only allows same-site relative paths', () => {
    expect(safeNext('/app/projects/x')).toBe('/app/projects/x');
    expect(safeNext('https://evil.example')).toBe('/app');
    expect(safeNext('//evil.example')).toBe('/app');
    expect(safeNext('/\\evil.example')).toBe('/app');
    expect(safeNext(null)).toBe('/app');
  });
});
