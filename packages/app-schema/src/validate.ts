import { validateAppCommand, validateAppDefinition } from './generated/validators.js';
import type { AppCommand, AppDefinition, ValidationIssue } from './types.ts';
import { validateSemantics } from './semantic.ts';

type AjvError = { instancePath: string; keyword: string; message?: string };

function toIssues(errors: AjvError[] | null | undefined): ValidationIssue[] {
  return (errors ?? []).map((e) => ({
    path: e.instancePath || '/',
    code: `schema.${e.keyword}`,
    message: e.message ?? 'is invalid',
  }));
}

export function validateStructure(doc: unknown): ValidationIssue[] {
  return validateAppDefinition(doc) ? [] : toIssues(validateAppDefinition.errors as AjvError[]);
}

export function validateCommandShape(cmd: unknown): ValidationIssue[] {
  return validateAppCommand(cmd) ? [] : toIssues(validateAppCommand.errors as AjvError[]);
}

/** Full validation: structural JSON Schema first, then semantic rules. */
export function validateDefinition(doc: unknown): ValidationIssue[] {
  const structural = validateStructure(doc);
  if (structural.length > 0) return structural;
  return validateSemantics(doc as AppDefinition);
}

export function isValidCommand(cmd: unknown): cmd is AppCommand {
  return validateCommandShape(cmd).length === 0;
}
