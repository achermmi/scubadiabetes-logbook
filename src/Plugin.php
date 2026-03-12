<?php
/**
 * Main Plugin bootstrap class.
 *
 * @package ScubaDiabetes\Logbook
 */

namespace ScubaDiabetes\Logbook;

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( self::class, 'boot' ) );
	}

	/**
	 * Boot the plugin logic.
	 *
	 * @return void
	 */
	public static function boot() {
		// Plugin logic.
	}
}
