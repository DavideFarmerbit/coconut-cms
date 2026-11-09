<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

use RuntimeException;

/** One thing wrong with a step's types, thrown where it's found; the checker collects them. */
final class TypeProblem extends RuntimeException
{
}
