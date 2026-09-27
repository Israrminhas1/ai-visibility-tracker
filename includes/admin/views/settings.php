<?php
/**
 * Settings view.
 *
 * @package AIVT
 *
 * @var array $settings
 */

use AIVT\Admin\Admin;
use AIVT\Engines;
use AIVT\Settings;

defined( 'ABSPATH' ) || exit;

$aivt_countries = array(
	''   => __( 'Worldwide (no location)', 'ai-visibility-tracker' ),
	'US' => 'United States',
	'GB' => 'United Kingdom',
	'CA' => 'Canada',
	'AU' => 'Australia',
	'IE' => 'Ireland',
	'DE' => 'Germany',
	'FR' => 'France',
	'ES' => 'Spain',
	'IT' => 'Italy',
	'NL' => 'Netherlands',
	'BE' => 'Belgium',
	'AT' => 'Austria',
	'CH' => 'Switzerland',
	'SE' => 'Sweden',
	'DK' => 'Denmark',
	'NO' => 'Norway',
	'FI' => 'Finland',
	'PL' => 'Poland',
	'PT' => 'Portugal',
);
?>

<?php // One form for everything, so saving never drops changes made in another section. ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aivt-settings-form">
<?php Admin::form_fields( 'save_settings' ); ?>

<div class="aivt-card">
	<h2><?php esc_html_e( 'AI engines', 'ai-visibility-tracker' ); ?></h2>
	<p class="aivt-muted"><?php esc_html_e( 'Turn on the engines you want to track and add your own API key for each. Keys are stored encrypted and only sent to that provider. You pay the provider directly for usage.', 'ai-visibility-tracker' ); ?></p>

	<div class="aivt-engines">
		<?php foreach ( Engines::all() as $aivt_id => $aivt_def ) : ?>
			<?php
			$aivt_cfg    = Settings::engine( $aivt_id );
			$aivt_masked = Settings::masked_key( $aivt_id );
			$aivt_field  = 'engines[' . $aivt_id . ']';
			?>
			<fieldset class="aivt-engine aivt-engine--<?php echo esc_attr( $aivt_id ); ?>" data-aivt-engine="<?php echo esc_attr( $aivt_id ); ?>">
				<legend class="screen-reader-text"><?php echo esc_html( $aivt_def['label'] ); ?></legend>
				<div class="aivt-engine__head">
					<span class="aivt-swatch" aria-hidden="true"></span>
					<strong><?php echo esc_html( $aivt_def['label'] ); ?></strong>
					<span class="aivt-muted aivt-small"><?php echo esc_html( $aivt_def['vendor'] ); ?></span>
					<label class="aivt-switch">
						<input type="checkbox" name="<?php echo esc_attr( $aivt_field ); ?>[enabled]" value="1" <?php checked( $aivt_cfg['enabled'] ); ?>>
						<span><?php esc_html_e( 'Track', 'ai-visibility-tracker' ); ?></span>
					</label>
				</div>

				<?php if ( Settings::key_unreadable( $aivt_id ) ) : ?>
					<p class="aivt-warning" role="alert"><?php esc_html_e( 'The saved key can no longer be read, usually because the site moved or its security keys changed. Paste it again.', 'ai-visibility-tracker' ); ?></p>
				<?php endif; ?>

				<label class="aivt-field">
					<span><?php esc_html_e( 'API key', 'ai-visibility-tracker' ); ?></span>
					<input type="password" name="<?php echo esc_attr( $aivt_field ); ?>[key]" autocomplete="off" spellcheck="false" class="regular-text" data-aivt-key
						placeholder="<?php echo $aivt_masked ? esc_attr( $aivt_masked ) : esc_attr__( 'Paste your API key', 'ai-visibility-tracker' ); ?>">
				</label>

				<label class="aivt-field">
					<span><?php esc_html_e( 'Model', 'ai-visibility-tracker' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $aivt_field ); ?>[model]" value="<?php echo esc_attr( $aivt_cfg['model'] ); ?>" list="aivt-models-<?php echo esc_attr( $aivt_id ); ?>" class="regular-text" data-aivt-model>
					<datalist id="aivt-models-<?php echo esc_attr( $aivt_id ); ?>">
						<?php foreach ( $aivt_def['models'] as $aivt_m ) : ?>
							<option value="<?php echo esc_attr( $aivt_m ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<?php if ( 'perplexity' === $aivt_id ) : ?>
						<span class="aivt-small"><?php esc_html_e( 'A Perplexity search preset (fast, low, medium, high) or a model ID.', 'ai-visibility-tracker' ); ?></span>
					<?php endif; ?>
				</label>

				<div class="aivt-engine__foot">
					<button type="button" class="button" data-aivt-test><?php esc_html_e( 'Test connection', 'ai-visibility-tracker' ); ?></button>
					<span class="aivt-status" data-aivt-test-status role="status"></span>
					<a href="<?php echo esc_url( $aivt_def['keys_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="aivt-small">
						<?php
						/* translators: %s: vendor name */
						printf( esc_html__( 'Get your %s API key', 'ai-visibility-tracker' ), esc_html( $aivt_def['vendor'] ) );
						?>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'ai-visibility-tracker' ); ?></span>
					</a>
					<?php if ( $aivt_masked ) : ?>
						<label class="aivt-small aivt-remove-key">
							<input type="checkbox" name="<?php echo esc_attr( $aivt_field ); ?>[remove_key]" value="1">
							<?php esc_html_e( 'Remove saved key', 'ai-visibility-tracker' ); ?>
						</label>
					<?php endif; ?>
				</div>
			</fieldset>
		<?php endforeach; ?>
	</div>
