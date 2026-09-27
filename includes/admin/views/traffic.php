<?php
/**
 * AI traffic: visitors sent by AI assistants and visits from AI crawlers.
 *
 * @package AIVT
 *
 * @var \AIVT\Repository $repo
 * @var array            $settings
 */

use AIVT\Admin\Admin;
use AIVT\Traffic;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
$aivt_days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
$aivt_days = in_array( $aivt_days, array( 7, 30, 90 ), true ) ? $aivt_days : 30;

$aivt_to        = current_time( 'Y-m-d' );
$aivt_to_ts     = strtotime( $aivt_to . ' 00:00:00 UTC' );
$aivt_from      = gmdate( 'Y-m-d', $aivt_to_ts - ( $aivt_days - 1 ) * DAY_IN_SECONDS );
$aivt_prev_to   = gmdate( 'Y-m-d', $aivt_to_ts - $aivt_days * DAY_IN_SECONDS );
$aivt_prev_from = gmdate( 'Y-m-d', $aivt_to_ts - ( 2 * $aivt_days - 1 ) * DAY_IN_SECONDS );

$aivt_daily     = $repo->traffic_by_day( 'referral', $aivt_from, $aivt_to );
$aivt_total     = array_sum( $aivt_daily );
$aivt_prev      = $repo->traffic_total( 'referral', $aivt_prev_from, $aivt_prev_to );
$aivt_sources   = $repo->traffic_by( 'referral', 'source', $aivt_from, $aivt_to, 10 );
$aivt_landing   = $repo->traffic_by( 'referral', 'path', $aivt_from, $aivt_to, 10 );
$aivt_bots      = $repo->traffic_by( 'bot', 'source', $aivt_from, $aivt_to, 20 );
$aivt_bot_pages = $repo->traffic_by( 'bot', 'path', $aivt_from, $aivt_to, 10 );
$aivt_bot_total = $repo->traffic_total( 'bot', $aivt_from, $aivt_to );
$aivt_bot_info  = Traffic::bots();

$aivt_change = null;
if ( $aivt_prev > 0 ) {
	$aivt_change = (int) round( 100 * ( $aivt_total - $aivt_prev ) / $aivt_prev );
}

$aivt_purpose = array(
	'search'   => __( 'Search / answers', 'ai-visibility-tracker' ),
	'user'     => __( 'Fetched for a user', 'ai-visibility-tracker' ),
	'training' => __( 'Training', 'ai-visibility-tracker' ),
);
?>

