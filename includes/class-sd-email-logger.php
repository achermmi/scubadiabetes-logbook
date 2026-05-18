<?php
/**
 * Helper centralizzato per registrare nel log di audit ogni e-mail inviata
 * dal plugin (data/ora, destinatario e oggetto).
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Email_Logger {

	/**
	 * Registra l'invio di un'e-mail nella tabella sd_audit_log.
	 *
	 * @param string $table_name Riferimento logico (es. 'sd_activity_registrations', 'sd_members', 'sd_payments').
	 * @param int    $record_id  ID del record correlato (registrazione, socio, pagamento). 0 se non applicabile.
	 * @param string $to         Destinatario principale.
	 * @param string $subject    Oggetto dell'e-mail effettivamente inviata.
	 * @param string $type       Categoria (es. 'payment_confirmation', 'refund_confirmation', 'registration_broadcast', ...).
	 * @param array  $extra      Eventuali metadati aggiuntivi (saranno serializzati in new_data).
	 * @return int|false ID inserito o false in caso di errore.
	 */
	public static function log( $table_name, $record_id, $to, $subject, $type = 'generic', array $extra = array() ) {
		global $wpdb;

		$payload = array_merge(
			array(
				'to'      => sanitize_email( (string) $to ),
				'subject' => sanitize_text_field( (string) $subject ),
				'type'    => sanitize_key( (string) $type ),
			),
			$extra
		);

		$ok = $wpdb->insert(
			$wpdb->prefix . 'sd_audit_log',
			array(
				'member_id'  => get_current_user_id() ?: null,
				'action'     => 'email_sent',
				'table_name' => sanitize_key( (string) $table_name ),
				'record_id'  => (int) $record_id ?: null,
				'new_data'   => wp_json_encode( $payload ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}
}
