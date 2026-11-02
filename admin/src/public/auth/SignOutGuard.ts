/**
 * Asked before signing out: whether it may go ahead. Whatever holds work
 * that signing out would lose registers one with `admin.auth.guard()`, at
 * its place among the others: the entity store does, asking about unsaved
 * changes. Guards are asked one at a time, in order.
 */
export interface SignOutGuard {
    /** Settles the work held now, asking the user when needed; false keeps them signed in. Nothing left to settle asks nothing. */
    allowsSignOut(): Promise<boolean>;
}