<?php if ( ! $settings['track_traffic'] ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'AI traffic tracking is turned off, so no new visits are being counted.', 'ai-visibility-tracker' ); ?>
			<a href="<?php echo esc_url( Admin::url( 'aivt-settings' ) ); ?>#tracking"><?php esc_html_e( 'Turn it on', 'ai-visibility-tracker' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<div class="aivt-toolbar">
	<p class="aivt-muted"><?php esc_html_e( 'Counted on your own site. No cookies, IP addresses or personal data are stored.', 'ai-visibility-tracker' ); ?></p>
	<nav class="aivt-segmented" aria-label="<?php esc_attr_e( 'Date range', 'ai-visibility-tracker' ); ?>">
		<?php foreach ( array( 7, 30, 90 ) as $aivt_d ) : ?>
			<a href="<?php echo esc_url( Admin::url( 'aivt-traffic', array( 'days' => $aivt_d ) ) ); ?>" class="<?php echo $aivt_d === $aivt_days ? 'is-active' : ''; ?>" <?php echo $aivt_d === $aivt_days ? 'aria-current="page"' : ''; ?>>
				<?php
				/* translators: %d: number of days */
				printf( esc_html__( '%d days', 'ai-visibility-tracker' ), (int) $aivt_d );
				?>
			</a>
		<?php endforeach; ?>
	</nav>
</div>

<section class="aivt-tiles" aria-label="<?php esc_attr_e( 'Summary', 'ai-visibility-tracker' ); ?>">
	<div class="aivt-tile aivt-tile--hero">
		<span class="aivt-tile__label"><?php esc_html_e( 'Visitors from AI', 'ai-visibility-tracker' ); ?></span>
		<span class="aivt-tile__value"><?php echo esc_html( number_format_i18n( $aivt_total ) ); ?></span>
		<span class="aivt-tile__note">
			<?php if ( null === $aivt_change ) : ?>
				<?php esc_html_e( 'No data for the previous period yet', 'ai-visibility-tracker' ); ?>
			<?php else : ?>
				<span class="aivt-delta <?php echo $aivt_change >= 0 ? 'is-up' : 'is-down'; ?>"><?php echo esc_html( ( $aivt_change >= 0 ? '▲ +' : '▼ ' ) . $aivt_change . '%' ); ?></span>
				<?php
				/* translators: %d: number of days */
				printf( esc_html__( 'vs previous %d days', 'ai-visibility-tracker' ), (int) $aivt_days );
				?>
			<?php endif; ?>
		</span>
	</div>
	<div class="aivt-tile">
		<span class="aivt-tile__label"><?php esc_html_e( 'Top AI source', 'ai-visibility-tracker' ); ?></span>
		<span class="aivt-tile__value aivt-tile__value--text"><?php echo $aivt_sources ? esc_html( Traffic::source_label( $aivt_sources[0]['k'] ) ) : '—'; ?></span>
		<span class="aivt-tile__note">
			<?php
			if ( $aivt_sources ) {
				/* translators: %s: number of visits */
				printf( esc_html__( '%s visits', 'ai-visibility-tracker' ), esc_html( number_format_i18n( (int) $aivt_sources[0]['hits'] ) ) );
			}
			?>
		</span>
	</div>
	<div class="aivt-tile">
		<span class="aivt-tile__label"><?php esc_html_e( 'AI crawler visits', 'ai-visibility-tracker' ); ?></span>
		<span class="aivt-tile__value"><?php echo esc_html( number_format_i18n( $aivt_bot_total ) ); ?></span>
		<span class="aivt-tile__note">
			<?php
			/* translators: %d: number of different crawlers */
			printf( esc_html( _n( 'From %d AI crawler', 'From %d AI crawlers', count( $aivt_bots ), 'ai-visibility-tracker' ) ), count( $aivt_bots ) );
			?>
		</span>
	</div>
</section>

<section class="aivt-card">
	<h2><?php esc_html_e( 'Visitors from AI per day', 'ai-visibility-tracker' ); ?></h2>
	<?php if ( ! $aivt_total ) : ?>
		<p class="aivt-empty"><?php esc_html_e( 'No visitors from AI assistants in this period yet. When ChatGPT, Perplexity, Gemini or others link to your site and people click, they will show up here.', 'ai-visibility-tracker' ); ?></p>
	<?php else : ?>
		<?php
		$aivt_max   = max( 1, max( $aivt_daily ) );
		$aivt_w     = 720;
		$aivt_h     = 200;
		$aivt_pl    = 36;
		$aivt_pb    = 24;
		$aivt_pt    = 8;
		$aivt_ph    = $aivt_h - $aivt_pb - $aivt_pt;
		$aivt_slot  = ( $aivt_w - $aivt_pl ) / $aivt_days;
		$aivt_bw    = max( 2, $aivt_slot - 2 );
		$aivt_ticks = array( 0, (int) ceil( $aivt_max / 2 ), $aivt_max );
		?>
		<div class="aivt-chart" data-aivt-chart>
			<svg viewBox="0 0 <?php echo (int) $aivt_w; ?> <?php echo (int) $aivt_h; ?>" role="img" aria-label="<?php esc_attr_e( 'Bar chart of daily visitors from AI assistants. A table version follows.', 'ai-visibility-tracker' ); ?>">
				<?php foreach ( array_unique( $aivt_ticks ) as $aivt_t ) : ?>
					<?php $aivt_ty = $aivt_pt + $aivt_ph * ( 1 - $aivt_t / $aivt_max ); ?>
					<line class="aivt-chart__grid" x1="<?php echo (int) $aivt_pl; ?>" x2="<?php echo (int) $aivt_w; ?>" y1="<?php echo esc_attr( $aivt_ty ); ?>" y2="<?php echo esc_attr( $aivt_ty ); ?>" />
					<text class="aivt-chart__axis" x="<?php echo (int) ( $aivt_pl - 6 ); ?>" y="<?php echo esc_attr( $aivt_ty + 4 ); ?>" text-anchor="end"><?php echo (int) $aivt_t; ?></text>
				<?php endforeach; ?>
				<line class="aivt-chart__crosshair" data-aivt-crosshair x1="0" x2="0" y1="<?php echo (int) $aivt_pt; ?>" y2="<?php echo (int) ( $aivt_pt + $aivt_ph ); ?>" hidden />
				<?php for ( $aivt_i = 0; $aivt_i < $aivt_days; $aivt_i++ ) : ?>
					<?php
					$aivt_day  = gmdate( 'Y-m-d', strtotime( $aivt_from . ' 00:00:00 UTC' ) + $aivt_i * DAY_IN_SECONDS );
					$aivt_v    = isset( $aivt_daily[ $aivt_day ] ) ? $aivt_daily[ $aivt_day ] : 0;
					$aivt_bh   = $aivt_ph * $aivt_v / $aivt_max;
					$aivt_x    = $aivt_pl + $aivt_i * $aivt_slot + 1;
					$aivt_cx   = $aivt_x + $aivt_bw / 2;
					$aivt_lbl  = wp_date( 'M j', strtotime( $aivt_day . ' 12:00:00 UTC' ) );
					$aivt_step = (int) ceil( $aivt_days / 8 );
					?>
					<?php if ( $aivt_v > 0 ) : ?>
						<path class="aivt-chart__bar" d="<?php echo esc_attr( sprintf( 'M%1$.1f %2$.1f V%3$.1f Q%1$.1f %4$.1f %5$.1f %4$.1f H%6$.1f Q%7$.1f %4$.1f %7$.1f %3$.1f V%2$.1f Z', $aivt_x, $aivt_pt + $aivt_ph, $aivt_pt + $aivt_ph - $aivt_bh + min( 3, $aivt_bh ), $aivt_pt + $aivt_ph - $aivt_bh, $aivt_x + min( 3, $aivt_bw / 2 ), $aivt_x + $aivt_bw - min( 3, $aivt_bw / 2 ), $aivt_x + $aivt_bw ) ); ?>" />
					<?php endif; ?>
					<?php if ( ( 0 === $aivt_i % $aivt_step && $aivt_days - 1 - $aivt_i >= $aivt_step / 2 ) || $aivt_i === $aivt_days - 1 ) : ?>
						<text class="aivt-chart__axis" x="<?php echo esc_attr( $aivt_cx ); ?>" y="<?php echo (int) ( $aivt_h - 6 ); ?>" text-anchor="middle"><?php echo esc_html( $aivt_lbl ); ?></text>
					<?php endif; ?>
					<rect class="aivt-chart__hit" x="<?php echo esc_attr( $aivt_pl + $aivt_i * $aivt_slot ); ?>" y="0" width="<?php echo esc_attr( $aivt_slot ); ?>" height="<?php echo (int) ( $aivt_pt + $aivt_ph ); ?>"
						data-x="<?php echo esc_attr( $aivt_cx ); ?>"
						data-title="<?php echo esc_attr( $aivt_lbl ); ?>"
						data-rows="<?php echo esc_attr( wp_json_encode( array( array( 'engine' => '', 'label' => __( 'Visitors', 'ai-visibility-tracker' ), 'value' => number_format_i18n( $aivt_v ) ) ) ) ); ?>" />
				<?php endfor; ?>
			</svg>
			<div class="aivt-tooltip" data-aivt-tooltip hidden></div>
		</div>
		<details class="aivt-tableview">
			<summary><?php esc_html_e( 'View as table', 'ai-visibility-tracker' ); ?></summary>
			<table class="aivt-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Day', 'ai-visibility-tracker' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Visitors', 'ai-visibility-tracker' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_reverse( $aivt_daily, true ) as $aivt_day => $aivt_v ) : ?>
						<tr><td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $aivt_day . ' 12:00:00 UTC' ) ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $aivt_v ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>
