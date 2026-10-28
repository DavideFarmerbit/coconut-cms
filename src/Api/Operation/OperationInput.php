<?php

namespace XyloIsCoding\CoconutCms\Api\Operation;

/**
 * Marks a class as an operation's input, or a part of one: a readonly class
 * whose constructor parameters are typed. InputReader builds it from JSON or
 * command-line arguments. A parameter is a string, int, float, bool, a
 * backed enum, another OperationInput, or an array whose @param names its
 * items: `list<ConditionInput>`, `list<mixed>` for any JSON value.
 */
interface OperationInput
{
}
