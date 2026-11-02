/** One operation of an editor save, as schema.preview and schema.save take it: `op` names it, the rest are its parameters. */
export interface SchemaOperationData {
    readonly op: string;
    readonly [parameter: string]: unknown;
}
