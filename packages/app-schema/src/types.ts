import type { ResponsiveStyles, Breakpoint } from '@studio/component-registry';

export type { ResponsiveStyles, Breakpoint };

export const SCHEMA_VERSION = 1 as const;

export interface ComponentNode {
  id: string;
  type: string;
  name?: string;
  props: Record<string, unknown>;
  styles: ResponsiveStyles;
  children: string[];
}

export interface PageSeo {
  title: string;
  description: string;
  noindex: boolean;
}

export interface PageDefinition {
  id: string;
  name: string;
  path: string;
  rootId: string;
  seo: PageSeo;
}

export interface Redirect {
  id: string;
  from: string;
  to: string;
  status: 301 | 302;
}

export type FontFamily = 'Kanit' | 'Sarabun' | 'Prompt' | 'Noto Sans Thai' | 'system';

export interface DesignTokens {
  colors: {
    primary: string;
    secondary: string;
    accent: string;
    background: string;
    surface: string;
    text: string;
    muted: string;
  };
  fonts: { heading: FontFamily; body: FontFamily };
  radius: string;
}

export interface AppDefinition {
  schemaVersion: typeof SCHEMA_VERSION;
  projectId: string;
  name: string;
  pages: PageDefinition[];
  routes: Redirect[];
  components: Record<string, ComponentNode>;
  designTokens: DesignTokens;
  dataModels: never[];
  permissions: never[];
  actions: never[];
  workflows: never[];
  integrations: never[];
  assets: string[];
  seo: { siteName: string; description: string; lang: 'th' | 'en' };
  settings: { homePageId: string; notFoundPageId?: string | null };
}

export type StylePatch = Partial<Record<Breakpoint, Record<string, string | number | null>>>;

export type AppCommand =
  | { type: 'component.insert'; parentId: string; index: number; nodes: ComponentNode[] }
  | { type: 'component.remove'; id: string }
  | { type: 'component.move'; id: string; parentId: string; index: number }
  | {
      type: 'component.update';
      id: string;
      name?: string | null;
      props?: Record<string, unknown>;
      styles?: StylePatch;
    }
  | { type: 'page.add'; page: PageDefinition; index: number; nodes: ComponentNode[] }
  | { type: 'page.remove'; id: string }
  | {
      type: 'page.update';
      id: string;
      changes: { name?: string; path?: string; seo?: Partial<PageSeo> };
    }
  | {
      type: 'tokens.update';
      changes: {
        colors?: Partial<DesignTokens['colors']>;
        fonts?: Partial<DesignTokens['fonts']>;
        radius?: string;
      };
    }
  | {
      type: 'site.update';
      changes: {
        name?: string;
        seo?: Partial<AppDefinition['seo']>;
        settings?: Partial<AppDefinition['settings']>;
      };
    };

export interface ValidationIssue {
  path: string;
  code: string;
  message: string;
}
