<?php
/**
 * PHP 8's Stringable, for the bundled SQL parser on PHP 7.4 (loaded only
 * when no other code declared it).
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

interface Stringable {
	public function __toString();
}
