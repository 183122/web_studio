export interface User {
  id: string;
  name: string;
  email: string;
  emailVerified: boolean;
  locale: string;
}

export type WorkspaceRole = 'admin' | 'editor' | 'developer' | 'viewer';

export interface Me {
  user: User;
  organizations: Array<{
    id: string;
    name: string;
    role: string;
    plan: { code: string; name: string } | null;
    workspaces: Array<{ id: string; name: string; slug: string; role: WorkspaceRole | null }>;
  }>;
}

export interface Project {
  id: string;
  workspaceId: string;
  name: string;
  slug: string;
  description: string;
  status: 'active' | 'archived';
  isTrashed: boolean;
  isFavorite: boolean;
  revision?: number | null;
  template?: { slug: string; name: string } | null;
  role?: WorkspaceRole;
  createdAt: string;
  updatedAt: string;
  deletedAt: string | null;
}

export interface Template {
  id: string;
  slug: string;
  name: string;
  description: string;
  category: string;
  version: number | null;
}

export interface Asset {
  id: string;
  ref: string;
  name: string;
  mime: string;
  sizeBytes: number;
  width: number | null;
  height: number | null;
  scanStatus: string;
  url: string | null;
  createdAt: string;
}

export interface Revision {
  revision: number;
  source: string;
  message: string | null;
  contentHash: string;
  sizeBytes: number;
  restoredFromRevision: number | null;
  author: { id: string; name: string } | null;
  createdAt: string;
}

export interface WorkspaceSummary {
  id: string;
  name: string;
  role: WorkspaceRole;
  organization: { id: string; name: string; plan: { code: string; name: string } | null };
  usage: { projects: { used: number; limit: number }; storage_bytes: { used: number; limit: number } };
}

export interface RecordRow {
  id: string;
  version: number;
  data: Record<string, unknown>;
  source: string;
  createdAt: string;
  updatedAt: string;
}

export interface ListMeta {
  currentPage: number;
  lastPage: number;
  total: number;
  perPage?: number;
}

export interface ApiKey {
  id: string;
  name: string;
  prefix: string;
  environment: string;
  role: 'public' | 'member' | 'admin';
  scopes: string[];
  lastUsedAt: string | null;
  revokedAt: string | null;
  createdAt: string;
  token?: string;
}

export interface WorkflowRunRow {
  id: number;
  workflowId: string;
  recordId: string;
  event: string;
  status: string;
  steps: Array<Record<string, unknown>>;
  error: string | null;
  createdAt: string;
}

export interface ImpactReport {
  totalRecords: number;
  requiresConfirmation: boolean;
  fields: Array<{ fieldId: string; name: string; change: string; invalidRecords?: number; duplicateValues?: number; recordsWithData?: number }>;
}
