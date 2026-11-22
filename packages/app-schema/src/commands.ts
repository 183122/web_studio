import { getComponentDefinition } from '@studio/component-registry';
import type { AppCommand, AppDefinition, ComponentNode, StylePatch } from './types.ts';
import { collectAssetRefs } from './semantic.ts';

/**
 * Command reducer. Mirrors apps/platform-api/app/Domain/Schema/CommandApplier.php.
 * Each command returns the new document (structurally shared, never mutating the input)
 * plus the inverse commands that undo it.
 */
export class CommandError extends Error {
  readonly code: string;
  constructor(code: string, message: string) {
    super(message);
    this.code = code;
    this.name = 'CommandError';
  }
}

const has = (obj: object, key: string) => Object.prototype.hasOwnProperty.call(obj, key);
const clone = <T>(v: T): T => structuredClone(v);

function clampIndex(index: number, length: number): number {
  return Math.max(0, Math.min(Math.trunc(index), length));
}

function findParentId(doc: AppDefinition, id: string): string | null {
  for (const node of Object.values(doc.components)) {
    if (node.children.includes(id)) return node.id;
  }
  return null;
}

/** Pre-order list of the subtree rooted at id (root first). */
export function collectSubtree(doc: AppDefinition, id: string): ComponentNode[] {
  const out: ComponentNode[] = [];
  const seen = new Set<string>();
  const walk = (cid: string) => {
    if (seen.has(cid) || !has(doc.components, cid)) return;
    seen.add(cid);
    const node = doc.components[cid];
    out.push(node);
    node.children.forEach(walk);
  };
  walk(id);
  return out;
}

function requireNode(doc: AppDefinition, id: string): ComponentNode {
  if (!has(doc.components, id)) throw new CommandError('not_found', `component "${id}" does not exist`);
  return doc.components[id];
}

function requireContainer(doc: AppDefinition, id: string): ComponentNode {
  const node = requireNode(doc, id);
  const def = getComponentDefinition(node.type);
  if (!def || !def.acceptsChildren) throw new CommandError('not_a_container', `${node.type} cannot contain children`);
  return node;
}

function applyPatch(
  target: Record<string, unknown>,
  patch: Record<string, unknown>,
): { next: Record<string, unknown>; inverse: Record<string, unknown> } {
  const next = { ...target };
  const inverse: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(patch)) {
    inverse[key] = has(target, key) ? clone(target[key]) : null;
    if (value === null) delete next[key];
    else next[key] = clone(value);
  }
  return { next, inverse };
}

function withAssets(doc: AppDefinition): AppDefinition {
  return { ...doc, assets: collectAssetRefs(doc) };
}

export interface ApplyResult {
  doc: AppDefinition;
  inverse: AppCommand[];
}

