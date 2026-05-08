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
		.sd-checkout-notice.sd-notice-error  { background: #fde8e8; border: 1px solid #f5c2c2; color: #8b1a1a; }
		.sd-checkout-notice.sd-notice-info   { background: #e8f0fd; border: 1px solid #c2d4f5; color: #1a3a8b; }
	</style>

	<?php if ( 'paypal_cancelled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-info"><?php esc_html_e( 'Pagamento PayPal annullato. Puoi riprovare o usare Fattura.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'paypal_disabled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'Metodo PayPal non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'invoice_disabled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'Metodo Fattura non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( in_array( $notice, array( 'paypal_error', 'paypal_missing_order', 'paypal_capture_failed' ), true ) ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'PayPal non disponibile al momento. Usa Fattura o riprova.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'twint_disabled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'TWINT non attivo nelle impostazioni.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'twint_error' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-error"><?php esc_html_e( 'TWINT non disponibile al momento. Usa Fattura o riprova.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'twint_cancelled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-info"><?php esc_html_e( 'Pagamento TWINT annullato. Puoi riprovare o usare un altro metodo.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'twint_ik_cancelled' === $notice ) : ?>
		<div class="sd-checkout-notice sd-notice-info"><?php esc_html_e( 'Pagamento TWINT annullato dal checkout Infomaniak. Puoi riprovare o usare un altro metodo.', 'sd-logbook' ); ?></div>
	<?php endif; ?>

	<?php
	// Controlla se c'è un ordine TWINT in corso (QR mostrato).
	$twint_order_uuid = isset( $_GET['twint_order'] ) ? sanitize_text_field( wp_unslash( $_GET['twint_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$twint_session    = $twint_enabled && '' !== $twint_order_uuid ? get_transient( 'sd_twint_order_' . $token ) : array();
	$show_twint_qr    = $twint_enabled && ! empty( $twint_session['order_uuid'] ) && $twint_session['order_uuid'] === $twint_order_uuid;
	?>

	<?php if ( $show_twint_qr ) : ?>
		<!-- ====== TWINT QR / deep-link ====== -->
		<div class="sd-twint-qr-wrap" style="text-align:center;padding:20px 0;">
			<h3><?php esc_html_e( 'Scansiona con l\'app TWINT', 'sd-logbook' ); ?></h3>
			<?php if ( ! empty( $twint_session['qr_code_svg'] ) ) : ?>
				<div class="sd-twint-qr" style="display:inline-block;border:1px solid #ddd;padding:8px;border-radius:6px;">
					<?php echo wp_kses( $twint_session['qr_code_svg'], array( 'svg' => array( 'xmlns' => true, 'viewBox' => true, 'width' => true, 'height' => true ), 'path' => array( 'd' => true, 'fill' => true ), 'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true ) ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $twint_session['deep_link'] ) ) : ?>
				<p style="margin-top:12px;">
					<a class="button button-primary" href="<?php echo esc_url( $twint_session['deep_link'] ); ?>">
						<?php esc_html_e( 'Apri TWINT sul telefono', 'sd-logbook' ); ?>
					</a>
				</p>
			<?php endif; ?>
			<div id="sd-twint-polling-status" style="margin-top:12px;color:#555;">
				<span class="sd-twint-spinner" style="display:inline-block;width:14px;height:14px;border:2px solid #aaa;border-top-color:#0073aa;border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle;margin-right:6px;"></span>
				<?php esc_html_e( 'In attesa di conferma pagamento...', 'sd-logbook' ); ?>
			</div>
			<p style="margin-top:16px;">
				<a href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'twint_cancel', 'sdpt' => $token ), $checkout_action_base ) ); ?>" class="button" style="background:#c00;color:#fff;border-color:#a00;border-radius:6px;">
					<?php esc_html_e( 'Annulla pagamento TWINT', 'sd-logbook' ); ?>
				</a>
			</p>
		</div>
		<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
		<?php
		wp_nonce_field( 'sd_twint_poll', 'sd_twint_nonce' );
		wp_localize_script(
			'sd-twint-checkout',
			'sdTwintData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'sd_twint_poll' ),
				'token'     => $token,
				'orderUuid' => $twint_order_uuid,
			)
		);
		wp_enqueue_script( 'sd-twint-checkout' );
		?>
	<?php else : ?>

	<div class="sd-payment-methods">
		<?php if ( $paypal_enabled ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'start_paypal', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Paga con PayPal', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $invoice_enabled ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'invoice_confirm', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Invia Fattura', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $twint_enabled ) : ?>
			<a class="button" style="background:#000;color:#fff;border-color:#000;" href="<?php echo esc_url( add_query_arg( array( 'sd_payment_action' => 'start_twint', 'sdpt' => $token ), $checkout_action_base ) ); ?>">
				<?php esc_html_e( 'Paga con TWINT', 'sd-logbook' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<?php endif; ?>
</div>