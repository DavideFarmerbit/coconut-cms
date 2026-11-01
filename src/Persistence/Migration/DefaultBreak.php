<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

/**
 * A type's default breaking one of its rules: a warning, never a refusal. A
 * default may be a placeholder a rule refuses on purpose, `""` for a
 * required name, so an entity saved holding it is refused until it's filled.
 */
final readonly class DefaultBreak
{
    /**
     * @param string $type The type whose default instance breaks it.
     * @param string $path Where, as ValidationFailed names it; "" for the entity itself.
     * @param string $error What the rule says.
     */
    public function __construct(
        public string $type,
        public string $path,
        public string $error,
    ) {
    }

    /** As a warning reads it. */
    public function describe(): string {
        return $this->path === ''
            ? sprintf('The default of "%s" breaks a rule: %s.', $this->type, $this->error)
            : sprintf('The default of "%s" at "%s" breaks a rule: %s.', $this->type, $this->path, $this->error);
    }
}
