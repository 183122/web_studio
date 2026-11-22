import { registry, getComponentDefinition, type PropDefinition } from '@studio/component-registry';
import type { AppDefinition, ValidationIssue } from './types.ts';
import { validateDataSemantics } from './semantic-data.ts';
import {
  LIMITS,
  PATTERNS,
  codePointLength,
  isSafeHref,
  isSafeImageSource,
} from './rules.ts';

const isPlainObject = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);

const has = (obj: object, key: string) => Object.prototype.hasOwnProperty.call(obj, key);

function validateText(value: unknown, def: PropDefinition, allowNewlines: boolean): string | null {
  if (typeof value !== 'string') return 'must be a string';
  if (def.maxLength !== undefined && codePointLength(value) > def.maxLength) return `must be at most ${def.maxLength} characters`;
  if (PATTERNS.forbiddenControl.test(value)) return 'contains control characters';
  if (!allowNewlines && PATTERNS.newline.test(value)) return 'must be a single line';
  return null;
}

export function validatePropValue(def: PropDefinition, value: unknown): string | null {
  switch (def.kind) {
    case 'text':
      return validateText(value, def, false);
    case 'longtext':
      return validateText(value, def, true);
    case 'url':
      if (typeof value !== 'string' || !isSafeHref(value)) return 'must be a safe link (/, #, http(s)://, mailto:, tel:)';
      return null;
    case 'select':
      if (typeof value !== 'string' || !(def.options ?? []).includes(value)) return 'is not an allowed option';
      return null;
    case 'boolean':
      return typeof value === 'boolean' ? null : 'must be a boolean';
    case 'image':
      if (typeof value !== 'string' || !isSafeImageSource(value)) return 'must be empty, asset:<id> or an https:// URL';
      return null;
    case 'slug':
      if (typeof value !== 'string' || (value !== '' && !PATTERNS.slug.test(value))) return 'must be a lowercase slug';
      return null;
    case 'videoId':
      if (typeof value !== 'string' || (value !== '' && !PATTERNS.videoId.test(value))) return 'must be a video ID';
      return null;
    case 'links': {
      if (!Array.isArray(value)) return 'must be a list';
      if (def.maxItems !== undefined && value.length > def.maxItems) return `must have at most ${def.maxItems} items`;
      for (const item of value) {
        if (!isPlainObject(item)) return 'items must be objects';
        const keys = Object.keys(item).sort().join(',');
        if (keys !== 'href,label') return 'items must have exactly label and href';
        const labelErr = validateText(item.label, { kind: 'text', label: '', default: '', maxLength: 80 }, false);
        if (labelErr) return `item label ${labelErr}`;
        if (typeof item.href !== 'string' || !isSafeHref(item.href)) return 'item href must be a safe link';
      }
      return null;
    }
    case 'model':
    case 'field':
      // Referential checks live in validateDataSemantics.
      return typeof value === 'string' && (value === '' || /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(value)) ? null : 'must be an id';
    case 'fieldList':
      if (!Array.isArray(value) || value.some((v) => typeof v !== 'string' || !/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(v))) return 'must be a list of ids';
      if (def.maxItems !== undefined && value.length > def.maxItems) return `must have at most ${def.maxItems} items`;
      return null;
    case 'stringList': {
      if (!Array.isArray(value)) return 'must be a list';
      if (def.maxItems !== undefined && value.length > def.maxItems) return `must have at most ${def.maxItems} items`;
      for (const item of value) {
        const err = validateText(item, def, false);
        if (err) return `item ${err}`;
      }
      return null;
    }
    default:
      return 'has an unsupported kind';
  }
}

export function validateStyleValue(property: string, value: unknown): string | null {
  if (!has(registry.styleProperties, property)) return 'is not an allowed style property';
  const def = registry.styleProperties[property];
  switch (def.kind) {
    case 'enum':
      return typeof value === 'string' && (def.values ?? []).includes(value) ? null : 'is not an allowed value';
    case 'integer':
      return typeof value === 'number' && Number.isInteger(value) && value >= (def.min ?? -Infinity) && value <= (def.max ?? Infinity)
        ? null
        : `must be an integer between ${def.min} and ${def.max}`;
    case 'number':
      return typeof value === 'number' && Number.isFinite(value) && value >= (def.min ?? -Infinity) && value <= (def.max ?? Infinity)
        ? null
        : `must be a number between ${def.min} and ${def.max}`;
    case 'length':
      return typeof value === 'string' && PATTERNS.length.test(value) ? null : 'must be a CSS length (e.g. 16px, 1.5rem, 50%)';
    case 'box':
      return typeof value === 'string' && PATTERNS.box.test(value) ? null : 'must be 1–4 CSS lengths';
    case 'color':
      return typeof value === 'string' && (PATTERNS.hexColor.test(value) || PATTERNS.colorToken.test(value) || value === 'transparent')
        ? null
        : 'must be a hex color, a color token ($primary…) or transparent';
    default:
      return 'has an unsupported kind';
  }
}

/** Sorted unique asset IDs referenced from image props (asset:<id>). */
export function collectAssetRefs(doc: Pick<AppDefinition, 'components'>): string[] {
  const ids = new Set<string>();
  for (const node of Object.values(doc.components)) {
    const def = getComponentDefinition(node.type);
    if (!def) continue;
    for (const [key, prop] of Object.entries(def.props)) {
      if (prop.kind !== 'image') continue;
      const value = node.props[key];
      if (typeof value === 'string' && PATTERNS.assetRef.test(value)) ids.add(value.slice('asset:'.length));
    }
  }
  return [...ids].sort();
}

/**
 * Semantic validation (assumes the document already passed structural JSON Schema validation).
 */
