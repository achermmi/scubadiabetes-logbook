<?php
/**
 * Template pagina conferma iscrizione attività.
 *
 * Variabili iniettate da SD_Activity_Payment_Flow::render_confirmation():
 *
 * @var object $ctx   Contesto (row sd_activity_registrations + join).
 * @var string $token Token pagamento (sdapt).
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$payment_label = '';
switch ( $ctx->payment_status ) {
	case 'paid':
		$payment_label = __( 'Pagamento ricevuto', 'sd-logbook' );
		break;
	case 'invoice_sent':
		$payment_label = __( 'Fattura inviata via email', 'sd-logbook' );
		break;
	case 'invoice_requested':
		$payment_label = __( 'Fattura richiesta – pagamento in attesa', 'sd-logbook' );
		break;
	case 'invoice_error':
		$payment_label = __( 'Errore invio email fattura', 'sd-logbook' );
		break;
	case 'free':
		$payment_label = __( 'Nessun pagamento richiesto', 'sd-logbook' );
		break;
	default:
		$payment_label = __( 'In attesa di pagamento', 'sd-logbook' );
		break;
}
?>
<div class="sd-membership-wrap" style="max-width:640px;margin:0 auto;">

	<h2><?php esc_html_e( 'Iscrizione confermata!', 'sd-logbook' ); ?></h2>

	<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: 1: Nome partecipante, 2: titolo attività */
				__( 'Grazie %1$s! La tua iscrizione all\'attività <strong>%2$s</strong> è stata registrata.', 'sd-logbook' ),
				esc_html( trim( $ctx->first_name . ' ' . $ctx->last_name ) ),
				esc_html( $ctx->activity_title ?? '' )
			)
		);
		?>
	</p>

	<table style="width:100%;border-collapse:collapse;max-width:520px;" cellpadding="8" cellspacing="0">
		<tr>
			<th style="text-align:left;border-bottom:1px solid #eee;width:40%;"><?php esc_html_e( 'Attività', 'sd-logbook' ); ?></th>
			<td style="border-bottom:1px solid #eee;"><?php echo esc_html( $ctx->activity_title ?? '' ); ?></td>
		</tr>
		<?php if ( ! empty( $ctx->activity_start_date ) ) : ?>
		<tr>
			<th style="text-align:left;border-bottom:1px solid #eee;"><?php esc_html_e( 'Data', 'sd-logbook' ); ?></th>
			<td style="border-bottom:1px solid #eee;"><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $ctx->activity_start_date ) ) ); ?></td>
		</tr>
		<?php endif; ?>
		<?php if ( ! empty( $ctx->price_name ) ) : ?>
		<tr>
			<th style="text-align:left;border-bottom:1px solid #eee;"><?php esc_html_e( 'Tariffa', 'sd-logbook' ); ?></th>
			<td style="border-bottom:1px solid #eee;"><?php echo esc_html( $ctx->price_name ); ?></td>
		</tr>
		<?php endif; ?>
		<tr>
			<th style="text-align:left;border-bottom:1px solid #eee;"><?php esc_html_e( 'Importo', 'sd-logbook' ); ?></th>
			<td style="border-bottom:1px solid #eee;">
				CHF <?php echo esc_html( number_format( (float) $ctx->price_chf, 2, '.', '' ) ); ?>
			</td>
		</tr>
		<tr>
			<th style="text-align:left;"><?php esc_html_e( 'Stato pagamento', 'sd-logbook' ); ?></th>
			<td>
				<?php if ( 'paid' === $ctx->payment_status ) : ?>
					<span style="color:#2a7a2a;font-weight:600;">&#10003; <?php echo esc_html( $payment_label ); ?></span>
				<?php elseif ( 'invoice_sent' === $ctx->payment_status ) : ?>
					<span style="color:#2a7a2a;font-weight:600;">&#9993; <?php echo esc_html( $payment_label ); ?></span>
				<?php elseif ( 'invoice_requested' === $ctx->payment_status ) : ?>
					<span style="color:#b36a00;font-weight:600;">&#9993; <?php echo esc_html( $payment_label ); ?></span>
				<?php elseif ( 'invoice_error' === $ctx->payment_status ) : ?>
					<span style="color:#991b1b;font-weight:600;">&#9888; <?php echo esc_html( $payment_label ); ?></span>
				<?php else : ?>
					<span style="color:#666;"><?php echo esc_html( $payment_label ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php if ( in_array( $ctx->payment_status, array( 'invoice_requested', 'invoice_sent' ), true ) ) : ?>
		<p style="margin-top:16px;color:#666;font-size:0.9em;">
			<?php esc_html_e( 'Riceverai la fattura via email nei prossimi giorni lavorativi.', 'sd-logbook' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( 'invoice_error' === $ctx->payment_status ) : ?>
		<p style="margin-top:16px;color:#991b1b;font-size:0.9em;">
			<?php esc_html_e( 'Non è stato possibile inviare l\'email della fattura. Contatta l\'organizzazione per completare il pagamento.', 'sd-logbook' ); ?>
		</p>
	<?php endif; ?>

	<p style="margin-top:24px;">
		<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( '← Torna alla home', 'sd-logbook' ); ?>
		</a>
	</p>

</div>
