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