export function validateSemantics(doc: AppDefinition): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  const add = (path: string, code: string, message: string) => issues.push({ path, code, message });

  const components = doc.components;
  const componentIds = Object.keys(components);
  if (componentIds.length > LIMITS.maxComponents) add('/components', 'too_many_components', `at most ${LIMITS.maxComponents} components`);

  // Component-level checks
  const parentOf = new Map<string, string>();
  for (const id of componentIds) {
    const node = components[id];
    const base = `/components/${id}`;
    if (node.id !== id) add(`${base}/id`, 'id_mismatch', 'component id must equal its key');
    const def = getComponentDefinition(node.type);
    if (!def) {
      add(`${base}/type`, 'unknown_component', `component type "${node.type}" is not allowed`);
      continue;
    }
    for (const [key, value] of Object.entries(node.props)) {
      if (!has(def.props, key)) {
        add(`${base}/props/${key}`, 'unknown_prop', `prop "${key}" is not allowed on ${node.type}`);
        continue;
      }
      const err = validatePropValue(def.props[key], value);
      if (err) add(`${base}/props/${key}`, 'invalid_prop', `${key} ${err}`);
    }
    for (const [bp, map] of Object.entries(node.styles)) {
      if (!map) continue;
      if (Object.keys(map).length === 0) add(`${base}/styles/${bp}`, 'empty_style_map', 'empty breakpoint style maps must be omitted');
      for (const [prop, value] of Object.entries(map)) {
        const err = validateStyleValue(prop, value);
        if (err) add(`${base}/styles/${bp}/${prop}`, 'invalid_style', `${prop} ${err}`);
      }
    }
    if (!def.acceptsChildren && node.children.length > 0) add(`${base}/children`, 'children_not_allowed', `${node.type} cannot contain children`);
    node.children.forEach((childId, i) => {
      if (!has(components, childId)) {
        add(`${base}/children/${i}`, 'missing_child', `child "${childId}" does not exist`);
        return;
      }
      if (components[childId].type === 'Page') add(`${base}/children/${i}`, 'invalid_child', 'a Page cannot be nested');
      if (parentOf.has(childId)) add(`${base}/children/${i}`, 'multiple_parents', `component "${childId}" has more than one parent`);
      else parentOf.set(childId, id);
    });
  }

  // Pages
  const pageIds = new Set<string>();
  const paths = new Set<string>();
  const roots = new Set<string>();
  if (doc.pages.length > LIMITS.maxPages) add('/pages', 'too_many_pages', `at most ${LIMITS.maxPages} pages`);
  doc.pages.forEach((page, i) => {
    const base = `/pages/${i}`;
    if (pageIds.has(page.id)) add(`${base}/id`, 'duplicate_page_id', 'duplicate page id');
    pageIds.add(page.id);
    if (paths.has(page.path)) add(`${base}/path`, 'route_conflict', `path "${page.path}" is used by more than one page`);
    paths.add(page.path);
    const root = components[page.rootId];
    if (!root) add(`${base}/rootId`, 'missing_root', 'page root component does not exist');
    else if (root.type !== 'Page') add(`${base}/rootId`, 'invalid_root', 'page root must be a Page component');
    if (parentOf.has(page.rootId)) add(`${base}/rootId`, 'invalid_root', 'page root cannot be a child');
    if (roots.has(page.rootId)) add(`${base}/rootId`, 'shared_root', 'pages cannot share a root');
    roots.add(page.rootId);
  });
  for (const id of componentIds) {
    if (components[id].type === 'Page' && !roots.has(id)) add(`/components/${id}`, 'orphan_page_root', 'Page component is not attached to a page');
  }

  if (!pageIds.has(doc.settings.homePageId)) add('/settings/homePageId', 'missing_home', 'home page does not exist');
  if (doc.settings.notFoundPageId && !pageIds.has(doc.settings.notFoundPageId)) add('/settings/notFoundPageId', 'missing_page', '404 page does not exist');

  // Redirects
  const froms = new Set<string>();
  const redirectIds = new Set<string>();
  doc.routes.forEach((r, i) => {
    if (redirectIds.has(r.id)) add(`/routes/${i}/id`, 'duplicate_route_id', 'duplicate redirect id');
    redirectIds.add(r.id);
    if (froms.has(r.from) || paths.has(r.from)) add(`/routes/${i}/from`, 'route_conflict', `redirect source "${r.from}" conflicts with a page or redirect`);
    froms.add(r.from);
  });

  // Reachability, depth, cycles
  const visited = new Set<string>();
  for (const page of doc.pages) {
    if (!has(components, page.rootId)) continue;
    const stack: Array<[string, number]> = [[page.rootId, 1]];
    while (stack.length) {
      const [id, depth] = stack.pop()!;
      if (visited.has(id)) {
        add(`/components/${id}`, 'cycle', 'component graph contains a cycle or shared node');
        continue;
      }
      visited.add(id);
      if (depth > LIMITS.maxDepth) {
        add(`/components/${id}`, 'too_deep', `nesting deeper than ${LIMITS.maxDepth} levels`);
        continue;
      }
      const node = components[id];
      for (let i = node.children.length - 1; i >= 0; i--) {
        const c = node.children[i];
        if (has(components, c)) stack.push([c, depth + 1]);
      }
    }
  }
  for (const id of componentIds) {
    if (!visited.has(id) && components[id].type !== 'Page') add(`/components/${id}`, 'orphan', 'component is not reachable from any page');
  }

  issues.push(...validateDataSemantics(doc, parentOf));

  // Derived asset list must be consistent
  const expectedAssets = collectAssetRefs(doc);
  if (expectedAssets.join(',') !== [...doc.assets].join(',')) add('/assets', 'assets_mismatch', 'assets must list exactly the referenced asset ids (sorted)');

  return issues;
}
