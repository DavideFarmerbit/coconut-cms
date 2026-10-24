<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use RuntimeException;

/**
 * Thrown by any read or write while entities can't be trusted: a migration
 * is pending, or one is unfinished. Catch it to show a "back soon" page, or
 * to hide what needs entities, without caring which of the two it is.
 */
abstract class PersistenceUnavailable extends RuntimeException
{
}