</section>

<div class="aivt-grid">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Visitors by AI assistant', 'ai-visibility-tracker' ); ?></h2>
		<?php if ( ! $aivt_sources ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'None yet.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<ul class="aivt-bars aivt-bars--mono">
				<?php foreach ( $aivt_sources as $aivt_s ) : ?>
					<?php $aivt_pct = (int) round( 100 * $aivt_s['hits'] / max( 1, $aivt_total ) ); ?>
					<li class="aivt-bar">
						<span class="aivt-bar__name"><?php echo esc_html( Traffic::source_label( $aivt_s['k'] ) ); ?></span>
						<span class="aivt-bar__track" role="img" aria-label="<?php echo esc_attr( $aivt_pct . '%' ); ?>"><span class="aivt-bar__fill" style="width: <?php echo (int) $aivt_pct; ?>%"></span></span>
						<span class="aivt-bar__value"><?php echo esc_html( number_format_i18n( (int) $aivt_s['hits'] ) ); ?></span>
						<span class="aivt-bar__meta"><?php echo (int) $aivt_pct; ?>%</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="aivt-card">
		<h2><?php esc_html_e( 'Pages AI sends visitors to', 'ai-visibility-tracker' ); ?></h2>
		<?php if ( ! $aivt_landing ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'None yet.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<table class="aivt-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'ai-visibility-tracker' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Visitors', 'ai-visibility-tracker' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $aivt_landing as $aivt_l ) : ?>
						<tr>
							<td><?php echo Admin::page_link( (int) $aivt_l['post_id'], $aivt_l['k'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $aivt_l['hits'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>

<div class="aivt-grid">
	<section class="aivt-card" id="crawlers">
		<h2><?php esc_html_e( 'AI crawlers visiting your site', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted"><?php esc_html_e( 'Search crawlers decide whether you can appear in AI answers. If a page cache serves your pages, some crawler visits are not counted.', 'ai-visibility-tracker' ); ?></p>
		<?php if ( ! $aivt_bots ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'No AI crawler visits recorded in this period.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<table class="aivt-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Crawler', 'ai-visibility-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Purpose', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Visits', 'ai-visibility-tracker' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Last seen', 'ai-visibility-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aivt_bots as $aivt_b ) : ?>
						<?php $aivt_i = isset( $aivt_bot_info[ $aivt_b['k'] ] ) ? $aivt_bot_info[ $aivt_b['k'] ] : array( '', '' ); ?>
						<tr>
							<td><strong><?php echo esc_html( $aivt_b['k'] ); ?></strong> <span class="aivt-muted aivt-small"><?php echo esc_html( $aivt_i[0] ); ?></span></td>
							<td class="aivt-small"><?php echo isset( $aivt_purpose[ $aivt_i[1] ] ) ? esc_html( $aivt_purpose[ $aivt_i[1] ] ) : ''; ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $aivt_b['hits'] ) ); ?></td>
							<td class="num"><?php echo esc_html( wp_date( 'M j', strtotime( substr( $aivt_b['last_day'], 0, 10 ) . ' 12:00:00 UTC' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aivt-card">
		<h2><?php esc_html_e( 'Pages AI crawlers read most', 'ai-visibility-tracker' ); ?></h2>
		<?php if ( ! $aivt_bot_pages ) : ?>
			<p class="aivt-empty"><?php esc_html_e( 'None yet.', 'ai-visibility-tracker' ); ?></p>
		<?php else : ?>
			<table class="aivt-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'ai-visibility-tracker' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Visits', 'ai-visibility-tracker' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $aivt_bot_pages as $aivt_l ) : ?>
						<tr>
							<td><?php echo Admin::page_link( (int) $aivt_l['post_id'], $aivt_l['k'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $aivt_l['hits'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
