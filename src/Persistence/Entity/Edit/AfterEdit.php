<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Edit;

/**
 * Implemented by a native entity class reacting to an edit by path once
 * every change is applied: for a rule spanning fields, such as a new country
 * resetting the region. Unreal's PostEditChangeProperty() plays the same
 * part. One method per class chain: a subclass overrides it, and decides
 * whether to call parent::afterEdit().
 */
interface AfterEdit
{
    /**
     * Called once per edit, after every setter. May change fields, or throw
     * EditRefused to refuse the whole edit.
     * @param list<string> $paths Every path changed, relative to this object:
     *        an owned object gets its own, its owner every path.
     */
    public function afterEdit(array $paths): void;
}
