<?php
/**
 * Dashboard view.
 *
 * @package AIVT
 *
 * @var \AIVT\Repository $repo
 * @var array            $settings
 */

use AIVT\Admin\Admin;
use AIVT\Engines;

defined( 'ABSPATH' ) || exit;

$aivt_engines     = Engines::all();
$aivt_active_ids  = Engines::active();
$aivt_prompts     = $repo->prompts( true );
$aivt_run         = $repo->latest_completed_run();
$aivt_active_run  = $repo->active_run();
$aivt_has_brand   = '' !== trim( (string) $settings['brand_name'] );
$aivt_setup_done  = $aivt_active_ids && $aivt_prompts && $aivt_has_brand;
?>

<?php if ( ! $aivt_run && ! $aivt_active_run ) : ?>
	<section class="aivt-card aivt-onboard">
		<h2><?php esc_html_e( 'Find out if AI recommends your business', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'We ask ChatGPT, Claude, Gemini, Perplexity and Grok the questions your customers ask, then show whether they mention you, which sources they cite, and which competitors they recommend instead.', 'ai-visibility-tracker' ); ?></p>
		<ol class="aivt-steps">
			<li class="<?php echo $aivt_active_ids ? 'is-done' : ''; ?>">
				<strong><?php esc_html_e( 'Connect at least one AI engine', 'ai-visibility-tracker' ); ?></strong>
				<span><?php esc_html_e( 'Add your own API key. You pay the AI provider directly, usually a few cents per run.', 'ai-visibility-tracker' ); ?></span>
				<a href="<?php echo esc_url( Admin::url( 'aivt-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'ai-visibility-tracker' ); ?></a>
			</li>
			<li class="<?php echo $aivt_has_brand ? 'is-done' : ''; ?>">
				<strong><?php esc_html_e( 'Check your brand name and website', 'ai-visibility-tracker' ); ?></strong>
				<span><?php esc_html_e( 'Add any other names customers use for you.', 'ai-visibility-tracker' ); ?></span>
				<a href="<?php echo esc_url( Admin::url( 'aivt-settings' ) ); ?>#brand"><?php esc_html_e( 'Edit brand', 'ai-visibility-tracker' ); ?></a>
			</li>
			<li class="<?php echo $aivt_prompts ? 'is-done' : ''; ?>">
				<strong><?php esc_html_e( 'Create your prompts', 'ai-visibility-tracker' ); ?></strong>
				<span><?php esc_html_e( 'Generate 10 from your site content, or write your own.', 'ai-visibility-tracker' ); ?></span>
				<a href="<?php echo esc_url( Admin::url( 'aivt-prompts' ) ); ?>"><?php esc_html_e( 'Manage prompts', 'ai-visibility-tracker' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Run your first check', 'ai-visibility-tracker' ); ?></strong>
				<span>
					<?php
					/* translators: 1: number of prompts, 2: number of engines */
					printf( esc_html__( '%1$d prompts × %2$d engines.', 'ai-visibility-tracker' ), count( $aivt_prompts ), count( $aivt_active_ids ) );
					?>
				</span>
				<button type="button" class="button button-primary" data-aivt-run <?php disabled( ! $aivt_setup_done ); ?>><?php esc_html_e( 'Run now', 'ai-visibility-tracker' ); ?></button>
			</li>
		</ol>
		<p class="aivt-muted aivt-onboard__more">
			<?php
			printf(
				/* translators: 1: link to AI Readiness, 2: link to AI Traffic */
				esc_html__( 'No API key needed for these: check your %1$s and see your %2$s.', 'ai-visibility-tracker' ),
				'<a href="' . esc_url( Admin::url( 'aivt-readiness' ) ) . '">' . esc_html__( 'AI Readiness', 'ai-visibility-tracker' ) . '</a>',
				'<a href="' . esc_url( Admin::url( 'aivt-traffic' ) ) . '">' . esc_html__( 'AI Traffic', 'ai-visibility-tracker' ) . '</a>'
			);
			?>
		</p>
	</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/run-bar.php'; ?>

<?php
if ( ! $aivt_run ) {
	return;
}

$aivt_run_id    = (int) $aivt_run['id'];
$aivt_stats     = $repo->engine_stats( $aivt_run_id );
$aivt_done      = (int) $aivt_run['done'];
$aivt_score     = $repo->run_score( $aivt_run_id );
$aivt_pos_sum   = 0;
$aivt_pos_count = 0;
foreach ( $aivt_stats as $aivt_s ) {
	if ( null !== $aivt_s['avg_position'] ) {
		$aivt_pos_sum   += $aivt_s['avg_position'] * $aivt_s['mentioned'];
		$aivt_pos_count += $aivt_s['mentioned'];
	}
}
$aivt_avg_pos = $aivt_pos_count ? round( $aivt_pos_sum / $aivt_pos_count, 1 ) : null;
$aivt_trend   = $repo->trend( 12 );
$aivt_topics  = $repo->topic_stats( $aivt_run_id );
$aivt_pages   = $repo->cited_pages( $aivt_run_id, 8 );
$aivt_opps    = $repo->opportunities( $aivt_run_id );
$aivt_domains = $repo->top_domains( $aivt_run_id, 10 );
$aivt_brands  = array_values(
	array_filter(
		$repo->top_brands( $aivt_run_id, 20 ),
		function ( $b ) {
			return ! \AIVT\Analyzer::is_platform_name( $b['name'] ) && ! \AIVT\Analyzer::is_platform( $b['domain'] );
		}
	)
);
$aivt_brands  = array_slice( $aivt_brands, 0, 10 );

$aivt_traffic = null;
if ( $settings['track_traffic'] ) {
	$aivt_to      = current_time( 'Y-m-d' );
	$aivt_from    = gmdate( 'Y-m-d', strtotime( $aivt_to ) - 29 * DAY_IN_SECONDS );
	$aivt_traffic = $repo->traffic_total( 'referral', $aivt_from, $aivt_to );
}
?>

<p class="aivt-muted aivt-asof">
	<?php
	/* translators: %s: date */
	printf( esc_html__( 'Latest completed check: %s', 'ai-visibility-tracker' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $aivt_run['finished_at'] . ' UTC' ) ) ) );
	?>
	· <a href="<?php echo esc_url( Admin::url( 'aivt-results', array( 'run' => $aivt_run_id ) ) ); ?>"><?php esc_html_e( 'See every answer', 'ai-visibility-tracker' ); ?></a>
