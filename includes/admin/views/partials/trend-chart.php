<?php
/**
 * Visibility trend: one line per engine across completed runs.
 *
 * @package AIVT
 *
 * @var array $aivt_trend
 * @var array $aivt_engines
 */

defined( 'ABSPATH' ) || exit;

$aivt_w     = 640;
$aivt_h     = 240;
$aivt_pad_l = 40;
$aivt_pad_r = 16;
$aivt_pad_t = 12;
$aivt_pad_b = 28;
$aivt_pw    = $aivt_w - $aivt_pad_l - $aivt_pad_r;
$aivt_ph    = $aivt_h - $aivt_pad_t - $aivt_pad_b;
$aivt_n     = count( $aivt_trend );

$aivt_x = function ( $i ) use ( $aivt_n, $aivt_pad_l, $aivt_pw ) {
	return $aivt_n > 1 ? $aivt_pad_l + $i * $aivt_pw / ( $aivt_n - 1 ) : $aivt_pad_l + $aivt_pw / 2;
};
$aivt_y = function ( $pct ) use ( $aivt_pad_t, $aivt_ph ) {
	return $aivt_pad_t + $aivt_ph * ( 1 - $pct / 100 );
};

// Engines that appear in any run, in fixed engine order (color follows the engine).
$aivt_series = array();
foreach ( $aivt_engines as $aivt_id => $aivt_def ) {
	foreach ( $aivt_trend as $aivt_r ) {
		if ( isset( $aivt_r['by_engine'][ $aivt_id ] ) ) {
			$aivt_series[ $aivt_id ] = $aivt_def['label'];
			break;
		}
	}
}

// Show the time too when several checks happened on the same day.
$aivt_days   = array_map(
	function ( $run ) {
		return wp_date( 'Y-m-d', strtotime( $run['created_at'] . ' UTC' ) );
	},
	$aivt_trend
);
$aivt_format = count( array_unique( $aivt_days ) ) < count( $aivt_days ) ? 'M j, ' . get_option( 'time_format' ) : 'M j';
$aivt_date   = function ( $run ) use ( $aivt_format ) {
	return wp_date( $aivt_format, strtotime( $run['created_at'] . ' UTC' ) );
};
$aivt_val  = function ( $run, $engine ) {
	if ( ! isset( $run['by_engine'][ $engine ] ) || ! $run['by_engine'][ $engine ]['done'] ) {
		return null;
	}
	$e = $run['by_engine'][ $engine ];
	return (int) round( 100 * $e['points'] / $e['done'] );
};
?>
<h2><?php esc_html_e( 'Visibility over time', 'ai-visibility-tracker' ); ?></h2>
<p class="aivt-muted">
	<?php
	if ( $aivt_n < 2 ) {
		esc_html_e( 'The trend fills in after your second check. Turn on weekly checks in Settings to track progress automatically.', 'ai-visibility-tracker' );
	} else {
		esc_html_e( 'Visibility score per engine, check by check.', 'ai-visibility-tracker' );
	}
	?>
</p>

<?php
if ( $aivt_n < 2 ) {
	return;
}
?>

<ul class="aivt-legend">
	<?php foreach ( $aivt_series as $aivt_id => $aivt_label ) : ?>
		<li class="aivt-engine--<?php echo esc_attr( $aivt_id ); ?>"><span class="aivt-swatch" aria-hidden="true"></span><?php echo esc_html( $aivt_label ); ?></li>
	<?php endforeach; ?>
</ul>

