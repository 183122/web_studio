import type { AppDefinition } from './types.ts';
import { SCHEMA_VERSION } from './types.ts';

/**
 * Upgrades older documents to the current schemaVersion. Mirror: Domain\Schema\Upcaster (PHP).
 * v1 → v2: v1 forced dataModels/permissions/workflows to be empty, so only the version changes.
 */
export function upcast(input: unknown): unknown {
  if (!input || typeof input !== 'object') return input;
  const doc = { ...(input as Record<string, unknown>) };
  if (doc.schemaVersion === 1) doc.schemaVersion = 2;
  return doc as unknown as AppDefinition;
}

export const CURRENT_SCHEMA_VERSION = SCHEMA_VERSION;
