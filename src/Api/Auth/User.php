<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;

/**
 * A signed-in caller, as the project's Authenticator describes it: who it
 * is, and the roles it holds, by the names permissions use.
 */
final readonly class User implements Actor
{
    /**
     * @param string $id Its id in the project's user library.
     * @param string $name What to show for it: a username, or an email.
     * @param list<string> $roles Every role it holds: "admin", "editor".
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $roles,
    ) {
    }

    public function hasRole(string $role): bool {
        return in_array($role, $this->roles, true);
    }
}
