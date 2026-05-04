<?php
/**
 * Adapter pagamento Fattura.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Fattura extends SD_Payment_Adapter {

	/**
	 * @return string
	 */
	public function get_provider_name() {
		return 'fattura';
	}
}
