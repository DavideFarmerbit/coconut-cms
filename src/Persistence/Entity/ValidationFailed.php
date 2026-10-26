<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use RuntimeException;

/**
 * Thrown by a save whose entity breaks a rule its type declares, or a unique
 * group: nothing was written. Holds every error, by the path it's about, so a
 * form can show them all at once.
 */
final class ValidationFailed extends RuntimeException
{
    /**
     * @param string $identifier The saved entity's type.
     * @param array<string, list<string>> $errors Each path's errors: "name",
     *        "chassis:weight", "spares.1.horsepower"; "" for the entity itself.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly array $errors,
    ) {
        $lines = [];
        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = $path === '' ? $message : sprintf('"%s": %s', $path, $message);
            }
        }

        parent::__construct(sprintf('This %s can\'t be saved: %s.', $identifier, implode('; ', $lines)));
    }
}
