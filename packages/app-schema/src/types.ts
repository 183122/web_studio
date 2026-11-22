import type { ResponsiveStyles, Breakpoint } from '@studio/component-registry';

export type { ResponsiveStyles, Breakpoint };

export const SCHEMA_VERSION = 2 as const;

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

export type FieldType =
  | 'text' | 'longtext' | 'number' | 'integer' | 'boolean' | 'date' | 'datetime' | 'email' | 'phone' | 'url' | 'select';

export interface FieldDefinition {
  id: string;
  /** Machine name used by the REST API and CSV (a-z, 0-9, _). */
  name: string;
  label: string;
  type: FieldType;
  required: boolean;
  unique: boolean;
  options?: string[];
  maxLength?: number;
  min?: number;
  max?: number;
  helpText?: string;
}

export interface DataModel {
  id: string;
  name: string;
  label: string;
  fields: FieldDefinition[];
  displayFieldId: string;
}

export type RecordAction = 'create' | 'read' | 'update' | 'delete';
export type AppRole = 'public' | 'member' | 'admin';

export interface Permission {
  modelId: string;
  rules: Record<AppRole, RecordAction[]>;
}

export type ConditionOp = 'eq' | 'neq' | 'contains' | 'empty' | 'not_empty' | 'gt' | 'lt';
export type Scalar = string | number | boolean | null;

export type WorkflowAction =
  | { type: 'record.setField'; fieldId: string; value: Scalar }
  | { type: 'email.send'; to: string; subject: string; body: string };

export interface Workflow {
  id: string;
  name: string;
  enabled: boolean;
  trigger: { event: 'record.created' | 'record.updated'; modelId: string };
  conditions: Array<{ fieldId: string; op: ConditionOp; value?: Scalar }>;
  actions: WorkflowAction[];
}

export interface AppDefinition {
  schemaVersion: typeof SCHEMA_VERSION;
  projectId: string;
  name: string;
  pages: PageDefinition[];
  routes: Redirect[];
  components: Record<string, ComponentNode>;
  designTokens: DesignTokens;
  dataModels: DataModel[];
  permissions: Permission[];
  actions: never[];
  workflows: Workflow[];
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
  | { type: 'model.upsert'; model: DataModel; index: number }
  | { type: 'model.remove'; id: string }
  | { type: 'permission.set'; permission: Permission }
  | { type: 'permission.remove'; modelId: string }
  | { type: 'workflow.upsert'; workflow: Workflow; index: number }
  | { type: 'workflow.remove'; id: string }
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
