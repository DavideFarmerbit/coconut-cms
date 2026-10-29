import type {User} from './User.ts';

/** What coconut-cms:auth.me, auth.signIn and auth.signOut give: the user, or null for nobody. */
export interface Me {
    readonly user: User | null;
}
