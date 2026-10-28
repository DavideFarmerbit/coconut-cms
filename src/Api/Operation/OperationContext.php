<?php

namespace XyloIsCoding\CoconutCms\Api\Operation;

use XyloIsCoding\CoconutCms\Api\Auth\Authenticator;
use XyloIsCoding\CoconutCms\Core\Kernel;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;

/**
 * What an operation runs with: the caller, persistence as the caller sees
 * it, and the project's services. Built by the transport for each call.
 */
final readonly class OperationContext
{
    /**
     * @param PersistenceSession $session Entities as $actor sees them.
     * @param Persistence $persistence For what isn't about entities, such as
     *        checking a migration: never to read or write entities past the
     *        caller's rights.
     * @param Authenticator $authenticator The project's users: signing in and out.
     * @param Kernel $kernel The project's services, by Identifier: what an
     *        operation needs beyond persistence, such as a mailer.
     */
    public function __construct(
        public Actor $actor,
        public PersistenceSession $session,
        public Persistence $persistence,
        public Authenticator $authenticator,
        public Kernel $kernel,
    ) {
    }
}
