import { defaultPropsFor, getComponentDefinition } from '@studio/component-registry';
import type { AppDefinition, ComponentNode, DesignTokens, PageDefinition } from './types.ts';
import { SCHEMA_VERSION } from './types.ts';

const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** Random, URL-safe id (≈59 bits of entropy per 10 chars). */
export function generateId(prefix = 'c'): string {
  const bytes = new Uint8Array(10);
  globalThis.crypto.getRandomValues(bytes);
  let out = '';
  for (const b of bytes) out += ALPHABET[b % ALPHABET.length];
  return `${prefix}_${out}`;
}

export const DEFAULT_TOKENS: DesignTokens = {
  colors: {
    primary: '#0f766e',
    secondary: '#1e3a5f',
    accent: '#f59e0b',
    background: '#ffffff',
    surface: '#f8fafc',
    text: '#0f172a',
    muted: '#64748b',
  },
  fonts: { heading: 'Kanit', body: 'Sarabun' },
  radius: '12px',
};

export function createNode(type: string, overrides: Partial<ComponentNode> = {}): ComponentNode {
  const def = getComponentDefinition(type);
  if (!def) throw new Error(`Unknown component type ${type}`);
  const styles = structuredClone(def.defaultStyles);
  for (const bp of Object.keys(styles) as Array<keyof typeof styles>) {
    if (styles[bp] && Object.keys(styles[bp]!).length === 0) delete styles[bp];
  }
  return {
    id: overrides.id ?? generateId(type === 'Page' ? 'p' : 'c'),
    type,
    props: { ...defaultPropsFor(type), ...(overrides.props ?? {}) },
    styles: overrides.styles ?? styles,
    children: overrides.children ?? [],
    ...(overrides.name ? { name: overrides.name } : {}),
  };
}

export function createPage(name: string, path: string): { page: PageDefinition; root: ComponentNode } {
  const root = createNode('Page');
  const page: PageDefinition = {
    id: generateId('pg'),
    name,
    path,
    rootId: root.id,
    seo: { title: name, description: '', noindex: false },
  };
  return { page, root };
}

export function createBlankDefinition(projectId: string, name: string): AppDefinition {
  const { page, root } = createPage('หน้าแรก', '/');
  return {
    schemaVersion: SCHEMA_VERSION,
    projectId,
    name,
    pages: [page],
    routes: [],
    components: { [root.id]: root },
    designTokens: structuredClone(DEFAULT_TOKENS),
    dataModels: [],
    permissions: [],
    actions: [],
    workflows: [],
    integrations: [],
    assets: [],
    seo: { siteName: name, description: '', lang: 'th' },
    settings: { homePageId: page.id, notFoundPageId: null },
  };
}

/** Deep-copy a subtree giving every node a fresh id (used by copy/paste and duplicate). */
export function cloneWithNewIds(nodes: ComponentNode[]): ComponentNode[] {
  const map = new Map<string, string>();
  for (const n of nodes) map.set(n.id, generateId('c'));
  return nodes.map((n) => ({
    ...structuredClone(n),
    id: map.get(n.id)!,
    children: n.children.map((c) => map.get(c) ?? c),
  }));
}

// ---------------- Phase 2 factories ----------------
import type { DataModel, FieldDefinition, FieldType, Permission } from './types.ts';

export function createField(label: string, type: FieldType, name: string): FieldDefinition {
  return {
    id: generateId('f'),
    name,
    label,
    type,
    required: false,
    unique: false,
    ...(type === 'select' ? { options: ['ตัวเลือก 1', 'ตัวเลือก 2'] } : {}),
  };
}

/** New model with a required title field and an explicit default-deny permission. */
export function createModel(label: string, name: string): { model: DataModel; permission: Permission } {
  const title = { ...createField('ชื่อ', 'text', 'title'), required: true };
  const model: DataModel = { id: generateId('m'), name, label, fields: [title], displayFieldId: title.id };
  return { model, permission: { modelId: model.id, rules: { public: [], member: ['read'], admin: ['create', 'read', 'update', 'delete'] } } };
}
