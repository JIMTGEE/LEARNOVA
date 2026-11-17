<?php
/**
 * Shared Learnova application identity and deployment defaults.
 * Override LEARNOVA_DB_NAME in the environment during/after database migration.
 */
define('APP_NAME', 'Learnova');
define('APP_SHORT_NAME', 'Learnova');
define('APP_TAGLINE', 'A new beginning for learning.');
define('APP_DATABASE_NAME', getenv('LEARNOVA_DB_NAME') ?: 'learnova');