export function applyCommand(doc: AppDefinition, cmd: AppCommand): ApplyResult {
  switch (cmd.type) {
    case 'component.insert': {
      const parent = requireContainer(doc, cmd.parentId);
      const components = { ...doc.components };
      const newIds = new Set<string>();
      for (const node of cmd.nodes) {
        if (has(components, node.id) || newIds.has(node.id)) throw new CommandError('duplicate_id', `component "${node.id}" already exists`);
        if (node.type === 'Page') throw new CommandError('invalid_child', 'a Page cannot be inserted as a component');
        newIds.add(node.id);
        components[node.id] = clone(node);
      }
      const root = cmd.nodes[0];
      const children = [...parent.children];
      children.splice(clampIndex(cmd.index, children.length), 0, root.id);
      components[parent.id] = { ...parent, children };
      return { doc: withAssets({ ...doc, components }), inverse: [{ type: 'component.remove', id: root.id }] };
    }

    case 'component.remove': {
      const node = requireNode(doc, cmd.id);
      if (node.type === 'Page') throw new CommandError('invalid_target', 'use page.remove to remove a page');
      const parentId = findParentId(doc, cmd.id);
      if (parentId === null) throw new CommandError('not_found', `component "${cmd.id}" is not attached`);
      const parent = doc.components[parentId];
      const index = parent.children.indexOf(cmd.id);
      const subtree = collectSubtree(doc, cmd.id);
      const components = { ...doc.components };
      for (const n of subtree) delete components[n.id];
      components[parentId] = { ...parent, children: parent.children.filter((c) => c !== cmd.id) };
      return {
        doc: withAssets({ ...doc, components }),
        inverse: [{ type: 'component.insert', parentId, index, nodes: subtree.map(clone) }],
      };
    }

    case 'component.move': {
      const node = requireNode(doc, cmd.id);
      if (node.type === 'Page') throw new CommandError('invalid_target', 'a Page cannot be moved');
      requireContainer(doc, cmd.parentId);
      const subtreeIds = new Set(collectSubtree(doc, cmd.id).map((n) => n.id));
      if (subtreeIds.has(cmd.parentId)) throw new CommandError('cycle', 'cannot move a component into itself or its descendants');
      const oldParentId = findParentId(doc, cmd.id);
      if (oldParentId === null) throw new CommandError('not_found', `component "${cmd.id}" is not attached`);
      const components = { ...doc.components };
      const oldParent = components[oldParentId];
      const oldIndex = oldParent.children.indexOf(cmd.id);
      components[oldParentId] = { ...oldParent, children: oldParent.children.filter((c) => c !== cmd.id) };
      const target = components[cmd.parentId];
      const children = [...target.children];
      children.splice(clampIndex(cmd.index, children.length), 0, cmd.id);
      components[cmd.parentId] = { ...target, children };
      return {
        doc: { ...doc, components },
        inverse: [{ type: 'component.move', id: cmd.id, parentId: oldParentId, index: oldIndex }],
      };
    }

    case 'component.update': {
      const node = requireNode(doc, cmd.id);
      const next: ComponentNode = { ...node };
      const inverse: Extract<AppCommand, { type: 'component.update' }> = { type: 'component.update', id: cmd.id };
      if (cmd.name !== undefined) {
        inverse.name = node.name ?? null;
        if (cmd.name === null || cmd.name === '') delete next.name;
        else next.name = cmd.name;
      }
      if (cmd.props) {
        const r = applyPatch(node.props, cmd.props);
        next.props = r.next;
        inverse.props = r.inverse;
      }
      if (cmd.styles) {
        const styles = { ...node.styles };
        const inv: StylePatch = {};
        for (const [bp, patch] of Object.entries(cmd.styles) as Array<[keyof StylePatch, Record<string, unknown>]>) {
          if (!patch) continue;
          const r = applyPatch((styles[bp] ?? {}) as Record<string, unknown>, patch);
          inv[bp] = r.inverse as Record<string, string | number | null>;
          if (Object.keys(r.next).length === 0) delete styles[bp];
          else styles[bp] = r.next as Record<string, string | number>;
        }
        next.styles = styles;
        inverse.styles = inv;
      }
      const components = { ...doc.components, [cmd.id]: next };
      return { doc: withAssets({ ...doc, components }), inverse: [inverse] };
    }

    case 'page.add': {
      if (doc.pages.some((p) => p.id === cmd.page.id)) throw new CommandError('duplicate_id', `page "${cmd.page.id}" already exists`);
      const root = cmd.nodes[0];
      if (root.type !== 'Page' || root.id !== cmd.page.rootId) throw new CommandError('invalid_root', 'first node must be the Page root');
      const components = { ...doc.components };
      const seen = new Set<string>();
      for (const node of cmd.nodes) {
        if (has(components, node.id) || seen.has(node.id)) throw new CommandError('duplicate_id', `component "${node.id}" already exists`);
        seen.add(node.id);
        components[node.id] = clone(node);
      }
      const pages = [...doc.pages];
      pages.splice(clampIndex(cmd.index, pages.length), 0, clone(cmd.page));
      return { doc: withAssets({ ...doc, pages, components }), inverse: [{ type: 'page.remove', id: cmd.page.id }] };
    }

    case 'page.remove': {
      const index = doc.pages.findIndex((p) => p.id === cmd.id);
      if (index < 0) throw new CommandError('not_found', `page "${cmd.id}" does not exist`);
      if (doc.settings.homePageId === cmd.id) throw new CommandError('home_page', 'the home page cannot be removed');
      if (doc.settings.notFoundPageId === cmd.id) throw new CommandError('not_found_page', 'unset the 404 page before removing it');
      const page = doc.pages[index];
      const subtree = collectSubtree(doc, page.rootId);
      const components = { ...doc.components };
      for (const n of subtree) delete components[n.id];
      const pages = doc.pages.filter((p) => p.id !== cmd.id);
      return {
        doc: withAssets({ ...doc, pages, components }),
        inverse: [{ type: 'page.add', page: clone(page), index, nodes: subtree.map(clone) }],
      };
    }

    case 'page.update': {
      const index = doc.pages.findIndex((p) => p.id === cmd.id);
      if (index < 0) throw new CommandError('not_found', `page "${cmd.id}" does not exist`);
      const page = doc.pages[index];
      const next = { ...page };
      const inverse: Extract<AppCommand, { type: 'page.update' }>['changes'] = {};
      if (cmd.changes.name !== undefined) {
        inverse.name = page.name;
        next.name = cmd.changes.name;
      }
      if (cmd.changes.path !== undefined) {
        inverse.path = page.path;
        next.path = cmd.changes.path;
      }
      if (cmd.changes.seo !== undefined) {
        inverse.seo = clone(page.seo);
        next.seo = { ...page.seo, ...clone(cmd.changes.seo) };
      }
      const pages = [...doc.pages];
      pages[index] = next;
      return { doc: { ...doc, pages }, inverse: [{ type: 'page.update', id: cmd.id, changes: inverse }] };
    }

    case 'tokens.update': {
      const t = doc.designTokens;
      const inverse: Extract<AppCommand, { type: 'tokens.update' }>['changes'] = {};
      const next = { ...t };
      if (cmd.changes.colors) {
        const old: Record<string, string> = {};
        for (const k of Object.keys(cmd.changes.colors)) {
          if (!has(t.colors, k)) throw new CommandError('unknown_token', `unknown color token "${k}"`);
          old[k] = (t.colors as Record<string, string>)[k];
        }
        inverse.colors = old;
        next.colors = { ...t.colors, ...cmd.changes.colors };
      }
      if (cmd.changes.fonts) {
        const old: Record<string, string> = {};
        for (const k of Object.keys(cmd.changes.fonts)) {
          if (!has(t.fonts, k)) throw new CommandError('unknown_token', `unknown font token "${k}"`);
          old[k] = (t.fonts as Record<string, string>)[k];
        }
        inverse.fonts = old as Partial<AppDefinition['designTokens']['fonts']>;
        next.fonts = { ...t.fonts, ...cmd.changes.fonts };
      }
      if (cmd.changes.radius !== undefined) {
        inverse.radius = t.radius;
        next.radius = cmd.changes.radius;
      }
      return { doc: { ...doc, designTokens: next }, inverse: [{ type: 'tokens.update', changes: inverse }] };
    }

    case 'site.update': {
      const inverse: Extract<AppCommand, { type: 'site.update' }>['changes'] = {};
      const next = { ...doc };
      if (cmd.changes.name !== undefined) {
        inverse.name = doc.name;
        next.name = cmd.changes.name;
      }
      if (cmd.changes.seo) {
        inverse.seo = clone(doc.seo);
        next.seo = { ...doc.seo, ...cmd.changes.seo };
      }
      if (cmd.changes.settings) {
        const old: Record<string, unknown> = {};
        for (const k of Object.keys(cmd.changes.settings)) {
          old[k] = has(doc.settings, k) ? (doc.settings as Record<string, unknown>)[k] : null;
        }
        inverse.settings = old as Partial<AppDefinition['settings']>;
        next.settings = { ...doc.settings, ...cmd.changes.settings };
      }
      return { doc: next, inverse: [{ type: 'site.update', changes: inverse }] };
    }

    default: {
      const t = (cmd as { type?: unknown }).type;
      throw new CommandError('unknown_command', `unsupported command "${String(t)}"`);
    }
  }
}

/** Apply a batch atomically. Inverses are returned in undo order (last command first). */
export function applyCommands(doc: AppDefinition, cmds: AppCommand[]): ApplyResult {
  let current = doc;
  const inverses: AppCommand[][] = [];
  for (const cmd of cmds) {
    const r = applyCommand(current, cmd);
    current = r.doc;
    inverses.push(r.inverse);
  }
  return { doc: current, inverse: inverses.reverse().flat() };
}