</div>

<div class="aivt-card" id="brand">
	<h2><?php esc_html_e( 'Your brand', 'ai-visibility-tracker' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="aivt-brand"><?php esc_html_e( 'Brand name', 'ai-visibility-tracker' ); ?></label></th>
			<td><input type="text" id="aivt-brand" name="brand_name" value="<?php echo esc_attr( $settings['brand_name'] ); ?>" class="regular-text" required></td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-aliases"><?php esc_html_e( 'Other names', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<input type="text" id="aivt-aliases" name="brand_aliases" value="<?php echo esc_attr( implode( ', ', (array) $settings['brand_aliases'] ) ); ?>" class="large-text" data-aivt-aliases>
				<p class="aivt-inline">
					<button type="button" class="button button-small" data-aivt-suggest-names <?php disabled( ! \AIVT\Engines::active() ); ?>><?php esc_html_e( 'Suggest names with AI', 'ai-visibility-tracker' ); ?></button>
					<span class="aivt-status" data-aivt-names-status role="status"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Comma separated. Short names, product names or old names AI might use, e.g. "Acme, Acme Bakery".', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-domains"><?php esc_html_e( 'Your websites', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<input type="text" id="aivt-domains" name="brand_domains" value="<?php echo esc_attr( implode( ', ', (array) $settings['brand_domains'] ) ); ?>" class="large-text">
				<p class="description"><?php esc_html_e( 'Comma separated domains. A citation of any of these counts as your site being cited.', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-business"><?php esc_html_e( 'What you do', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<textarea id="aivt-business" name="business" rows="3" class="large-text"><?php echo esc_textarea( $settings['business'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One or two sentences about your products or services and who they are for. Used to write better prompts.', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-location"><?php esc_html_e( 'Service area', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<input type="text" id="aivt-location" name="location" value="<?php echo esc_attr( $settings['location'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Austin, Texas — leave empty if online/national', 'ai-visibility-tracker' ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-country"><?php esc_html_e( 'Search from country', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<select id="aivt-country" name="country">
					<?php foreach ( $aivt_countries as $aivt_code => $aivt_name ) : ?>
						<option value="<?php echo esc_attr( $aivt_code ); ?>" <?php selected( $aivt_code, $settings['country'] ); ?>><?php echo esc_html( $aivt_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Engines that support it search as if the user were in this country.', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-language"><?php esc_html_e( 'Prompt language', 'ai-visibility-tracker' ); ?></label></th>
			<td><input type="text" id="aivt-language" name="language" value="<?php echo esc_attr( $settings['language'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Automatic checks', 'ai-visibility-tracker' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Automatic checks', 'ai-visibility-tracker' ); ?></legend>
					<?php
					$aivt_sched = array(
						'off'     => __( 'Off — I run checks myself', 'ai-visibility-tracker' ),
						'weekly'  => __( 'Weekly', 'ai-visibility-tracker' ),
						'monthly' => __( 'Monthly', 'ai-visibility-tracker' ),
					);
					foreach ( $aivt_sched as $aivt_val => $aivt_label ) :
						?>
						<label class="aivt-radio"><input type="radio" name="schedule" value="<?php echo esc_attr( $aivt_val ); ?>" <?php checked( $aivt_val, $settings['schedule'] ); ?>> <?php echo esc_html( $aivt_label ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Scheduled checks use WP-Cron, which runs when your site gets visits. Each check costs the same as a manual one.', 'ai-visibility-tracker' ); ?></p>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-budget"><?php esc_html_e( 'Monthly budget (USD)', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<input type="number" id="aivt-budget" name="monthly_budget" min="0" step="0.5" value="<?php echo esc_attr( (float) $settings['monthly_budget'] > 0 ? $settings['monthly_budget'] : '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'none', 'ai-visibility-tracker' ); ?>">
				<p class="description">
					<?php
					$aivt_repo = new \AIVT\Repository();
					printf(
						/* translators: 1: estimated cost of one check, 2: amount spent this month */
						esc_html__( 'A check that would go over it does not start. One check now costs about %1$s; %2$s spent this month.', 'ai-visibility-tracker' ),
						esc_html( \AIVT\Pricing::format( \AIVT\Runner::estimate() ) ),
						esc_html( \AIVT\Pricing::format( $aivt_repo->spent_since( gmdate( 'Y-m-01 00:00:00' ) ) ) )
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-keep"><?php esc_html_e( 'Keep checks', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<input type="number" id="aivt-keep" name="keep_runs" min="1" max="500" step="1" value="<?php echo esc_attr( (int) $settings['keep_runs'] ); ?>" class="small-text">
				<p class="description"><?php esc_html_e( 'The most recent checks to keep. Older ones are deleted with their answers.', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
	</table>

	<h2 id="tracking"><?php esc_html_e( 'AI traffic', 'ai-visibility-tracker' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Track AI traffic', 'ai-visibility-tracker' ); ?></th>
			<td>
				<label><input type="checkbox" name="track_traffic" value="1" <?php checked( $settings['track_traffic'] ); ?>> <?php esc_html_e( 'Count visitors sent by AI assistants and visits from AI crawlers', 'ai-visibility-tracker' ); ?></label>
				<p class="description"><?php esc_html_e( 'Only daily totals per source and page are stored, with no cookies, IP addresses or personal data. A tiny script (under 1 KB) checks where visitors came from.', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
	</table>

	<h2 id="llms"><?php esc_html_e( 'llms.txt', 'ai-visibility-tracker' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Automatic llms.txt', 'ai-visibility-tracker' ); ?></th>
			<td>
				<?php if ( \AIVT\Llms_Txt::physical_file_exists() ) : ?>
					<p><?php esc_html_e( 'Your site already has its own llms.txt file, so the automatic one is not used.', 'ai-visibility-tracker' ); ?></p>
				<?php else : ?>
					<label><input type="checkbox" name="llms_enabled" value="1" <?php checked( $settings['llms_enabled'] ); ?>> <?php esc_html_e( 'Publish an llms.txt that summarizes your site for AI assistants', 'ai-visibility-tracker' ); ?></label>
					<p class="description">
						<?php esc_html_e( 'Built from your brand details and published pages, products and posts. Updates automatically when you publish.', 'ai-visibility-tracker' ); ?>
						<?php if ( $settings['llms_enabled'] ) : ?>
							<a href="<?php echo esc_url( \AIVT\Llms_Txt::url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View llms.txt', 'ai-visibility-tracker' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aivt-llms-intro"><?php esc_html_e( 'Extra introduction', 'ai-visibility-tracker' ); ?></label></th>
			<td>
				<textarea id="aivt-llms-intro" name="llms_intro" rows="3" class="large-text"><?php echo esc_textarea( $settings['llms_intro'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional. Key facts you want AI to know: what makes you different, opening hours, service area, awards.', 'ai-visibility-tracker' ); ?></p>
			</td>
		</tr>
	</table>
</div>

<p class="aivt-save-bar"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ai-visibility-tracker' ); ?></button></p>
</form>

<section class="aivt-card" id="competitors">
	<h2><?php esc_html_e( 'Competitors', 'ai-visibility-tracker' ); ?></h2>
	<p class="aivt-muted"><?php esc_html_e( 'Brands you compete with. Their websites are labeled "Competitor" in cited sources. You can also track brands straight from the Dashboard.', 'ai-visibility-tracker' ); ?></p>
	<?php if ( $settings['competitors'] ) : ?>
		<ul class="aivt-competitors">
			<?php foreach ( $settings['competitors'] as $aivt_c ) : ?>
				<li>
					<strong><?php echo esc_html( $aivt_c['name'] ); ?></strong>
					<?php if ( $aivt_c['domain'] ) : ?>
						<span class="aivt-muted"><?php echo esc_html( $aivt_c['domain'] ); ?></span>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php Admin::form_fields( 'competitor' ); ?>
						<input type="hidden" name="do" value="remove">
						<input type="hidden" name="name" value="<?php echo esc_attr( $aivt_c['name'] ); ?>">
						<button type="submit" class="button-link aivt-danger">
							<?php esc_html_e( 'Remove', 'ai-visibility-tracker' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( $aivt_c['name'] ); ?></span>
						</button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aivt-inline">
		<?php Admin::form_fields( 'competitor' ); ?>
		<input type="hidden" name="do" value="add">
		<label class="screen-reader-text" for="aivt-comp-name"><?php esc_html_e( 'Competitor name', 'ai-visibility-tracker' ); ?></label>
		<input type="text" id="aivt-comp-name" name="name" placeholder="<?php esc_attr_e( 'Name', 'ai-visibility-tracker' ); ?>" required>
		<label class="screen-reader-text" for="aivt-comp-domain"><?php esc_html_e( 'Competitor website', 'ai-visibility-tracker' ); ?></label>
		<input type="text" id="aivt-comp-domain" name="domain" placeholder="<?php esc_attr_e( 'website.com (optional)', 'ai-visibility-tracker' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Add competitor', 'ai-visibility-tracker' ); ?></button>
	</form>
</section>

<section class="aivt-card aivt-about">
	<h2><?php esc_html_e( 'Privacy', 'ai-visibility-tracker' ); ?></h2>
	<p class="aivt-muted"><?php esc_html_e( 'This plugin only contacts an AI provider when you test a key, generate prompts, or run a check (manually or on your schedule). It sends your prompts, and when generating prompts a summary of your public site content, to the providers you enable. No data is sent to the plugin author.', 'ai-visibility-tracker' ); ?></p>
	<p class="aivt-small aivt-muted">
		<?php
		/* translators: %s: author name */
		printf( esc_html__( 'Built by %s.', 'ai-visibility-tracker' ), 'Israr Minhas' );
		?>
	</p>
</section>
