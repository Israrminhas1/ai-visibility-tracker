<?php
/**
 * "Run now" bar with live progress.
 *
 * @package AIVT
 *
 * @var \AIVT\Repository $repo
 */

use AIVT\Admin\Admin;
use AIVT\Engines;

defined( 'ABSPATH' ) || exit;

$aivt_bar_active  = $repo->active_run();
$aivt_bar_last    = $repo->runs( 1 );
$aivt_bar_last    = $aivt_bar_last ? $aivt_bar_last[0] : null;
$aivt_bar_engines = Engines::active();
$aivt_bar_prompts = count( $repo->prompts( true ) );
$aivt_bar_calls   = $aivt_bar_prompts * count( $aivt_bar_engines );
?>
<section class="aivt-card aivt-runbar" data-aivt-runbar>
	<div class="aivt-runbar__info">
		<strong><?php esc_html_e( 'Visibility check', 'ai-visibility-tracker' ); ?></strong>
		<span class="aivt-muted" data-aivt-run-status role="status" aria-live="polite">
			<?php
			if ( $aivt_bar_active ) {
				esc_html_e( 'A check is running…', 'ai-visibility-tracker' );
			} elseif ( $aivt_bar_calls ) {
				printf(
					/* translators: 1: prompts, 2: engines, 3: total answers, 4: estimated cost */
					esc_html__( '%1$d prompts × %2$d engines = %3$d answers per check, about %4$s.', 'ai-visibility-tracker' ),
					(int) $aivt_bar_prompts,
					(int) count( $aivt_bar_engines ),
					(int) $aivt_bar_calls,
					esc_html( \AIVT\Pricing::format( \AIVT\Runner::estimate( $aivt_bar_engines, $aivt_bar_prompts ) ) )
				);
			} elseif ( ! $aivt_bar_engines ) {
				esc_html_e( 'Connect an AI engine in Settings to start.', 'ai-visibility-tracker' );
			} else {
				esc_html_e( 'Add some prompts to start.', 'ai-visibility-tracker' );
			}
			?>
		</span>
	</div>
	<div class="aivt-progress" data-aivt-progress <?php echo $aivt_bar_active ? '' : 'hidden'; ?>>
		<div class="aivt-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Check progress', 'ai-visibility-tracker' ); ?>" data-aivt-progress-bar><div class="aivt-progress__fill" data-aivt-progress-fill style="width:0"></div></div>
	</div>
	<div class="aivt-runbar__actions">
		<button type="button" class="button" data-aivt-cancel <?php echo $aivt_bar_active ? '' : 'hidden'; ?>><?php esc_html_e( 'Stop', 'ai-visibility-tracker' ); ?></button>
		<button type="button" class="button button-primary" data-aivt-run <?php echo $aivt_bar_active ? 'hidden' : ''; ?> <?php disabled( 0 === $aivt_bar_calls ); ?>><?php esc_html_e( 'Run now', 'ai-visibility-tracker' ); ?></button>
	</div>
	<p class="aivt-runbar__hint aivt-muted aivt-small">
		<?php esc_html_e( 'Keep this page open for the fastest results. If you leave, the check continues in the background.', 'ai-visibility-tracker' ); ?>
		<?php if ( $aivt_bar_last && 'completed' === $aivt_bar_last['status'] ) : ?>
			<br>
			<?php
			printf(
				/* translators: 1: cost of the last check, 2: amount spent this month */
				esc_html__( 'Last check cost %1$s. This month: %2$s.', 'ai-visibility-tracker' ),
				esc_html( \AIVT\Pricing::format( (float) $aivt_bar_last['cost'] ) ),
				esc_html( \AIVT\Pricing::format( $repo->spent_since( gmdate( 'Y-m-01 00:00:00' ) ) ) )
			);
			?>
		<?php endif; ?>
		<?php if ( $aivt_bar_last && 'completed' === $aivt_bar_last['status'] && (int) $aivt_bar_last['failed'] > 0 ) : ?>
			<br>
			<?php
			/* translators: %d: failed answers */
			printf( esc_html__( 'The last check had %d failed answers.', 'ai-visibility-tracker' ), (int) $aivt_bar_last['failed'] );
			?>
			<a href="<?php echo esc_url( Admin::url( 'aivt-results', array( 'run' => (int) $aivt_bar_last['id'] ) ) ); ?>"><?php esc_html_e( 'See why', 'ai-visibility-tracker' ); ?></a>
		<?php endif; ?>
	</p>
</section>
