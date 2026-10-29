<?php
// php-scoper config: phpMyAdmin's SQL parser under Kico\Vendor, so it never
// clashes with another plugin's copy (phpMyAdmin-based plugins, WP-CLI's
// own). Only the parser's src/ and its license are kept: no tests, locales
// or binaries. The polyfills it requires are not bundled: WordPress has
// str_contains and friends, and class-kico-sql.php declares Stringable on
// PHP 7.4 and checks for mbstring.
use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'             => 'Kico\\Vendor',
	'finders'            => array(
		Finder::create()->files()->in( 'vendor/phpmyadmin/sql-parser' )->path( '#^src/#' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/phpmyadmin/sql-parser' )->depth( 0 )->name( 'LICENSE.txt' ),
	),
	'exclude-namespaces' => array(),
	'exclude-classes'    => array( 'Stringable' ),
	'exclude-functions'  => array( 'str_contains', 'str_starts_with', 'str_ends_with' ),
	'expose-global-constants' => true,
	'expose-global-classes'   => true,
	'expose-global-functions' => true,
);
