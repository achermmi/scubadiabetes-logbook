<div class="sd-membership-wrap">
	<h2><?php esc_html_e( 'Conferma Pagamento', 'sd-logbook' ); ?></h2>

	<?php if ( (int) $ctx->has_paid_fee === 1 ) : ?>
		<div class="sd-notice sd-notice-success"><?php esc_html_e( 'Pagamento approvato e iscrizione attivata.', 'sd-logbook' ); ?></div>
	<?php elseif ( 'fattura' === (string) $ctx->provider ) : ?>
		<div class="sd-notice sd-notice-info"><?php esc_html_e( 'Fattura generata. Stato in attesa di accredito; il socio e il rispettivo account verrà attivato dopo verifica pagamento.', 'sd-logbook' ); ?></div>
	<?php else : ?>
		<div class="sd-notice sd-notice-info"><?php esc_html_e( 'Pagamento in elaborazione.', 'sd-logbook' ); ?></div>
	<?php endif; ?>

	<ul>
		<li><strong><?php esc_html_e( 'Socio', 'sd-logbook' ); ?>:</strong> <?php echo esc_html( trim( $ctx->first_name . ' ' . $ctx->last_name ) ); ?></li>
		<li><strong><?php esc_html_e( 'Numero socio', 'sd-logbook' ); ?>:</strong> <?php echo esc_html( (string) $ctx->member_number ); ?></li>
		<li><strong><?php esc_html_e( 'Importo', 'sd-logbook' ); ?>:</strong> CHF <?php echo esc_html( number_format( (float) $ctx->amount, 2, '.', '' ) ); ?></li>
		<li><strong><?php esc_html_e( 'Metodo', 'sd-logbook' ); ?>:</strong> <?php
			$method_labels = array(
				'bonifico_iban' => __( 'Bonifico IBAN', 'sd-logbook' ),
				'paypal'        => __( 'PayPal', 'sd-logbook' ),
				'twint'         => __( 'TWINT', 'sd-logbook' ),
				'fattura'       => __( 'Fattura', 'sd-logbook' ),
			);
			$method_key = (string) $ctx->payment_method;
			echo esc_html( isset( $method_labels[ $method_key ] ) ? $method_labels[ $method_key ] : $method_key );
		?></li>
		<li><strong><?php esc_html_e( 'Scadenza tessera', 'sd-logbook' ); ?>:</strong> <?php
			$expiry_raw = (string) $ctx->membership_expiry;
			if ( '' !== $expiry_raw ) {
				$dt = DateTime::createFromFormat( 'Y-m-d', $expiry_raw );
				echo esc_html( $dt ? $dt->format( 'd.m.Y' ) : $expiry_raw );
			}
		?></li>
	</ul>

	<div style="display:flex;flex-wrap:wrap;gap:10px;">
		<?php if ( ! empty( $receipt_url ) ) : ?>
			<a class="button" href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( 'fattura' === (string) $ctx->provider ? __( 'Scarica Fattura PDF', 'sd-logbook' ) : __( 'Scarica Ricevuta PDF', 'sd-logbook' ) ); ?></a>
		<?php endif; ?>
		<?php if ( ! empty( $card_url ) ) : ?>
			<a class="button" href="<?php echo esc_url( $card_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Scarica Tessera PDF', 'sd-logbook' ); ?></a>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Torna alla Homepage', 'sd-logbook' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Vai al Login', 'sd-logbook' ); ?></a>
	</div>
</div>
