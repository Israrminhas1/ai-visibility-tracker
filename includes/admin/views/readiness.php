<?php
/**
 * AI readiness audit.
 *
 * @package AIVT
 *
 * @var array $settings
 */

use AIVT\Admin\Admin;
use AIVT\Readiness;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the URL is validated below.
$aivt_url = isset( $_GET['url'] ) ? Admin::site_url_for( esc_url_raw( wp_unslash( $_GET['url'] ) ) ) : '';
$aivt_report = ( new Readiness() )->audit( $aivt_url );
$aivt_pct    = $aivt_report['total'] ? (int) round( 100 * $aivt_report['passed'] / $aivt_report['total'] ) : 0;

$aivt_status_labels = array(
	'pass' => __( 'Passed', 'ai-visibility-tracker' ),
	'warn' => __( 'Improve', 'ai-visibility-tracker' ),
	'fail' => __( 'Problem', 'ai-visibility-tracker' ),
	'info' => __( 'Info', 'ai-visibility-tracker' ),
);
$aivt_status_icons  = array(
	'pass' => 'yes-alt',
	'warn' => 'warning',
	'fail' => 'dismiss',
	'info' => 'info-outline',
);
$aivt_purpose       = array(
	'search'   => __( 'Shows you in AI answers', 'ai-visibility-tracker' ),
	'user'     => __( 'Reads pages a user asks about', 'ai-visibility-tracker' ),
	'training' => __( 'Trains future AI models', 'ai-visibility-tracker' ),
);

