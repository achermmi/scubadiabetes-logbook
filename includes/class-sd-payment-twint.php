<?php
/**
 * Adapter TWINT (stub non-live per architettura futura).
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Twint extends SD_Payment_Adapter {

	/**
	 * @return string
	 */
	public function get_provider_name() {
		return 'twint';
	}

	/**
	 * Adapter non ancora attivo in MVP.
	 *
	 * @return WP_Error
	 */
	public function start_checkout() {
		return new WP_Error(
			'sd_twint_not_available',
			__( 'TWINT diretto non e ancora disponibile in questa release.', 'sd-logbook' )
		);
	}
}