<div class="aivt-chart" data-aivt-chart>
	<svg viewBox="0 0 <?php echo (int) $aivt_w; ?> <?php echo (int) $aivt_h; ?>" role="img" aria-label="<?php esc_attr_e( 'Line chart of the visibility score per AI engine over time. A table version follows.', 'ai-visibility-tracker' ); ?>">
		<?php foreach ( array( 0, 25, 50, 75, 100 ) as $aivt_tick ) : ?>
			<line class="aivt-chart__grid" x1="<?php echo (int) $aivt_pad_l; ?>" x2="<?php echo (int) ( $aivt_w - $aivt_pad_r ); ?>" y1="<?php echo esc_attr( $aivt_y( $aivt_tick ) ); ?>" y2="<?php echo esc_attr( $aivt_y( $aivt_tick ) ); ?>" />
			<text class="aivt-chart__axis" x="<?php echo (int) ( $aivt_pad_l - 8 ); ?>" y="<?php echo esc_attr( $aivt_y( $aivt_tick ) + 4 ); ?>" text-anchor="end"><?php echo (int) $aivt_tick; ?></text>
		<?php endforeach; ?>

		<?php foreach ( $aivt_trend as $aivt_i => $aivt_r ) : ?>
			<?php if ( $aivt_n <= 8 || 0 === $aivt_i % (int) ceil( $aivt_n / 8 ) || $aivt_i === $aivt_n - 1 ) : ?>
				<text class="aivt-chart__axis" x="<?php echo esc_attr( $aivt_x( $aivt_i ) ); ?>" y="<?php echo (int) ( $aivt_h - 8 ); ?>" text-anchor="middle"><?php echo esc_html( $aivt_date( $aivt_r ) ); ?></text>
			<?php endif; ?>
		<?php endforeach; ?>

		<line class="aivt-chart__crosshair" data-aivt-crosshair x1="0" x2="0" y1="<?php echo (int) $aivt_pad_t; ?>" y2="<?php echo (int) ( $aivt_pad_t + $aivt_ph ); ?>" hidden />

		<?php foreach ( $aivt_series as $aivt_id => $aivt_label ) : ?>
			<?php
			$aivt_pts = array();
			foreach ( $aivt_trend as $aivt_i => $aivt_r ) {
				$aivt_v = $aivt_val( $aivt_r, $aivt_id );
				if ( null !== $aivt_v ) {
					$aivt_pts[] = array( $aivt_x( $aivt_i ), $aivt_y( $aivt_v ) );
				}
			}
			$aivt_path = '';
			foreach ( $aivt_pts as $aivt_k => $aivt_p ) {
				$aivt_path .= ( $aivt_k ? 'L' : 'M' ) . round( $aivt_p[0], 1 ) . ' ' . round( $aivt_p[1], 1 );
			}
			?>
			<g class="aivt-engine--<?php echo esc_attr( $aivt_id ); ?>">
				<?php if ( count( $aivt_pts ) > 1 ) : ?>
					<path class="aivt-chart__line" d="<?php echo esc_attr( $aivt_path ); ?>" />
				<?php endif; ?>
				<?php foreach ( $aivt_pts as $aivt_p ) : ?>
					<circle class="aivt-chart__dot" cx="<?php echo esc_attr( round( $aivt_p[0], 1 ) ); ?>" cy="<?php echo esc_attr( round( $aivt_p[1], 1 ) ); ?>" r="4" />
				<?php endforeach; ?>
			</g>
		<?php endforeach; ?>

		<?php foreach ( $aivt_trend as $aivt_i => $aivt_r ) : ?>
			<?php
			$aivt_col  = $aivt_n > 1 ? $aivt_pw / ( $aivt_n - 1 ) : $aivt_pw;
			$aivt_tip  = array();
			foreach ( $aivt_series as $aivt_id => $aivt_label ) {
				$aivt_v = $aivt_val( $aivt_r, $aivt_id );
				$aivt_tip[] = array(
					'engine' => $aivt_id,
					'label'  => $aivt_label,
					'value'  => null === $aivt_v ? '—' : (string) $aivt_v,
				);
			}
			?>
			<rect class="aivt-chart__hit" x="<?php echo esc_attr( max( 0, $aivt_x( $aivt_i ) - $aivt_col / 2 ) ); ?>" y="0" width="<?php echo esc_attr( $aivt_col ); ?>" height="<?php echo (int) $aivt_h; ?>"
				data-x="<?php echo esc_attr( $aivt_x( $aivt_i ) ); ?>"
				data-title="<?php echo esc_attr( $aivt_date( $aivt_r ) ); ?>"
				data-rows="<?php echo esc_attr( wp_json_encode( $aivt_tip ) ); ?>" />
		<?php endforeach; ?>
	</svg>
	<div class="aivt-tooltip" data-aivt-tooltip hidden></div>
</div>

<details class="aivt-tableview">
	<summary><?php esc_html_e( 'View as table', 'ai-visibility-tracker' ); ?></summary>
	<table class="aivt-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Check', 'ai-visibility-tracker' ); ?></th>
				<?php foreach ( $aivt_series as $aivt_label ) : ?>
					<th scope="col" class="num"><?php echo esc_html( $aivt_label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array_reverse( $aivt_trend ) as $aivt_r ) : ?>
				<tr>
					<td><?php echo esc_html( $aivt_date( $aivt_r ) ); ?></td>
					<?php foreach ( $aivt_series as $aivt_id => $aivt_label ) : ?>
						<?php $aivt_v = $aivt_val( $aivt_r, $aivt_id ); ?>
						<td class="num"><?php echo null === $aivt_v ? '—' : (int) $aivt_v; ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</details>
