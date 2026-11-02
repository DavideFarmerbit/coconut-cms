/**
 * Where a sign-out guard runs among the others: its name, and the guards it
 * runs before or after. A guard runs before the ones whose work its save
 * may settle: the schema's, before the entities'. A name no guard has is
 * left out: its plugin may not be there.
 *
 *     admin.auth.guard(guard, {name: 'shop:orders', after: ['coconut-cms:schema']});
 */
export interface GuardPlace {
    /** Its identifier, "namespace:key" as an operation's: what other guards name it by. */
    readonly name: string;
    /** The guards it runs before. */
    readonly before?: readonly string[];
    /** The guards it runs after. */
    readonly after?: readonly string[];
}
