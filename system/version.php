<?php
declare(strict_types=1);

/**
 * Single source of truth for the running app version. Bumped as part of
 * every release commit (the same commit that gets a matching git tag
 * pushed to origin). See docs/UPDATES.md for the versioning scheme.
 *
 * Format: strict semver `MAJOR.MINOR.PATCH`, no pre-release suffixes.
 */
const APP_VERSION = '1.0.0';