</p>

<section class="aivt-tiles" aria-label="<?php esc_attr_e( 'Summary', 'ai-visibility-tracker' ); ?>">
	<div class="aivt-tile aivt-tile--hero">
		<span class="aivt-tile__label"><?php esc_html_e( 'AI visibility score', 'ai-visibility-tracker' ); ?></span>
		<span class="aivt-tile__value"><?php echo null === $aivt_score ? '—' : (int) $aivt_score; ?><small>/100</small></span>
		<span class="aivt-tile__note">
			<?php
			/* translators: 1: answers linking to the site, 2: answers mentioning the brand, 3: total answers */
			printf( esc_html__( 'Linked in %1$d and mentioned in %2$d of %3$d answers', 'ai-visibility-tracker' ), (int) $aivt_run['cited'], (int) $aivt_run['mentioned'], (int) $aivt_done );
			?>
			<span class="aivt-help" tabindex="0" title="<?php esc_attr_e( 'Each answer that links to your website scores 100, an answer that only mentions your brand scores 50, and an answer without you scores 0. The score is the average.', 'ai-visibility-tracker' ); ?>">?</span>
		</span>
	</div>
	<div class="aivt-tile">
		<span class="aivt-tile__label"><?php esc_html_e( 'Mention rate', 'ai-visibility-tracker' ); ?></span>
		<span class="aivt-tile__value"><?php echo esc_html( Admin::pct( (int) $aivt_run['mentioned'], $aivt_done ) ); ?></span>
		<span class="aivt-tile__note"><?php esc_html_e( 'Answers that name your brand', 'ai-visibility-tracker' ); ?></span>
	</div>
	<div class="aivt-tile">
		<span class="aivt-tile__label"><?php esc_html_e( 'Average position', 'ai-visibility-tracker' ); ?></span>
		<span class="aivt-tile__value"><?php echo null === $aivt_avg_pos ? '—' : esc_html( '#' . $aivt_avg_pos ); ?></span>
		<span class="aivt-tile__note"><?php esc_html_e( 'Your rank among brands named, when mentioned', 'ai-visibility-tracker' ); ?></span>
	</div>
	<?php if ( null !== $aivt_traffic ) : ?>
		<a class="aivt-tile aivt-tile--link" href="<?php echo esc_url( Admin::url( 'aivt-traffic' ) ); ?>">
			<span class="aivt-tile__label"><?php esc_html_e( 'AI visitors', 'ai-visibility-tracker' ); ?></span>
			<span class="aivt-tile__value"><?php echo esc_html( number_format_i18n( $aivt_traffic ) ); ?></span>
			<span class="aivt-tile__note"><?php esc_html_e( 'Sent by AI assistants, last 30 days', 'ai-visibility-tracker' ); ?></span>
		</a>
	<?php else : ?>
		<div class="aivt-tile">
			<span class="aivt-tile__label"><?php esc_html_e( 'Prompts tracked', 'ai-visibility-tracker' ); ?></span>
			<span class="aivt-tile__value"><?php echo (int) count( $aivt_prompts ); ?></span>
			<span class="aivt-tile__note">
				<?php
				/* translators: %d: number of engines */
				printf( esc_html( _n( 'Across %d AI engine', 'Across %d AI engines', count( $aivt_stats ), 'ai-visibility-tracker' ) ), count( $aivt_stats ) );
				?>
			</span>
		</div>
	<?php endif; ?>
