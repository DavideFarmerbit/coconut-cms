<?php

namespace XyloIsCoding\CoconutCms\Api\Operation;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;

/**
 * What an operation runs with: the caller, and persistence as the caller
 * sees it. Built by the transport for each call.
 */
final readonly class OperationContext
{
    /**
     * @param PersistenceSession $session Entities as $actor sees them.
     * @param Persistence $persistence For what isn't about entities, such as
     *        checking a migration: never to read or write entities past the
     *        caller's rights.
     */
    public function __construct(
        public Actor $actor,
        public PersistenceSession $session,
        public Persistence $persistence,
    ) {
    }
}
