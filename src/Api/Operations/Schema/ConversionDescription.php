<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

/**
 * One registered converter or initializer, as the editor offers it: the
 * identifier to send as "kind", what it fits, and its parameters' form.
 */
final readonly class ConversionDescription
{
    /**
     * @param array<string, mixed>|null $from The fields a converter takes, as SignatureForm writes them; null for an initializer.
     * @param array<string, mixed>|null $to The fields it gives, or fills; null for a level initializer.
     * @param list<ParameterDescription> $parameters
     */
    public function __construct(
        public string $kind,
        public ?array $from,
        public ?array $to,
        public array $parameters,
    ) {
    }
}
