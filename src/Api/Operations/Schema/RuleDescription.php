<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

/**
 * One registered validator rule, as the editor offers it: the identifier to
 * send as "rule", the fields it fits, and its parameters' form.
 */
final readonly class RuleDescription
{
    /**
     * @param array<string, mixed>|null $fits The fields a field rule checks, as SignatureCodec writes them; null for a prototype rule.
     * @param list<ParameterDescription> $parameters
     */
    public function __construct(
        public string $rule,
        public ?array $fits,
        public array $parameters,
    ) {
    }
}
