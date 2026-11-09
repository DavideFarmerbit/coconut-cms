<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php;

use RuntimeException;

/**
 * A step failing on one aggregate: `fail(…)`, or a built-in given a value it
 * can't take, like toInt("call us"). The aggregate fails whole; `try`
 * catches it.
 */
final class StepFailed extends RuntimeException
{
}
