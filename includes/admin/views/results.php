<?php
/**
 * Results view: every answer of a run.
 *
 * @package AIVT
 *
 * @var \AIVT\Repository $repo
 * @var array            $settings
 */

use AIVT\Admin\Admin;
use AIVT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$aivt_runs      = $repo->runs( 30 );
$aivt_run_id    = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : ( $aivt_runs ? (int) $aivt_runs[0]['id'] : 0 );
$aivt_f_engine  = isset( $_GET['engine'] ) ? sanitize_key( $_GET['engine'] ) : '';
$aivt_f_mention = isset( $_GET['mentioned'] ) ? sanitize_key( $_GET['mentioned'] ) : '';
$aivt_f_topic   = isset( $_GET['topic'] ) ? sanitize_text_field( wp_unslash( $_GET['topic'] ) ) : '';
$aivt_f_prompt  = isset( $_GET['prompt'] ) ? absint( $_GET['prompt'] ) : 0;
// phpcs:enable
$aivt_run = $aivt_run_id ? $repo->run( $aivt_run_id ) : null;

if ( ! $aivt_run ) :
	?>
	<section class="aivt-card">
		<p class="aivt-empty"><?php esc_html_e( 'No checks yet. Run your first check from the Dashboard.', 'ai-visibility-tracker' ); ?></p>
	</section>
	<?php
	return;
endif;

$aivt_results = $repo->results(
	(int) $aivt_run['id'],
	array(
		'engine'    => $aivt_f_engine,
		'mentioned' => $aivt_f_mention,
		'topic'     => $aivt_f_topic,
		'prompt_id' => $aivt_f_prompt,
	)
);
$aivt_topics = $repo->topics();
$aivt_run_engines = array_filter( explode( ',', $aivt_run['engines'] ) );
$aivt_fmt         = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>

