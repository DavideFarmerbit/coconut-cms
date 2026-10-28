<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Routing\Request;

/**
 * Nobody ever signs in: for a project with no users yet, or one booting the
 * API for the command line only, which runs as SystemActor.
 */
final readonly class AnonymousAuthenticator implements Authenticator
{
    public function actorFor(Request $request): Actor {
        return new AnonymousActor();
    }

    public function signIn(string $login, string $password, bool $remember): User {
        throw new SignInFailed();
    }

    public function signOut(): void {
    }

    public function userNamed(string $login): ?User {
        return null;
    }
}
