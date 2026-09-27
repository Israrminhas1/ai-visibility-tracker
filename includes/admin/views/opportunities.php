<?php
/**
 * Opportunities: prompts where AI left the brand out, and what to do about it.
 *
 * @package AIVT
 *
 * @var \AIVT\Repository $repo
 * @var array            $settings
 */

use AIVT\Admin\Admin;
use AIVT\Analyzer;
use AIVT\Engines;

defined( 'ABSPATH' ) || exit;

$aivt_run = $repo->latest_completed_run();
if ( ! $aivt_run ) :
	?>
	<section class="aivt-card">
		<p class="aivt-empty"><?php esc_html_e( 'Run your first check from the Dashboard to see opportunities.', 'ai-visibility-tracker' ); ?></p>
	</section>
	<?php
	return;
endif;

$aivt_opps = $repo->opportunities( (int) $aivt_run['id'] );

// Sources and brands across all missed answers.
$aivt_all_domains = array();
$aivt_all_brands  = array();
foreach ( $aivt_opps as $aivt_o ) {
	foreach ( $aivt_o['domains'] as $aivt_d => $aivt_n ) {
		$aivt_all_domains[ $aivt_d ] = ( isset( $aivt_all_domains[ $aivt_d ] ) ? $aivt_all_domains[ $aivt_d ] : 0 ) + $aivt_n;
	}
	foreach ( $aivt_o['brands'] as $aivt_b => $aivt_n ) {
		$aivt_all_brands[ $aivt_b ] = ( isset( $aivt_all_brands[ $aivt_b ] ) ? $aivt_all_brands[ $aivt_b ] : 0 ) + $aivt_n;
	}
}
// The brand's own site can't be a place to "get featured".
$aivt_all_domains = array_filter(
	$aivt_all_domains,
	function ( $d ) use ( $settings ) {
		return 'brand' !== Analyzer::source_type( $d, $settings );
	},
	ARRAY_FILTER_USE_KEY
);
arsort( $aivt_all_domains );
arsort( $aivt_all_brands );

$aivt_advice = function ( $domain ) use ( $settings ) {
	switch ( Analyzer::source_type( $domain, $settings ) ) {
		case 'competitor':
			return __( 'Competitor page. Study what it covers that your site doesn\'t.', 'ai-visibility-tracker' );
		case 'social':
			return __( 'Join the conversation here: answer questions and share helpful posts.', 'ai-visibility-tracker' );
		default:
			return __( 'Get listed, reviewed or featured on this site.', 'ai-visibility-tracker' );
	}
};
?>

<?php if ( ! $aivt_opps ) : ?>
	<section class="aivt-card aivt-empty-state">
		<span class="dashicons dashicons-awards" aria-hidden="true"></span>
		<h2><?php esc_html_e( 'AI mentioned you in every answer.', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Add more prompts to find new areas where AI doesn\'t know you yet.', 'ai-visibility-tracker' ); ?></p>
		<a class="button button-primary" href="<?php echo esc_url( Admin::url( 'aivt-prompts' ) ); ?>"><?php esc_html_e( 'Add prompts', 'ai-visibility-tracker' ); ?></a>
	</section>
	<?php
	return;
endif;
?>