<section class="aivt-card">
	<form method="get" class="aivt-filters">
		<input type="hidden" name="page" value="aivt-results">
		<label>
			<span><?php esc_html_e( 'Check', 'ai-visibility-tracker' ); ?></span>
			<select name="run">
				<?php foreach ( $aivt_runs as $aivt_r ) : ?>
					<option value="<?php echo (int) $aivt_r['id']; ?>" <?php selected( (int) $aivt_r['id'], (int) $aivt_run['id'] ); ?>>
						<?php
						echo esc_html( wp_date( $aivt_fmt, strtotime( $aivt_r['created_at'] . ' UTC' ) ) );
						if ( 'running' === $aivt_r['status'] ) {
							echo ' — ' . esc_html__( 'running', 'ai-visibility-tracker' );
						}
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'Engine', 'ai-visibility-tracker' ); ?></span>
			<select name="engine">
				<option value=""><?php esc_html_e( 'All engines', 'ai-visibility-tracker' ); ?></option>
				<?php foreach ( $aivt_run_engines as $aivt_e ) : ?>
					<option value="<?php echo esc_attr( $aivt_e ); ?>" <?php selected( $aivt_e, $aivt_f_engine ); ?>><?php echo esc_html( Engines::label( $aivt_e ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php if ( $aivt_topics ) : ?>
			<label>
				<span><?php esc_html_e( 'Topic', 'ai-visibility-tracker' ); ?></span>
				<select name="topic">
					<option value=""><?php esc_html_e( 'All topics', 'ai-visibility-tracker' ); ?></option>
					<?php foreach ( $aivt_topics as $aivt_t ) : ?>
						<option value="<?php echo esc_attr( $aivt_t ); ?>" <?php selected( $aivt_t, $aivt_f_topic ); ?>><?php echo esc_html( $aivt_t ); ?></option>
					<?php endforeach; ?>
					<option value="-" <?php selected( '-', $aivt_f_topic ); ?>><?php esc_html_e( 'No topic', 'ai-visibility-tracker' ); ?></option>
				</select>
			</label>
		<?php endif; ?>
		<label>
			<span><?php esc_html_e( 'Brand', 'ai-visibility-tracker' ); ?></span>
			<select name="mentioned">
				<option value=""><?php esc_html_e( 'All answers', 'ai-visibility-tracker' ); ?></option>
				<option value="yes" <?php selected( 'yes', $aivt_f_mention ); ?>><?php esc_html_e( 'Mentioned', 'ai-visibility-tracker' ); ?></option>
				<option value="no" <?php selected( 'no', $aivt_f_mention ); ?>><?php esc_html_e( 'Not mentioned', 'ai-visibility-tracker' ); ?></option>
			</select>
		</label>
		<?php if ( $aivt_f_prompt ) : ?>
			<input type="hidden" name="prompt" value="<?php echo (int) $aivt_f_prompt; ?>">
		<?php endif; ?>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ai-visibility-tracker' ); ?></button>
		<?php if ( $aivt_f_prompt ) : ?>
			<a class="button-link" href="<?php echo esc_url( Admin::url( 'aivt-results', array( 'run' => (int) $aivt_run['id'] ) ) ); ?>"><?php esc_html_e( 'Show all prompts', 'ai-visibility-tracker' ); ?></a>
		<?php endif; ?>
		<span class="aivt-filters__end">
			<span class="aivt-muted aivt-small">
				<?php
				/* translators: %s: cost of the check */
				printf( esc_html__( 'Cost: %s', 'ai-visibility-tracker' ), esc_html( \AIVT\Pricing::format( (float) $aivt_run['cost'] ) ) );
				?>
			</span>
			<a class="button" href="<?php echo esc_url( Admin::action_url( 'export', array( 'what' => 'results', 'run' => (int) $aivt_run['id'] ) ) ); ?>"><?php esc_html_e( 'Export CSV', 'ai-visibility-tracker' ); ?></a>
			<a class="button-link aivt-danger" href="<?php echo esc_url( Admin::action_url( 'delete_run', array( 'run' => (int) $aivt_run['id'] ) ) ); ?>" data-aivt-confirm-link="<?php esc_attr_e( 'Delete this check and all its answers? This cannot be undone.', 'ai-visibility-tracker' ); ?>"><?php esc_html_e( 'Delete check', 'ai-visibility-tracker' ); ?></a>
		</span>
	</form>

	<table class="aivt-table aivt-results">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Prompt', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Engine', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Your brand', 'ai-visibility-tracker' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Position', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Site cited', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ai-visibility-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $aivt_results ) : ?>
				<tr><td colspan="6" class="aivt-empty"><?php esc_html_e( 'No answers match these filters.', 'ai-visibility-tracker' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $aivt_results as $aivt_r ) : ?>
				<tr>
					<td>
						<?php echo esc_html( $aivt_r['prompt'] ); ?>
						<?php if ( $aivt_r['topic'] ) : ?>
							<span class="aivt-chip aivt-chip--topic"><?php echo esc_html( $aivt_r['topic'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo Admin::engine_tag( $aivt_r['engine'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<?php if ( 'done' === $aivt_r['status'] ) : ?>
						<td>
							<?php if ( $aivt_r['brand_mentioned'] ) : ?>
								<span class="aivt-status-pill is-yes"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Mentioned', 'ai-visibility-tracker' ); ?></span>
							<?php else : ?>
								<span class="aivt-status-pill is-no"><span class="dashicons dashicons-minus" aria-hidden="true"></span><?php esc_html_e( 'Not mentioned', 'ai-visibility-tracker' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo $aivt_r['brand_position'] ? '#' . (int) $aivt_r['brand_position'] : '—'; ?></td>
						<td><?php echo $aivt_r['brand_cited'] ? esc_html__( 'Yes', 'ai-visibility-tracker' ) : esc_html__( 'No', 'ai-visibility-tracker' ); ?></td>
						<td class="actions"><button type="button" class="button button-small" data-aivt-result="<?php echo (int) $aivt_r['id']; ?>"><?php esc_html_e( 'View answer', 'ai-visibility-tracker' ); ?></button></td>
					<?php elseif ( 'failed' === $aivt_r['status'] ) : ?>
						<td colspan="4"><span class="aivt-status-pill is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e( 'Failed', 'ai-visibility-tracker' ); ?></span> <span class="aivt-small aivt-muted"><?php echo esc_html( (string) $aivt_r['error'] ); ?></span></td>
					<?php else : ?>
						<td colspan="4" class="aivt-muted"><?php esc_html_e( 'Waiting…', 'ai-visibility-tracker' ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>

<?php require __DIR__ . '/partials/modal.php'; ?>