</section>

<?php if ( $aivt_done < 10 ) : ?>
	<p class="aivt-notice-inline">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<?php
		/* translators: %d: number of answers */
		printf( esc_html__( 'This score is based on only %d answers. AI answers vary, so add more prompts or engines for a reliable picture.', 'ai-visibility-tracker' ), (int) $aivt_done );
		?>
	</p>
<?php endif; ?>

<?php if ( $aivt_opps ) : ?>
	<a class="aivt-card aivt-callout" href="<?php echo esc_url( Admin::url( 'aivt-opportunities' ) ); ?>">
		<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
		<span>
			<strong>
				<?php
				/* translators: %d: number of prompts */
				printf( esc_html( _n( 'AI left you out of %d prompt.', 'AI left you out of %d prompts.', count( $aivt_opps ), 'ai-visibility-tracker' ) ), count( $aivt_opps ) );
				?>
			</strong>
			<span class="aivt-muted"><?php esc_html_e( 'See who it recommended instead and where to get featured.', 'ai-visibility-tracker' ); ?></span>
		</span>
		<span class="aivt-callout__go" aria-hidden="true">→</span>
	</a>
<?php endif; ?>

<div class="aivt-grid">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Visibility by AI engine', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Visibility score per engine (links count double).', 'ai-visibility-tracker' ); ?></p>
		<ul class="aivt-bars">
			<?php foreach ( $aivt_engines as $aivt_id => $aivt_def ) : ?>
				<?php
				if ( ! isset( $aivt_stats[ $aivt_id ] ) ) {
					continue;
				}
				$aivt_s = $aivt_stats[ $aivt_id ];
				?>
				<li class="aivt-bar aivt-engine--<?php echo esc_attr( $aivt_id ); ?>">
					<span class="aivt-bar__name"><?php echo esc_html( $aivt_def['label'] ); ?></span>
					<span class="aivt-bar__track" role="img" aria-label="<?php echo esc_attr( $aivt_def['label'] . ': ' . $aivt_s['score'] . '/100' ); ?>">
						<span class="aivt-bar__fill" style="width: <?php echo (int) $aivt_s['score']; ?>%"></span>
					</span>
					<span class="aivt-bar__value"><?php echo (int) $aivt_s['score']; ?></span>
					<span class="aivt-bar__meta">
						<?php
						/* translators: 1: mentions, 2: answers */
						printf( esc_html__( '%1$d/%2$d named', 'ai-visibility-tracker' ), (int) $aivt_s['mentioned'], (int) $aivt_s['done'] );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section class="aivt-card">
		<?php require __DIR__ . '/partials/trend-chart.php'; ?>
	</section>
</div>

