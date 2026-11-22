import type { AppDefinition, ComponentNode, DataModel, ValidationIssue } from './types.ts';
import { DATA_PATTERNS, PATTERNS, RESERVED_FIELD_NAMES, validateFieldValue } from './rules.ts';

const TEXT_LIKE = new Set(['text', 'longtext', 'email', 'phone', 'url']);
const NUMERIC = new Set(['number', 'integer']);
const VALUE_OPS = new Set(['eq', 'neq', 'contains', 'gt', 'lt']);

/** Phase 2 rules: data models, permissions, workflows and data-bound components. Mirror: DataSemanticValidator.php */
export function validateDataSemantics(doc: AppDefinition, parentOf: Map<string, string>): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  const add = (path: string, code: string, message: string) => issues.push({ path, code, message });

  const models = new Map<string, DataModel>();
  const modelNames = new Set<string>();
  const fieldOwner = new Map<string, string>(); // fieldId -> modelId
  doc.dataModels.forEach((m, i) => {
    const base = `/dataModels/${i}`;
    if (models.has(m.id)) add(`${base}/id`, 'duplicate_model', 'duplicate model id');
    if (modelNames.has(m.name)) add(`${base}/name`, 'duplicate_model', `model name "${m.name}" is used twice`);
    models.set(m.id, m);
    modelNames.add(m.name);
    if (PATTERNS.newline.test(m.label) || PATTERNS.forbiddenControl.test(m.label)) add(`${base}/label`, 'invalid_label', 'label must be a single line');
    const names = new Set<string>();
    m.fields.forEach((f, j) => {
      const fb = `${base}/fields/${j}`;
      if (fieldOwner.has(f.id) || models.has(f.id)) add(`${fb}/id`, 'duplicate_field', `field id "${f.id}" is not unique`);
      fieldOwner.set(f.id, m.id);
      if (names.has(f.name)) add(`${fb}/name`, 'duplicate_field', `field name "${f.name}" is used twice`);
      names.add(f.name);
      if ((RESERVED_FIELD_NAMES as readonly string[]).includes(f.name)) add(`${fb}/name`, 'reserved_name', `"${f.name}" is reserved`);
      if (PATTERNS.newline.test(f.label) || PATTERNS.forbiddenControl.test(f.label)) add(`${fb}/label`, 'invalid_label', 'label must be a single line');
      if (f.type === 'select' && !f.options) add(`${fb}/options`, 'invalid_field', 'select fields need options');
      if (f.type !== 'select' && f.options !== undefined) add(`${fb}/options`, 'invalid_field', 'options are only allowed on select fields');
      if (f.maxLength !== undefined && !TEXT_LIKE.has(f.type)) add(`${fb}/maxLength`, 'invalid_field', 'maxLength is only allowed on text fields');
      if ((f.min !== undefined || f.max !== undefined) && !NUMERIC.has(f.type)) add(`${fb}/min`, 'invalid_field', 'min/max are only allowed on number fields');
      if (f.min !== undefined && f.max !== undefined && f.min > f.max) add(`${fb}/min`, 'invalid_field', 'min must not exceed max');
      if (f.unique && (f.type === 'longtext' || f.type === 'boolean')) add(`${fb}/unique`, 'invalid_field', `${f.type} fields cannot be unique`);
    });
    if (!m.fields.some((f) => f.id === m.displayFieldId)) add(`${base}/displayFieldId`, 'missing_field', 'display field must be one of the model fields');
  });

  const fieldOf = (modelId: string, fieldId: string) => models.get(modelId)?.fields.find((f) => f.id === fieldId);

  // Permissions: exactly one entry per model (explicit, default deny).
  const permitted = new Set<string>();
  doc.permissions.forEach((p, i) => {
    if (!models.has(p.modelId)) add(`/permissions/${i}/modelId`, 'missing_model', 'permission refers to an unknown model');
    if (permitted.has(p.modelId)) add(`/permissions/${i}/modelId`, 'duplicate_permission', 'only one permission entry per model');
    permitted.add(p.modelId);
  });
  doc.dataModels.forEach((m, i) => {
    if (!permitted.has(m.id)) add(`/dataModels/${i}`, 'missing_permission', `model "${m.name}" has no permission entry`);
  });

  // Workflows
  const workflowIds = new Set<string>();
  doc.workflows.forEach((w, i) => {
    const base = `/workflows/${i}`;
    if (workflowIds.has(w.id)) add(`${base}/id`, 'duplicate_workflow', 'duplicate workflow id');
    workflowIds.add(w.id);
    const model = models.get(w.trigger.modelId);
    if (!model) {
      add(`${base}/trigger/modelId`, 'missing_model', 'trigger refers to an unknown model');
      return;
    }
    w.conditions.forEach((c, j) => {
      const field = fieldOf(model.id, c.fieldId);
      if (!field) add(`${base}/conditions/${j}/fieldId`, 'missing_field', 'condition refers to a field outside the trigger model');
      const hasValue = c.value !== undefined && c.value !== null;
      if (VALUE_OPS.has(c.op) && !hasValue) add(`${base}/conditions/${j}/value`, 'invalid_condition', `${c.op} needs a value`);
      if (!VALUE_OPS.has(c.op) && hasValue) add(`${base}/conditions/${j}/value`, 'invalid_condition', `${c.op} takes no value`);
      if ((c.op === 'gt' || c.op === 'lt') && typeof c.value !== 'number') add(`${base}/conditions/${j}/value`, 'invalid_condition', `${c.op} needs a number`);
    });
    w.actions.forEach((a, j) => {
      const ab = `${base}/actions/${j}`;
      if (a.type === 'record.setField') {
        const field = fieldOf(model.id, a.fieldId);
        if (!field) add(`${ab}/fieldId`, 'missing_field', 'action refers to a field outside the trigger model');
        else {
          const err = validateFieldValue({ ...field, required: false }, a.value);
          if (err) add(`${ab}/value`, 'invalid_value', `value ${err}`);
        }
      } else {
        if (DATA_PATTERNS.emailTarget.test(a.to)) {
          const field = fieldOf(model.id, a.to.slice(6));
          if (!field || field.type !== 'email') add(`${ab}/to`, 'invalid_recipient', 'recipient field must be an e-mail field of the trigger model');
        } else if (!DATA_PATTERNS.email.test(a.to)) {
          add(`${ab}/to`, 'invalid_recipient', 'recipient must be an e-mail address or field:<emailFieldId>');
        }
        if (PATTERNS.newline.test(a.subject) || PATTERNS.forbiddenControl.test(a.subject)) add(`${ab}/subject`, 'invalid_text', 'subject must be a single line');
        if (PATTERNS.forbiddenControl.test(a.body)) add(`${ab}/body`, 'invalid_text', 'body contains control characters');
        const names = new Set(model.fields.map((f) => f.name));
        for (const text of [a.subject, a.body]) {
          for (const match of text.matchAll(DATA_PATTERNS.placeholder)) {
            if (!names.has(match[1])) add(`${ab}`, 'unknown_placeholder', `{{${match[1]}}} is not a field of ${model.name}`);
          }
        }
      }
    });
  });

  // Data-bound components
  const nearestForm = (id: string): ComponentNode | null => {
    let cur = parentOf.get(id);
    while (cur) {
      const node = doc.components[cur];
      if (node?.type === 'Form') return node;
      cur = parentOf.get(cur);
    }
    return null;
  };
  for (const node of Object.values(doc.components)) {
    const base = `/components/${node.id}`;
    if (node.type === 'Form') {
      const modelId = node.props.modelId;
      if (typeof modelId === 'string' && modelId !== '' && !models.has(modelId)) add(`${base}/props/modelId`, 'missing_model', 'form refers to an unknown model');
      if (nearestForm(node.id)) add(base, 'nested_form', 'forms cannot be nested');
    } else if (node.type === 'FormField') {
      const form = nearestForm(node.id);
      const fieldId = node.props.fieldId;
      if (!form) add(base, 'field_outside_form', 'form fields must be placed inside a form');
      else if (typeof fieldId === 'string' && fieldId !== '') {
        const modelId = typeof form.props.modelId === 'string' ? form.props.modelId : '';
        if (fieldOwner.get(fieldId) !== modelId || modelId === '') add(`${base}/props/fieldId`, 'missing_field', 'field does not belong to the form model');
      }
    } else if (node.type === 'DataTable') {
      const modelId = typeof node.props.modelId === 'string' ? node.props.modelId : '';
      if (modelId !== '' && !models.has(modelId)) add(`${base}/props/modelId`, 'missing_model', 'table refers to an unknown model');
      const ids = Array.isArray(node.props.fieldIds) ? node.props.fieldIds : [];
      if (new Set(ids).size !== ids.length) add(`${base}/props/fieldIds`, 'duplicate_field', 'columns must be unique');
      for (const fid of ids) {
        if (typeof fid === 'string' && fieldOwner.get(fid) !== modelId) add(`${base}/props/fieldIds`, 'missing_field', `column "${fid}" does not belong to the table model`);
      }
    }
  }
  return issues;
}

/** Ids of components/workflows/permissions that reference a model (blocks model.remove). */
export function modelReferences(doc: AppDefinition, modelId: string): string[] {
  const refs: string[] = [];
  for (const n of Object.values(doc.components)) {
    if ((n.type === 'Form' || n.type === 'DataTable') && n.props.modelId === modelId) refs.push(n.id);
  }
  for (const w of doc.workflows) if (w.trigger.modelId === modelId) refs.push(w.id);
  return refs;
}
