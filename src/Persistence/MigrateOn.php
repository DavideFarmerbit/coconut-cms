<?php

namespace XyloIsCoding\CoconutCms\Persistence;

/** What runs a pending migration. */
enum MigrateOn
{
    /**
     * Only an explicit Persistence::migrate(), from a deploy command: a
     * request finding one pending refuses to read or write.
     */
    case Command;

    /** The first request finding one pending runs it. For development. */
    case Request;
}
