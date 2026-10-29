/**
 * An API operation's identifier, "namespace:key": "coconut-cms:entities.get".
 * The template type refuses a name without its namespace at compile time.
 */
export type OperationName = `${string}:${string}`;
