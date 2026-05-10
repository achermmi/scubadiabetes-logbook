<?php
/**
 * Base adapter per provider di pagamento.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SD_Payment_Adapter {

	/**
	 * Nome provider (PayPal, Fattura, Twint).
	 *
	 * @return string
	 */
	abstract public function get_provider_name();
}
