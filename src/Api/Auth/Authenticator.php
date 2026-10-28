<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Routing\Request;

/**
 * Who calls the API, over the project's own users. The project implements
 * it over the user library it picks, and gives it to Api::boot(): the
 * package depends on none. The auth.signIn, auth.signOut and auth.me
 * operations call through it.
 */
interface Authenticator
{
    /** The caller of $request: the User signed in, or an AnonymousActor. */
    public function actorFor(Request $request): Actor;

    /**
     * Signs the current caller in as the user $login names: an email or a
     * username, as the project allows.
     * @param bool $remember Whether the sign-in outlives the browser session.
     * @throws SignInFailed For credentials that don't sign anyone in.
     * @throws TooManyAttempts When signing in is throttled.
     */
    public function signIn(string $login, string $password, bool $remember): User;

    /** Signs the current caller out; nothing when nobody is signed in. */
    public function signOut(): void;

    /** The user $login names, without signing in: what `--as` acts as. Null when there's none. */
    public function userNamed(string $login): ?User;
}
