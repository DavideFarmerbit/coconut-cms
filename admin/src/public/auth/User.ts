/** A signed-in user, as coconut-cms:auth.me and auth.signIn give it. */
export interface User {
    /** Its id in the project's user library. */
    readonly id: string;
    /** What to show for it: a username, or an email. */
    readonly name: string;
    /** Every role it holds, by the names permissions use. */
    readonly roles: readonly string[];
}
