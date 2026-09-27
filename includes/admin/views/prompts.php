<?php
/**
 * Prompts view.
 *
 * @package AIVT
 *
 * @var \AIVT\Repository $repo
 * @var array            $settings
 */

use AIVT\Admin\Admin;
use AIVT\Engines;

defined( 'ABSPATH' ) || exit;

$aivt_prompts = $repo->prompts();
$aivt_topics  = $repo->topics();
$aivt_run     = $repo->latest_completed_run();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
$aivt_f_topic = isset( $_GET['topic'] ) ? sanitize_text_field( wp_unslash( $_GET['topic'] ) ) : '';
if ( '' !== $aivt_f_topic ) {
	$aivt_prompts = array_values(
		array_filter(
			$aivt_prompts,
			function ( $p ) use ( $aivt_f_topic ) {
				return ( '-' === $aivt_f_topic ? '' : $aivt_f_topic ) === $p['topic'];
			}
		)
	);
}
// Group by topic (untitled last), keeping creation order inside each topic.
usort(
	$aivt_prompts,
	function ( $a, $b ) {
		if ( $a['topic'] !== $b['topic'] ) {
			if ( '' === $a['topic'] || '' === $b['topic'] ) {
				return '' === $a['topic'] ? 1 : -1;
			}
			return strcasecmp( $a['topic'], $b['topic'] );
		}
		return (int) $a['id'] - (int) $b['id'];
	}
);
$aivt_engines = Engines::all();

// Latest result for each prompt on each engine.
$aivt_matrix = array();
$aivt_cols   = array();
if ( $aivt_run ) {
	foreach ( $repo->results( (int) $aivt_run['id'] ) as $aivt_r ) {
		$aivt_matrix[ $aivt_r['prompt_id'] ][ $aivt_r['engine'] ] = $aivt_r;
		$aivt_cols[ $aivt_r['engine'] ]                           = true;
	}
}
$aivt_intents = array(
	'best_of'      => __( 'Best of', 'ai-visibility-tracker' ),
	'comparison'   => __( 'Comparison', 'ai-visibility-tracker' ),
	'alternatives' => __( 'Alternatives', 'ai-visibility-tracker' ),
	'how_to'       => __( 'How to', 'ai-visibility-tracker' ),
	'problem'      => __( 'Problem', 'ai-visibility-tracker' ),
	'local'        => __( 'Local', 'ai-visibility-tracker' ),
	'pricing'      => __( 'Pricing', 'ai-visibility-tracker' ),
);
$aivt_can_generate = (bool) Engines::active();
?>

<?php require __DIR__ . '/partials/run-bar.php'; ?>

<div class="aivt-grid aivt-grid--wide-left">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Generate prompts from your website', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'We read your pages, products and posts, then write the kind of questions real customers ask AI. Your brand name is never included, so results are honest.', 'ai-visibility-tracker' ); ?></p>
		<div class="aivt-inline">
			<label for="aivt-gen-count"><?php esc_html_e( 'How many', 'ai-visibility-tracker' ); ?></label>
			<input type="number" id="aivt-gen-count" min="1" max="50" value="10" class="small-text">
			<button type="button" class="button button-primary" data-aivt-generate <?php disabled( ! $aivt_can_generate ); ?>><?php esc_html_e( 'Generate prompts', 'ai-visibility-tracker' ); ?></button>
		</div>
		<p class="aivt-status" data-aivt-generate-status role="status">
			<?php if ( ! $aivt_can_generate ) : ?>
				<?php
				printf(
					/* translators: %s: link to settings */
					esc_html__( 'Connect an AI engine in %s first.', 'ai-visibility-tracker' ),
					'<a href="' . esc_url( Admin::url( 'aivt-settings' ) ) . '">' . esc_html__( 'Settings', 'ai-visibility-tracker' ) . '</a>'
				);
				?>
			<?php endif; ?>
		</p>
	</section>

	<section class="aivt-card">
		<h2><?php esc_html_e( 'Add your own', 'ai-visibility-tracker' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php Admin::form_fields( 'add_prompts' ); ?>
			<label for="aivt-new-prompts" class="screen-reader-text"><?php esc_html_e( 'Prompts, one per line', 'ai-visibility-tracker' ); ?></label>
			<textarea id="aivt-new-prompts" name="prompts" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'One question per line, e.g. What is the best bakery in Austin for wedding cakes?', 'ai-visibility-tracker' ); ?>" required></textarea>
			<p class="aivt-inline">
				<label for="aivt-new-topic"><?php esc_html_e( 'Topic', 'ai-visibility-tracker' ); ?></label>
				<input type="text" id="aivt-new-topic" name="topic" list="aivt-topic-list" placeholder="<?php esc_attr_e( 'e.g. Wedding cakes (optional)', 'ai-visibility-tracker' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Add prompts', 'ai-visibility-tracker' ); ?></button>
			</p>
		</form>
	</section>
