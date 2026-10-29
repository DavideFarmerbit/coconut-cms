<?php

namespace XyloIsCoding\CoconutCms\Admin;

/** A theme the admin's build ships, chosen by name instead of by URL: Admin::boot(theme: BundledTheme::Light). */
enum BundledTheme
{
    case Light;

    /** Its stylesheet's entry in the admin's Vite manifest. */
    public function entry(): string {
        return match ($this) {
            self::Light => Admin::ENTRY_THEMES . 'light.scss',
        };
    }
}
