/**
 * Asked before signing out: whether it may go ahead. Whatever holds work
 * that signing out would lose registers one with `admin.auth.guard()`: the
 * entity store does, asking about unsaved changes.
 */
export interface SignOutGuard {
    /** Settles the work held, asking the user when needed; false keeps them signed in. */
    allowsSignOut(): Promise<boolean>;
}
