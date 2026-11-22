import registryJson from '../registry.json' with { type: 'json' };

export type PropKind =
  | 'text'
  | 'longtext'
  | 'url'
  | 'select'
  | 'boolean'
  | 'image'
  | 'slug'
  | 'videoId'
  | 'links'
  | 'stringList'
  | 'model'
  | 'field'
  | 'fieldList';

export interface PropDefinition {
  kind: PropKind;
  label: string;
  default: unknown;
  maxLength?: number;
  maxItems?: number;
  options?: string[];
}

export type StyleKind = 'enum' | 'integer' | 'number' | 'length' | 'box' | 'color';

export interface StylePropertyDefinition {
  kind: StyleKind;
  values?: string[];
  min?: number;
  max?: number;
}

export type Breakpoint = 'base' | 'tablet' | 'mobile';
export type StyleMap = Record<string, string | number>;
export type ResponsiveStyles = Partial<Record<Breakpoint, StyleMap>>;

export interface ComponentDefinition {
  label: string;
  labelTh: string;
  category: 'system' | 'layout' | 'basic' | 'media' | 'navigation' | 'data';
  icon: string;
  acceptsChildren: boolean;
  insertable: boolean;
  props: Record<string, PropDefinition>;
  defaultStyles: ResponsiveStyles;
}

export interface ComponentRegistry {
  registryVersion: number;
  styleProperties: Record<string, StylePropertyDefinition>;
  components: Record<string, ComponentDefinition>;
}

export const registry = registryJson as unknown as ComponentRegistry;

export const BREAKPOINTS: Breakpoint[] = ['base', 'tablet', 'mobile'];

export const BREAKPOINT_WIDTHS: Record<Breakpoint, number> = {
  base: 1280,
  tablet: 820,
  mobile: 390,
};

export function getComponentDefinition(type: string): ComponentDefinition | undefined {
  return Object.prototype.hasOwnProperty.call(registry.components, type)
    ? registry.components[type]
    : undefined;
}

export function defaultPropsFor(type: string): Record<string, unknown> {
  const def = getComponentDefinition(type);
  if (!def) return {};
  const out: Record<string, unknown> = {};
  for (const [key, prop] of Object.entries(def.props)) {
    out[key] = structuredClone(prop.default);
  }
  return out;
}

export function insertableComponents(): Array<[string, ComponentDefinition]> {
  return Object.entries(registry.components).filter(([, def]) => def.insertable);
}