$aivt_render_checks = function ( array $checks ) use ( $aivt_status_labels, $aivt_status_icons ) {
	echo '<ul class="aivt-checks">';
	foreach ( $checks as $c ) {
		echo '<li class="aivt-check aivt-check--' . esc_attr( $c['status'] ) . '">';
		echo '<span class="aivt-check__icon dashicons dashicons-' . esc_attr( $aivt_status_icons[ $c['status'] ] ) . '" aria-hidden="true"></span>';
		echo '<div class="aivt-check__body"><div class="aivt-check__title"><strong>' . esc_html( $c['label'] ) . '</strong> <span class="aivt-check__status">' . esc_html( $aivt_status_labels[ $c['status'] ] ) . '</span></div>';
		echo '<p class="aivt-check__detail">' . esc_html( $c['detail'] ) . '</p>';
		if ( $c['fix'] ) {
			echo '<p class="aivt-check__fix"><strong>' . esc_html__( 'How to fix:', 'ai-visibility-tracker' ) . '</strong> ' . esc_html( $c['fix'] );
			if ( $c['link'] ) {
				echo ' <a href="' . esc_url( $c['link'] ) . '">' . esc_html__( 'Open', 'ai-visibility-tracker' ) . '</a>';
			}
			echo '</p>';
		} elseif ( $c['link'] ) {
			echo '<p class="aivt-check__fix"><a href="' . esc_url( $c['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $c['link'] ) . '</a></p>';
		}
		echo '</div></li>';
	}
	echo '</ul>';
};
?>

<section class="aivt-card aivt-readiness-head">
	<div class="aivt-score-ring" style="--aivt-pct: <?php echo (int) $aivt_pct; ?>" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: passed, 2: total */ __( '%1$d of %2$d checks passed', 'ai-visibility-tracker' ), $aivt_report['passed'], $aivt_report['total'] ) ); ?>">
		<span><?php echo (int) $aivt_report['passed']; ?><small>/<?php echo (int) $aivt_report['total']; ?></small></span>
	</div>
	<div class="aivt-readiness-head__text">
		<h2><?php esc_html_e( 'Can AI find, read and understand your site?', 'ai-visibility-tracker' ); ?></h2>
		<p class="aivt-muted">
			<?php
			/* translators: 1: passed checks, 2: total checks, 3: time ago */
			printf( esc_html__( '%1$d of %2$d checks passed. Checked %3$s ago.', 'ai-visibility-tracker' ), (int) $aivt_report['passed'], (int) $aivt_report['total'], esc_html( human_time_diff( $aivt_report['time'] ) ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aivt-inline">
			<?php Admin::form_fields( 'audit' ); ?>
			<label for="aivt-audit-url" class="screen-reader-text"><?php esc_html_e( 'Page to check', 'ai-visibility-tracker' ); ?></label>
			<input type="url" id="aivt-audit-url" name="url" class="regular-text" value="<?php echo esc_attr( $aivt_report['url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Check again', 'ai-visibility-tracker' ); ?></button>
		</form>
		<p class="aivt-small aivt-muted"><?php esc_html_e( 'Enter any page on your site to check it, e.g. a product or service page.', 'ai-visibility-tracker' ); ?></p>
	</div>
</section>

<div class="aivt-grid aivt-grid--wide-left">
	<section class="aivt-card">
		<h2><?php esc_html_e( 'Site', 'ai-visibility-tracker' ); ?></h2>
		<?php $aivt_render_checks( $aivt_report['site'] ); ?>
	</section>

	<section class="aivt-card">
		<h2>
			<?php
			/* translators: %s: page URL */
			printf( esc_html__( 'Page: %s', 'ai-visibility-tracker' ), esc_html( untrailingslashit( preg_replace( '#^https?://#', '', $aivt_report['url'] ) ) ) );
			?>
		</h2>
		<?php if ( ! $aivt_report['page']['fetched'] ) : ?>
			<p class="aivt-notice-inline"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html( $aivt_report['page']['error'] ); ?></p>
		<?php else : ?>
			<?php $aivt_render_checks( $aivt_report['page']['checks'] ); ?>
		<?php endif; ?>
	</section>
</div>

<section class="aivt-card" id="robots">
	<h2><?php esc_html_e( 'AI crawlers and your robots.txt', 'ai-visibility-tracker' ); ?></h2>
	<p class="aivt-muted">
		<?php esc_html_e( 'Blocking search crawlers keeps you out of AI answers. Blocking training crawlers is your choice: it doesn\'t stop AI from finding and citing you.', 'ai-visibility-tracker' ); ?>
		<?php
		$aivt_src = array(
			'file'    => __( 'Read from the robots.txt file in your site root.', 'ai-visibility-tracker' ),
			'http'    => __( 'Read from your live robots.txt.', 'ai-visibility-tracker' ),
			'virtual' => __( 'Built the way WordPress generates it (your site could not load its own robots.txt).', 'ai-visibility-tracker' ),
		);
		echo esc_html( $aivt_src[ $aivt_report['robots']['source'] ] );
		?>
	</p>
	<table class="aivt-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Crawler', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Used for', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Access', 'ai-visibility-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Rule that decides it', 'ai-visibility-tracker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $aivt_report['bots'] as $aivt_b ) : ?>
				<?php $aivt_bad = ! $aivt_b['allowed'] && 'training' !== $aivt_b['purpose']; ?>
				<tr>
					<td><strong><?php echo esc_html( $aivt_b['token'] ); ?></strong> <span class="aivt-muted aivt-small"><?php echo esc_html( $aivt_b['label'] ); ?></span></td>
					<td class="aivt-small"><?php echo esc_html( $aivt_purpose[ $aivt_b['purpose'] ] ); ?></td>
					<td>
						<?php if ( $aivt_b['allowed'] ) : ?>
							<span class="aivt-status-pill is-yes"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Allowed', 'ai-visibility-tracker' ); ?></span>
						<?php else : ?>
							<span class="aivt-status-pill <?php echo $aivt_bad ? 'is-error' : 'is-no'; ?>"><span class="dashicons dashicons-<?php echo $aivt_bad ? 'warning' : 'lock'; ?>" aria-hidden="true"></span><?php esc_html_e( 'Blocked', 'ai-visibility-tracker' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo $aivt_b['rule'] ? '<code>' . esc_html( $aivt_b['rule'] ) . '</code>' : '<span class="aivt-muted">' . esc_html__( 'No rule, allowed by default', 'ai-visibility-tracker' ) . '</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<details class="aivt-tableview">
		<summary><?php esc_html_e( 'Show robots.txt', 'ai-visibility-tracker' ); ?></summary>
		<pre class="aivt-code"><?php echo esc_html( $aivt_report['robots']['content'] ); ?></pre>
	</details>
</section>