<div class="aivt-grid">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Visibility by topic', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Where AI already knows you, and where it doesn\'t.', 'ai-visibility-tracker' ); ?></p>
		<?php if ( 1 === count( $aivt_topics ) && '' === $aivt_topics[0]['topic'] ) : ?>
			<p class="aivt-empty">
				<?php esc_html_e( 'Your prompts have no topics yet.', 'ai-visibility-tracker' ); ?>
				<a href="<?php echo esc_url( Admin::url( 'aivt-prompts' ) ); ?>"><?php esc_html_e( 'Add topics to your prompts', 'ai-visibility-tracker' ); ?></a>
			</p>
		<?php else : ?>
			<table class="aivt-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Topic', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Prompts', 'ai-visibility-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Visibility score', 'ai-visibility-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aivt_topics as $aivt_t ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( Admin::url( 'aivt-results', array( 'run' => $aivt_run_id, 'topic' => '' === $aivt_t['topic'] ? '-' : $aivt_t['topic'] ) ) ); ?>">
									<?php echo '' === $aivt_t['topic'] ? esc_html__( 'No topic', 'ai-visibility-tracker' ) : esc_html( $aivt_t['topic'] ); ?>
								</a>
							</td>
							<td class="num"><?php echo (int) $aivt_t['prompts']; ?></td>
							<td>
								<span class="aivt-meter" role="img" aria-label="<?php echo esc_attr( $aivt_t['score'] . '/100' ); ?>"><span style="width: <?php echo (int) $aivt_t['score']; ?>%"></span></span>
								<span class="aivt-meter__value"><?php echo (int) $aivt_t['score']; ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aivt-card">
		<h2><?php esc_html_e( 'Your pages AI cites', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Pages on your site that AI answers link to. Keep these up to date: they are your strongest AI assets.', 'ai-visibility-tracker' ); ?></p>
		<?php if ( ! $aivt_pages ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'No answer linked to your website yet.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<table class="aivt-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Page', 'ai-visibility-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Engines', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Cited in', 'ai-visibility-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aivt_pages as $aivt_pg ) : ?>
						<tr>
							<td><?php echo Admin::page_link( $aivt_pg['post_id'], $aivt_pg['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="aivt-swatches">
								<?php foreach ( $aivt_pg['engines'] as $aivt_e ) : ?>
									<span class="aivt-swatch aivt-engine--<?php echo esc_attr( $aivt_e ); ?>" title="<?php echo esc_attr( Engines::label( $aivt_e ) ); ?>"></span>
								<?php endforeach; ?>
								<span class="screen-reader-text"><?php echo esc_html( implode( ', ', array_map( array( Engines::class, 'label' ), $aivt_pg['engines'] ) ) ); ?></span>
							</td>
							<td class="num">
								<?php
								/* translators: %d: number of answers */
								printf( esc_html( _n( '%d answer', '%d answers', (int) $aivt_pg['answers'], 'ai-visibility-tracker' ) ), (int) $aivt_pg['answers'] );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>

<div class="aivt-grid">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Who AI recommends instead', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Other brands named in the answers. Track the ones that are real competitors.', 'ai-visibility-tracker' ); ?></p>
		<?php if ( ! $aivt_brands ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'No other brands were mentioned.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<table class="aivt-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Brand', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Share of answers', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Avg. position', 'ai-visibility-tracker' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ai-visibility-tracker' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<tr class="aivt-row--you">
						<td><strong><?php echo esc_html( $settings['brand_name'] ); ?></strong> <?php echo Admin::type_badge( 'brand' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="num"><?php echo esc_html( Admin::pct( (int) $aivt_run['mentioned'], $aivt_done ) ); ?></td>
						<td class="num"><?php echo null === $aivt_avg_pos ? '—' : esc_html( '#' . $aivt_avg_pos ); ?></td>
						<td></td>
					</tr>
					<?php foreach ( $aivt_brands as $aivt_b ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $aivt_b['name'] ); ?>
								<?php if ( $aivt_b['domain'] ) : ?>
									<span class="aivt-muted aivt-small"><?php echo esc_html( $aivt_b['domain'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="num"><?php echo esc_html( Admin::pct( (int) $aivt_b['answers'], $aivt_done ) ); ?></td>
							<td class="num">#<?php echo esc_html( $aivt_b['avg_position'] ); ?></td>
							<td class="actions">
								<?php if ( Admin::is_competitor( $aivt_b['name'], $settings ) ) : ?>
									<?php echo Admin::type_badge( 'competitor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php Admin::form_fields( 'competitor' ); ?>
										<input type="hidden" name="do" value="add">
										<input type="hidden" name="back" value="aivt">
										<input type="hidden" name="name" value="<?php echo esc_attr( $aivt_b['name'] ); ?>">
										<input type="hidden" name="domain" value="<?php echo esc_attr( $aivt_b['domain'] ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Track', 'ai-visibility-tracker' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aivt-card">
		<h2><?php esc_html_e( 'Sources AI cites', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Websites the answers link to. Getting featured on popular sources is one of the best ways to be recommended.', 'ai-visibility-tracker' ); ?></p>
		<?php if ( ! $aivt_domains ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'No sources were cited.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<table class="aivt-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Website', 'ai-visibility-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Cited in', 'ai-visibility-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aivt_domains as $aivt_d ) : ?>
						<tr>
							<td><?php echo esc_html( $aivt_d['domain'] ); ?></td>
							<td><?php echo Admin::type_badge( Admin::domain_type( $aivt_d['domain'], $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="num">
								<?php
								/* translators: %d: number of answers */
								printf( esc_html( _n( '%d answer', '%d answers', (int) $aivt_d['answers'], 'ai-visibility-tracker' ) ), (int) $aivt_d['answers'] );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
