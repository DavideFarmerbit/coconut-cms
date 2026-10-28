<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Routing\Request;

/**
 * Every HTTP caller is anonymous: for a project with no sign-in yet, or one
 * booting the API for the command line only, which runs as SystemActor
 * whatever this says.
 */
final readonly class AnonymousAuthenticator implements Authenticator
{
    public function actorFor(Request $request): Actor {
        return new AnonymousActor();
    }
}