</div>

<datalist id="aivt-topic-list">
	<?php foreach ( $aivt_topics as $aivt_t ) : ?>
		<option value="<?php echo esc_attr( $aivt_t ); ?>"></option>
	<?php endforeach; ?>
</datalist>

<section class="aivt-card">
	<div class="aivt-card__head">
		<h2>
			<?php
			/* translators: %d: number of prompts */
			printf( esc_html__( 'Your prompts (%d)', 'ai-visibility-tracker' ), count( $aivt_prompts ) );
			?>
		</h2>
		<?php if ( $aivt_topics ) : ?>
			<form method="get" class="aivt-inline">
				<input type="hidden" name="page" value="aivt-prompts">
				<label for="aivt-topic-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by topic', 'ai-visibility-tracker' ); ?></label>
				<select id="aivt-topic-filter" name="topic" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All topics', 'ai-visibility-tracker' ); ?></option>
					<?php foreach ( $aivt_topics as $aivt_t ) : ?>
						<option value="<?php echo esc_attr( $aivt_t ); ?>" <?php selected( $aivt_t, $aivt_f_topic ); ?>><?php echo esc_html( $aivt_t ); ?></option>
					<?php endforeach; ?>
					<option value="-" <?php selected( '-', $aivt_f_topic ); ?>><?php esc_html_e( 'No topic', 'ai-visibility-tracker' ); ?></option>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'ai-visibility-tracker' ); ?></button></noscript>
			</form>
		<?php endif; ?>
	</div>
	<?php if ( $aivt_run ) : ?>
		<p class="aivt-muted"><?php esc_html_e( 'Dots show the latest check: filled = your brand was mentioned. Click one to read the answer.', 'ai-visibility-tracker' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $aivt_prompts ) : ?>
		<p class="aivt-empty"><?php esc_html_e( 'No prompts yet. Generate some from your website or add your own above.', 'ai-visibility-tracker' ); ?></p>
	<?php else : ?>
		<table class="aivt-table aivt-prompts">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Prompt', 'ai-visibility-tracker' ); ?></th>
					<?php foreach ( $aivt_engines as $aivt_id => $aivt_def ) : ?>
						<?php if ( isset( $aivt_cols[ $aivt_id ] ) ) : ?>
							<th scope="col" class="center"><?php echo esc_html( $aivt_def['label'] ); ?></th>
						<?php endif; ?>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ai-visibility-tracker' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php $aivt_last_topic = null; ?>
				<?php foreach ( $aivt_prompts as $aivt_p ) : ?>
					<?php if ( $aivt_topics && $aivt_p['topic'] !== $aivt_last_topic ) : ?>
						<?php $aivt_last_topic = $aivt_p['topic']; ?>
						<tr class="aivt-group"><th colspan="<?php echo (int) ( 2 + count( $aivt_cols ) ); ?>" scope="colgroup"><?php echo '' === $aivt_p['topic'] ? esc_html__( 'No topic', 'ai-visibility-tracker' ) : esc_html( $aivt_p['topic'] ); ?></th></tr>
					<?php endif; ?>
					<tr class="<?php echo $aivt_p['active'] ? '' : 'is-paused'; ?>">
						<td>
							<div class="aivt-prompt-text" data-aivt-prompt-text><?php echo esc_html( $aivt_p['prompt'] ); ?></div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aivt-prompt-edit" data-aivt-prompt-edit hidden>
								<?php Admin::form_fields( 'prompt_action' ); ?>
								<input type="hidden" name="id" value="<?php echo (int) $aivt_p['id']; ?>">
								<input type="hidden" name="do" value="edit">
								<label class="screen-reader-text" for="aivt-edit-<?php echo (int) $aivt_p['id']; ?>"><?php esc_html_e( 'Prompt text', 'ai-visibility-tracker' ); ?></label>
								<textarea id="aivt-edit-<?php echo (int) $aivt_p['id']; ?>" name="prompt" rows="2" class="large-text"><?php echo esc_textarea( $aivt_p['prompt'] ); ?></textarea>
								<span class="aivt-inline">
									<label for="aivt-edit-topic-<?php echo (int) $aivt_p['id']; ?>"><?php esc_html_e( 'Topic', 'ai-visibility-tracker' ); ?></label>
									<input type="text" id="aivt-edit-topic-<?php echo (int) $aivt_p['id']; ?>" name="topic" value="<?php echo esc_attr( $aivt_p['topic'] ); ?>" list="aivt-topic-list">
									<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Save', 'ai-visibility-tracker' ); ?></button>
								</span>
							</form>
							<div class="aivt-small aivt-muted">
								<?php if ( $aivt_p['topic'] && ! $aivt_topics ) : ?>
									<span class="aivt-chip aivt-chip--topic"><?php echo esc_html( $aivt_p['topic'] ); ?></span>
								<?php endif; ?>
								<?php if ( $aivt_p['intent'] && isset( $aivt_intents[ $aivt_p['intent'] ] ) ) : ?>
									<span class="aivt-chip"><?php echo esc_html( $aivt_intents[ $aivt_p['intent'] ] ); ?></span>
								<?php endif; ?>
								<?php echo 'generated' === $aivt_p['source'] ? esc_html__( 'Generated', 'ai-visibility-tracker' ) : esc_html__( 'Added by you', 'ai-visibility-tracker' ); ?>
								<?php if ( ! $aivt_p['active'] ) : ?>
									· <strong><?php esc_html_e( 'Paused', 'ai-visibility-tracker' ); ?></strong>
								<?php endif; ?>
							</div>
						</td>
						<?php foreach ( $aivt_engines as $aivt_id => $aivt_def ) : ?>
							<?php
							if ( ! isset( $aivt_cols[ $aivt_id ] ) ) {
								continue;
							}
							$aivt_cell = isset( $aivt_matrix[ $aivt_p['id'] ][ $aivt_id ] ) ? $aivt_matrix[ $aivt_p['id'] ][ $aivt_id ] : null;
							?>
							<td class="center">
								<?php if ( ! $aivt_cell ) : ?>
									<span class="aivt-muted">–</span>
								<?php elseif ( 'done' !== $aivt_cell['status'] ) : ?>
									<span class="aivt-dot aivt-dot--error" title="<?php echo esc_attr( (string) $aivt_cell['error'] ); ?>" aria-label="<?php esc_attr_e( 'Failed', 'ai-visibility-tracker' ); ?>">!</span>
								<?php else : ?>
									<?php
									$aivt_label = $aivt_cell['brand_mentioned']
										? ( $aivt_cell['brand_position'] ? sprintf( /* translators: %d: position */ __( 'Mentioned, position #%d', 'ai-visibility-tracker' ), $aivt_cell['brand_position'] ) : __( 'Mentioned', 'ai-visibility-tracker' ) )
										: __( 'Not mentioned', 'ai-visibility-tracker' );
									?>
									<button type="button" class="aivt-dot <?php echo $aivt_cell['brand_mentioned'] ? 'is-yes' : 'is-no'; ?>" data-aivt-result="<?php echo (int) $aivt_cell['id']; ?>" title="<?php echo esc_attr( $aivt_label ); ?>" aria-label="<?php echo esc_attr( $aivt_def['label'] . ': ' . $aivt_label ); ?>">
										<?php echo $aivt_cell['brand_mentioned'] && $aivt_cell['brand_position'] ? (int) $aivt_cell['brand_position'] : ''; ?>
									</button>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td class="actions">
							<button type="button" class="button-link" data-aivt-edit-toggle><?php esc_html_e( 'Edit', 'ai-visibility-tracker' ); ?></button>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php Admin::form_fields( 'prompt_action' ); ?>
								<input type="hidden" name="id" value="<?php echo (int) $aivt_p['id']; ?>">
								<input type="hidden" name="do" value="toggle">
								<button type="submit" class="button-link"><?php echo $aivt_p['active'] ? esc_html__( 'Pause', 'ai-visibility-tracker' ) : esc_html__( 'Resume', 'ai-visibility-tracker' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-aivt-confirm="delete">
								<?php Admin::form_fields( 'prompt_action' ); ?>
								<input type="hidden" name="id" value="<?php echo (int) $aivt_p['id']; ?>">
								<input type="hidden" name="do" value="delete">
								<button type="submit" class="button-link aivt-danger"><?php esc_html_e( 'Delete', 'ai-visibility-tracker' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php require __DIR__ . '/partials/modal.php'; ?>
