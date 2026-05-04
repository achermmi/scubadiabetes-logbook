<div class="sd-membership-wrap" style="max-width:640px;margin:0 auto;">
	<h2><?php esc_html_e( 'Pagamento Tassa Sociale', 'sd-logbook' ); ?></h2>
	<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: 1: member name, 2: year, 3: amount */
				__( 'Ciao %1$s,<br>completa ora il pagamento della tassa sociale %2$s di CHF %3$s.', 'sd-logbook' ),
				trim( $ctx->first_name . ' ' . $ctx->last_name ),
				(int) $ctx->payment_year,
				number_format( (float) $ctx->amount, 2, '.', '' )
			)
		);
		?>
	</p>

	<?php if ( 'paypal_cancelled' === $notice ) : ?>
		<div class="sd-notice sd-notice-info"><?php esc_html_e( 'Pagamento PayPal annullato. Puoi riprovare o usare Fattura.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'paypal_disabled' === $notice ) : ?>
		<div class="sd-notice sd-notice-error"><?php esc_html_e( 'Metodo PayPal non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'invoice_disabled' === $notice ) : ?>
		<div class="sd-notice sd-notice-error"><?php esc_html_e( 'Metodo Fattura non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( in_array( $notice, array( 'paypal_error', 'paypal_missing_order', 'paypal_capture_failed' ), true ) ) : ?>
		<div class="sd-notice sd-notice-error"><?php esc_html_e( 'PayPal non disponibile al momento. Usa Fattura o riprova.', 'sd-logbook' ); ?></div>
	<?php endif; ?>

	<div class="sd-payment-methods" style="display:grid;gap:12px;max-width:520px;">
		<?php if ( $paypal_enabled ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'start_paypal', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Paga con PayPal', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $invoice_enabled ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'invoice_confirm', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Genera Fattura', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $twint_enabled ) : ?>
			<div class="sd-notice sd-notice-info">
				<strong><?php esc_html_e( 'TWINT diretto', 'sd-logbook' ); ?>:</strong>
				<?php esc_html_e( 'adapter predisposto ma non attivo in questa release MVP.', 'sd-logbook' ); ?>
			</div>
		<?php endif; ?>
	</div>
</div>
