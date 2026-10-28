<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Routing\Request;

/**
 * Turns an HTTP request into whoever makes it. The project implements it
 * over its own users, and gives it to Api::boot(): the package depends on no
 * user library.
 */
interface Authenticator
{
    /** The caller of $request: an AnonymousActor when nobody signed in. */
    public function actorFor(Request $request): Actor;
}
