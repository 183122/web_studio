import type { ErrorObject } from './ajv-types';
interface Fn { (data: unknown): boolean; errors?: ErrorObject[] | null }
export declare const validateAppDefinition: Fn;
export declare const validateAppCommand: Fn;
