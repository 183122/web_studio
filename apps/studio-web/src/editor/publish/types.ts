export interface DeployStep { step: string; status: 'ok' | 'warning' | 'failed'; message: string; at: string }

export interface Deployment {
  id: string;
  action: 'publish' | 'rollback' | 'unpublish';
  status: 'queued' | 'running' | 'succeeded' | 'failed';
  releaseId: string | null;
  sourceRevision: number | null;
  steps: DeployStep[];
  error: { code: string; message: string } | null;
  createdAt: string;
  finishedAt: string | null;
}

export interface DnsRecord { type: string; name: string; value: string; purpose: string }

export interface SiteDomain {
  id: string;
  hostname: string;
  type: 'subdomain' | 'custom';
  status: 'active' | 'verified' | 'pending_verification' | 'released';
  url: string;
  checks: Record<'ownership' | 'routing' | 'certificate', { ok: boolean; message: string }> | null;
  lastCheckedAt: string | null;
  instructions: { apex: boolean; records: DnsRecord[]; note: string } | null;
}

export interface ReleaseRow {
  id: string;
  version: number;
  sourceRevision: number;
  status: 'building' | 'ready' | 'failed';
  warnings: string[];
  isLive: boolean;
  createdAt: string;
}

export interface PublishingOverview {
  baseDomain: string;
  subdomain: SiteDomain | null;
  live: { releaseId: string; version: number; sourceRevision: number; publishedAt: string } | null;
  headRevision: number;
  hasUnpublishedChanges: boolean;
  requiresVerifiedEmail: boolean;
  releases: ReleaseRow[];
  deployments: Deployment[];
  domains: SiteDomain[];
}

export interface PreviewLinkRow { id: string; revision: number; expiresAt: string; revokedAt: string | null; createdAt: string }
