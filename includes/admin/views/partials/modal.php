<?php
/**
 * Dialog that shows one AI answer.
 *
 * @package AIVT
 */

defined( 'ABSPATH' ) || exit;
?>
<dialog class="aivt-modal" data-aivt-modal aria-labelledby="aivt-modal-title">
	<div class="aivt-modal__head">
		<div>
			<h2 id="aivt-modal-title" data-aivt-modal-title></h2>
			<p class="aivt-muted" data-aivt-modal-prompt></p>
		</div>
		<button type="button" class="button-link aivt-modal__close" data-aivt-modal-close aria-label="<?php esc_attr_e( 'Close', 'ai-visibility-tracker' ); ?>">
			<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
		</button>
	</div>
	<div class="aivt-modal__body" data-aivt-modal-body></div>
</dialog>