<div class="aivt-grid">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Where to get featured', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'The sources AI relied on when it left you out. Appearing on these is the fastest way into AI answers.', 'ai-visibility-tracker' ); ?></p>
		<table class="aivt-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Website', 'ai-visibility-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What to do', 'ai-visibility-tracker' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Answers', 'ai-visibility-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $aivt_all_domains, 0, 8, true ) as $aivt_d => $aivt_n ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $aivt_d ); ?></strong> <?php echo Admin::type_badge( Analyzer::source_type( $aivt_d, $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="aivt-small"><?php echo esc_html( $aivt_advice( $aivt_d ) ); ?></td>
						<td class="num"><?php echo (int) $aivt_n; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $aivt_all_domains ) : ?>
					<tr><td colspan="3" class="aivt-empty"><?php esc_html_e( 'These answers cited no sources.', 'ai-visibility-tracker' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</section>

	<section class="aivt-card">
		<h2><?php esc_html_e( 'Who wins when you\'re missing', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Brands AI recommended in the answers that left you out.', 'ai-visibility-tracker' ); ?></p>
		<?php if ( ! $aivt_all_brands ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'No other brands were named either. AI may not know any business for these questions yet, which is a real opening for you.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<ol class="aivt-rank">
				<?php foreach ( array_slice( $aivt_all_brands, 0, 8, true ) as $aivt_b => $aivt_n ) : ?>
					<li>
						<span><?php echo esc_html( $aivt_b ); ?> <?php echo Admin::is_competitor( $aivt_b, $settings ) ? Admin::type_badge( 'competitor' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="aivt-muted">
							<?php
							/* translators: %d: number of answers */
							printf( esc_html( _n( '%d answer', '%d answers', $aivt_n, 'ai-visibility-tracker' ) ), (int) $aivt_n );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</section>
</div>

<section class="aivt-card">
	<h2>
		<?php
		/* translators: %d: number of prompts */
		printf( esc_html( _n( '%d prompt where AI left you out', '%d prompts where AI left you out', count( $aivt_opps ), 'ai-visibility-tracker' ) ), count( $aivt_opps ) );
		?>
	</h2>
	<p class="aivt-muted"><?php esc_html_e( 'Most urgent first: missing on every engine while competitors are named.', 'ai-visibility-tracker' ); ?></p>

	<div class="aivt-opps">
		<?php foreach ( $aivt_opps as $aivt_o ) : ?>
			<?php
			$aivt_missed = array_values( array_unique( $aivt_o['missed'] ) );
			$aivt_level  = $aivt_o['severity'] >= 80 ? 'high' : ( $aivt_o['severity'] >= 40 ? 'medium' : 'low' );
			$aivt_levels = array(
				'high'   => __( 'High priority', 'ai-visibility-tracker' ),
				'medium' => __( 'Medium priority', 'ai-visibility-tracker' ),
				'low'    => __( 'Low priority', 'ai-visibility-tracker' ),
			);
			?>
			<article class="aivt-opp aivt-opp--<?php echo esc_attr( $aivt_level ); ?>">
				<header class="aivt-opp__head">
					<span class="aivt-priority aivt-priority--<?php echo esc_attr( $aivt_level ); ?>"><?php echo esc_html( $aivt_levels[ $aivt_level ] ); ?></span>
					<?php if ( $aivt_o['topic'] ) : ?>
						<span class="aivt-chip aivt-chip--topic"><?php echo esc_html( $aivt_o['topic'] ); ?></span>
					<?php endif; ?>
					<h3>“<?php echo esc_html( $aivt_o['prompt'] ); ?>”</h3>
				</header>

				<dl class="aivt-opp__facts">
					<div>
						<dt><?php esc_html_e( 'Missing on', 'ai-visibility-tracker' ); ?></dt>
						<dd>
							<?php
							/* translators: 1: engines missing, 2: engines answered */
							printf( esc_html__( '%1$d of %2$d engines', 'ai-visibility-tracker' ), count( $aivt_missed ), (int) $aivt_o['answers'] );
							?>
							<span class="aivt-tags">
								<?php foreach ( $aivt_missed as $aivt_e ) : ?>
									<?php echo Admin::engine_tag( $aivt_e ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endforeach; ?>
							</span>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Recommended instead', 'ai-visibility-tracker' ); ?></dt>
						<dd><?php echo $aivt_o['brands'] ? esc_html( implode( ', ', array_slice( array_keys( $aivt_o['brands'] ), 0, 5 ) ) ) : '<span class="aivt-muted">' . esc_html__( 'No brands named', 'ai-visibility-tracker' ) . '</span>'; ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Sources used', 'ai-visibility-tracker' ); ?></dt>
						<dd><?php echo $aivt_o['domains'] ? esc_html( implode( ', ', array_slice( array_keys( $aivt_o['domains'] ), 0, 5 ) ) ) : '<span class="aivt-muted">' . esc_html__( 'None cited', 'ai-visibility-tracker' ) . '</span>'; ?></dd>
					</div>
				</dl>

				<footer class="aivt-opp__actions">
					<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'page', 'post_title' => rawurlencode( $aivt_o['prompt'] ) ), admin_url( 'post-new.php' ) ) ); ?>">
						<?php esc_html_e( 'Write a page that answers this', 'ai-visibility-tracker' ); ?>
					</a>
					<a class="button-link" href="<?php echo esc_url( Admin::url( 'aivt-results', array( 'run' => (int) $aivt_run['id'], 'prompt' => (int) $aivt_o['prompt_id'] ) ) ); ?>"><?php esc_html_e( 'Read the answers', 'ai-visibility-tracker' ); ?></a>
				</footer>
			</article>
		<?php endforeach; ?>
	</div>
</section>
