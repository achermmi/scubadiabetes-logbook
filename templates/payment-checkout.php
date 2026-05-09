<?php
/**
 * Template pagina checkout pubblica.
 *
 * Variabili disponibili (iniettate da SD_Payment_Flow::render_checkout):
 *
 * @var object $ctx                  Contesto pagamento (SD_Database row).
 * @var string $token                Token pagamento (sdpt).
 * @var string $notice               Eventuale notice da query string.
 * @var string $checkout_action_base URL base della pagina di checkout.
 * @var bool   $paypal_enabled       PayPal attivo.
 * @var bool   $invoice_enabled      Fattura attiva.
 * @var bool   $stripe_enabled       Stripe attivo.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sd-membership-wrap" style="max-width:640px;margin:0 auto;">
	<h2><?php esc_html_e( 'Pagamento Tassa Sociale', 'sd-logbook' ); ?></h2>
	<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: 1: Nome membro, 2: anno, 3: importo */
				__( 'Ciao %1$s,<br>completa ora il pagamento della tassa sociale %2$s di CHF %3$s.', 'sd-logbook' ),
				trim( $ctx->first_name . ' ' . $ctx->last_name ),
				(int) $ctx->payment_year,
				number_format( (float) $ctx->amount, 2, '.', '' )
			)
		);
		?>
	</p>

	<style>
		.sd-payment-methods { display: grid; gap: 12px; max-width: 520px; }
		.sd-payment-methods .button {
			border-radius: 6px !important;
			text-align: center;
			display: block;
			box-sizing: border-box;
			width: 100%;
		}
		.sd-checkout-notice {
			display: block;
			width: 100%;
			max-width: 520px;
			box-sizing: border-box;
			padding: 8px 12px;
			border-radius: 6px;
			margin-bottom: 12px;
		}
		.sd-checkout-notice.sd-notice-error { background: #fde8e8; border: 1px solid #f5c2c2; color: #8b1a1a; }
		.sd-checkout-notice.sd-notice-info  { background: #e8f0fd; border: 1px solid #c2d4f5; color: #1a3a8b; }
	</style>

	<?php if ( 'paypal_cancelled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-info"><?php esc_html_e( 'Pagamento PayPal annullato. Puoi riprovare o scegliere un altro metodo.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'paypal_disabled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'Metodo PayPal non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'invoice_disabled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'Metodo Fattura non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( in_array( $notice, array( 'paypal_error', 'paypal_missing_order', 'paypal_capture_failed' ), true ) ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'PayPal non disponibile al momento. Usa Stripe o riprova.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'stripe_disabled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'Il metodo di pagamento online non è al momento attivo. Usa Fattura.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'stripe_error' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'Pagamento non disponibile al momento. Riprova o usa Fattura.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'stripe_cancelled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-info"><?php esc_html_e( 'Pagamento annullato. Puoi riprovare o scegliere un altro metodo.', 'sd-logbook' ); ?></div>
	<?php endif; ?>

	<div class="sd-payment-methods">

		<?php if ( $stripe_enabled ) : ?>
			<a class="button button-primary"
			   href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'start_stripe', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Paga con Carta / TWINT / Apple Pay / Google Pay', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $paypal_enabled ) : ?>
			<a class="button"
			   href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'start_paypal', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Paga con PayPal', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $invoice_enabled ) : ?>
			<a class="button"
			   href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'invoice_confirm', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Richiedi Fattura', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

	</div>
</div>
