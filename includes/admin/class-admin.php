<?php
/**
 * Admin screens.
 *
 * @package AIVT
 */

namespace AIVT\Admin;

use AIVT\Analyzer;
use AIVT\Engines;
use AIVT\Llms_Txt;
use AIVT\Readiness;
use AIVT\Repository;
use AIVT\Rest;
use AIVT\Settings;
use AIVT\Traffic;

defined( 'ABSPATH' ) || exit;

/**
 * Menus, pages, form handlers and view helpers.
 */
class Admin {

	const CAP = 'manage_options';

	/**
	 * Page slugs => titles.
	 *
	 * @return array
	 */
	private function pages() {
		return array(
			'aivt'          => __( 'Dashboard', 'ai-visibility-tracker' ),
			'aivt-prompts'  => __( 'Prompts', 'ai-visibility-tracker' ),
			'aivt-results'       => __( 'Results', 'ai-visibility-tracker' ),
			'aivt-opportunities' => __( 'Opportunities', 'ai-visibility-tracker' ),
			'aivt-traffic'       => __( 'AI Traffic', 'ai-visibility-tracker' ),
			'aivt-readiness'     => __( 'AI Readiness', 'ai-visibility-tracker' ),
			'aivt-settings'      => __( 'Settings', 'ai-visibility-tracker' ),
		);
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AIVT_FILE ), array( $this, 'action_links' ) );

		foreach ( array( 'save_settings', 'save_engines', 'add_prompts', 'prompt_action', 'competitor', 'audit', 'export', 'delete_run' ) as $action ) {
			add_action( 'admin_post_aivt_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Admin menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'AI Visibility', 'ai-visibility-tracker' ),
			__( 'AI Visibility', 'ai-visibility-tracker' ),
			self::CAP,
			'aivt',
			array( $this, 'render' ),
			'dashicons-visibility',
			58
		);
		foreach ( $this->pages() as $slug => $title ) {
			add_submenu_page( 'aivt', $title, $title, self::CAP, $slug, array( $this, 'render' ) );
		}
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'aivt-settings' ) ) . '">' . esc_html__( 'Settings', 'ai-visibility-tracker' ) . '</a>' );
		return $links;
	}

	/**
	 * Enqueue CSS/JS on plugin pages only.
	 *
	 * @param string $hook Current screen hook.
	 */
	public function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $this->pages()[ $page ] ) ) {
			return;
		}
		wp_enqueue_style( 'aivt-admin', AIVT_URL . 'assets/admin.css', array(), AIVT_VERSION );
		wp_enqueue_script( 'aivt-admin', AIVT_URL . 'assets/admin.js', array(), AIVT_VERSION, true );

		$active = ( new Repository() )->active_run();
		wp_localize_script(
			'aivt-admin',
			'AIVT',
			array(
				'rest'      => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'activeRun' => $active ? (int) $active['id'] : 0,
				'i18n'      => array(
					'testing'      => __( 'Testing…', 'ai-visibility-tracker' ),
					'generating'   => __( 'Generating prompts… this can take up to a minute.', 'ai-visibility-tracker' ),
					'starting'     => __( 'Starting…', 'ai-visibility-tracker' ),
					/* translators: 1: answers checked so far, 2: answers in the run */
					'progress'     => __( '%1$d of %2$d answers checked', 'ai-visibility-tracker' ),
					'done'         => __( 'Run complete. Refreshing…', 'ai-visibility-tracker' ),
					'error'        => __( 'Something went wrong.', 'ai-visibility-tracker' ),
					/* translators: %s: estimated cost, e.g. $0.42 */
					'confirmRun'   => __( 'Run a check now? It will cost about %s on your AI accounts.', 'ai-visibility-tracker' ),
					'sessionExpired'        => __( 'Your login has expired. Reload the page to continue; the check carries on in the background.', 'ai-visibility-tracker' ),
					'continuesInBackground' => __( 'The check carries on in the background.', 'ai-visibility-tracker' ),
					/* translators: %d: number of prompts */
					'generated'    => __( 'New prompts added: %d. Refreshing…', 'ai-visibility-tracker' ),
					'confirmStop'  => __( 'Stop this run? Answers already checked are kept.', 'ai-visibility-tracker' ),
					'confirmDel'   => __( 'Delete this prompt?', 'ai-visibility-tracker' ),
					'loading'      => __( 'Loading…', 'ai-visibility-tracker' ),
					'mentioned'    => __( 'Mentioned', 'ai-visibility-tracker' ),
					'notMentioned' => __( 'Not mentioned', 'ai-visibility-tracker' ),
					/* translators: %d: rank among the brands in the answer */
					'position'     => __( 'Position #%d', 'ai-visibility-tracker' ),
					'cited'        => __( 'Your site is cited', 'ai-visibility-tracker' ),
					'sources'      => __( 'Sources cited', 'ai-visibility-tracker' ),
					'noSources'    => __( 'This answer cited no sources.', 'ai-visibility-tracker' ),
					'brands'       => __( 'Brands in this answer', 'ai-visibility-tracker' ),
					'noBrands'     => __( 'No brands detected.', 'ai-visibility-tracker' ),
					'you'          => __( 'You', 'ai-visibility-tracker' ),
					'competitor'   => __( 'Competitor', 'ai-visibility-tracker' ),
					'source'       => __( 'Source', 'ai-visibility-tracker' ),
					'social'       => __( 'Social', 'ai-visibility-tracker' ),
					'editPage'     => __( 'Edit page', 'ai-visibility-tracker' ),
					'suggesting'   => __( 'Asking AI for other names…', 'ai-visibility-tracker' ),
					'noNames'      => __( 'No new names suggested.', 'ai-visibility-tracker' ),
					/* translators: %s: comma-separated names */
					'namesAdded'   => __( 'Added: %s. Review them, then save.', 'ai-visibility-tracker' ),
				),
			)
		);
	}

	/**
	 * Render the current page.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'aivt'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pages = $this->pages();
		if ( ! isset( $pages[ $page ] ) ) {
			$page = 'aivt';
		}
		$view = 'aivt' === $page ? 'dashboard' : substr( $page, 5 );

		$repo     = new Repository();
		$settings = Settings::all();

		echo '<div class="wrap aivt">';
		$this->header( $page, $pages );
		$this->notice();
		include AIVT_DIR . 'includes/admin/views/' . $view . '.php';
		echo '</div>';
	}

	/**
	 * Page header with tabs.
	 *
	 * @param string $current Current slug.
	 * @param array  $pages   Pages.
	 */
	private function header( $current, array $pages ) {
		echo '<div class="aivt-header"><h1 class="aivt-title"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>' . esc_html__( 'AI Visibility Tracker', 'ai-visibility-tracker' ) . '</h1>';
		echo '<nav class="aivt-tabs" aria-label="' . esc_attr__( 'AI Visibility sections', 'ai-visibility-tracker' ) . '">';
		foreach ( $pages as $slug => $title ) {
			printf(
				'<a href="%s" class="aivt-tab%s"%s>%s</a>',
				esc_url( self::url( $slug ) ),
				$slug === $current ? ' is-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $title )
			);
		}
		echo '</nav></div>';
	}

	/* ---------------------------------------------------------------------
	 * Form handlers (admin-post.php)
	 * ------------------------------------------------------------------ */

	/**
	 * Verify capability and nonce for a form action.
	 *
	 * @param string $action Action name.
	 */
	private function verify( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ai-visibility-tracker' ), 403 );
		}
		check_admin_referer( 'aivt_' . $action );
	}

	/**
	 * Link that runs an admin-post action (with its nonce).
	 *
	 * @param string $action Action without the aivt_ prefix.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	public static function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'aivt_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'aivt_' . $action );
	}

	/**
	 * Download a CSV: the answers of a check, the prompts, or AI traffic.
	 */
	public function handle_export() {
		$this->verify( 'export' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$what = isset( $_GET['what'] ) ? sanitize_key( $_GET['what'] ) : '';
		$run  = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0;
		// phpcs:enable
		$repo = new Repository();
		$rows = array();

		if ( 'results' === $what && $run ) {
			$rows[] = array( 'prompt', 'topic', 'engine', 'model', 'status', 'brand_mentioned', 'brand_cited', 'position', 'cost_usd', 'error', 'answered_at_utc' );
			foreach ( $repo->results( $run ) as $r ) {
				$rows[] = array( $r['prompt'], $r['topic'], Engines::label( $r['engine'] ), $r['model'], $r['status'], $r['brand_mentioned'] ? 'yes' : 'no', $r['brand_cited'] ? 'yes' : 'no', $r['brand_position'], $r['cost'], $r['error'], $r['completed_at'] );
			}
		} elseif ( 'prompts' === $what ) {
			$rows[] = array( 'prompt', 'topic', 'intent', 'source', 'active', 'created_at_utc' );
			foreach ( $repo->prompts() as $p ) {
				$rows[] = array( $p['prompt'], $p['topic'], $p['intent'], $p['source'], $p['active'] ? 'yes' : 'no', $p['created_at'] );
			}
		} elseif ( 'traffic' === $what ) {
			$rows[] = array( 'day', 'kind', 'source', 'path', 'hits' );
			foreach ( $repo->traffic_rows( gmdate( 'Y-m-d', strtotime( '-400 days' ) ) ) as $t ) {
				$rows[] = array( $t['day'], $t['kind'], 'referral' === $t['kind'] ? Traffic::source_label( $t['source'] ) : $t['source'], $t['path'], $t['hits'] );
			}
		} else {
			wp_die( esc_html__( 'Nothing to export.', 'ai-visibility-tracker' ), 400 );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ai-visibility-' . $what . ( $run ? '-' . $run : '' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * A CSV cell that spreadsheets won't run as a formula.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		return '' !== $value && false !== strpos( '=+-@', $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Delete one check and its answers.
	 */
	public function handle_delete_run() {
		$this->verify( 'delete_run' );
		$run  = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$repo = new Repository();
		if ( $run && $repo->run( $run ) ) {
			$repo->cancel_run( $run );
			$repo->delete_run( $run );
		}
		$this->redirect( 'aivt-results', __( 'Check deleted.', 'ai-visibility-tracker' ) );
	}

	/**
	 * Save brand, business and schedule settings.
	 */
	public function handle_save_settings() {
		$this->verify( 'save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = wp_unslash( $_POST );

		$domains = array();
		foreach ( self::split_list( isset( $post['brand_domains'] ) ? $post['brand_domains'] : '' ) as $d ) {
			$d = Analyzer::normalize_domain( $d );
			if ( $d ) {
				$domains[] = $d;
			}
		}

		$schedule = isset( $post['schedule'] ) ? sanitize_key( $post['schedule'] ) : 'off';
		$country  = isset( $post['country'] ) ? strtoupper( sanitize_text_field( $post['country'] ) ) : '';

		Settings::update(
			array(
				'brand_name'    => isset( $post['brand_name'] ) ? sanitize_text_field( $post['brand_name'] ) : '',
				'brand_aliases' => array_map( 'sanitize_text_field', self::split_list( isset( $post['brand_aliases'] ) ? $post['brand_aliases'] : '' ) ),
				'brand_domains' => array_values( array_unique( $domains ) ),
				'business'      => isset( $post['business'] ) ? sanitize_textarea_field( $post['business'] ) : '',
				'location'      => isset( $post['location'] ) ? sanitize_text_field( $post['location'] ) : '',
				'country'       => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
				'language'      => isset( $post['language'] ) ? sanitize_text_field( $post['language'] ) : 'English',
				'schedule'      => in_array( $schedule, array( 'off', 'weekly', 'monthly' ), true ) ? $schedule : 'off',
				'track_traffic' => ! empty( $post['track_traffic'] ),
				'llms_enabled'  => ! empty( $post['llms_enabled'] ),
				'llms_intro'    => isset( $post['llms_intro'] ) ? sanitize_textarea_field( $post['llms_intro'] ) : '',
				'monthly_budget' => isset( $post['monthly_budget'] ) && '' !== $post['monthly_budget'] ? max( 0, round( (float) $post['monthly_budget'], 2 ) ) : 0,
				'keep_runs'      => isset( $post['keep_runs'] ) ? min( 500, max( 1, absint( $post['keep_runs'] ) ) ) : 52,
			)
		);
		if ( isset( $post['engines'] ) && is_array( $post['engines'] ) ) {
			$this->save_engines( $post['engines'] );
		}
		// phpcs:enable
		\AIVT\Runner::sync_schedule();
		Llms_Txt::flush();
		Readiness::flush();
		$this->redirect( 'aivt-settings', __( 'Settings saved.', 'ai-visibility-tracker' ) );
	}

	/**
	 * Save engine keys, models and enabled flags.
	 */
	public function handle_save_engines() {
		$this->verify( 'save_engines' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = isset( $_POST['engines'] ) && is_array( $_POST['engines'] ) ? wp_unslash( $_POST['engines'] ) : array();
		$this->save_engines( $input );
		$this->redirect( 'aivt-settings', __( 'AI engines saved.', 'ai-visibility-tracker' ) );
	}

	/**
	 * Save engine keys, models and enabled flags from the settings form.
	 *
	 * @param array $input Unslashed engines input.
	 */
	private function save_engines( array $input ) {
		foreach ( array_keys( Engines::all() ) as $id ) {
			$e      = isset( $input[ $id ] ) ? (array) $input[ $id ] : array();
			$key    = isset( $e['key'] ) ? Rest::sanitize_key_input( $e['key'] ) : '';
			$remove = ! empty( $e['remove_key'] );
			Settings::set_engine(
				$id,
				! empty( $e['enabled'] ),
				$remove ? '' : ( '' === $key ? null : $key ),
				isset( $e['model'] ) ? sanitize_text_field( $e['model'] ) : ''
			);
		}
	}

	/**
	 * Add one or more prompts (one per line).
	 */
	public function handle_add_prompts() {
		$this->verify( 'add_prompts' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$raw   = isset( $_POST['prompts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompts'] ) ) : '';
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		// phpcs:enable
		$repo  = new Repository();
		$added = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( $repo->add_prompt( $line, '', 'manual', $topic ) ) {
				++$added;
			}
		}
		/* translators: %d: number of prompts */
		$this->redirect( 'aivt-prompts', sprintf( _n( '%d prompt added.', '%d prompts added.', $added, 'ai-visibility-tracker' ), $added ) );
	}

	/**
	 * Edit, toggle or delete a prompt.
	 */
	public function handle_prompt_action() {
		$this->verify( 'prompt_action' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do   = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$repo = new Repository();
		$row  = $repo->prompt( $id );
		if ( $row ) {
			if ( 'delete' === $do ) {
				$repo->delete_prompt( $id );
			} elseif ( 'toggle' === $do ) {
				$repo->update_prompt( $id, array( 'active' => ! $row['active'] ) );
			} elseif ( 'edit' === $do && isset( $_POST['prompt'] ) ) {
				$repo->update_prompt(
					$id,
					array(
						'prompt' => sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ),
						'topic'  => isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : $row['topic'],
					)
				);
			}
		}
		// phpcs:enable
		$this->redirect( 'aivt-prompts', __( 'Prompt updated.', 'ai-visibility-tracker' ) );
	}

	/**
	 * Add or remove a tracked competitor.
	 */
	public function handle_competitor() {
		$this->verify( 'competitor' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$do          = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : 'add';
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$domain      = isset( $_POST['domain'] ) ? Analyzer::normalize_domain( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) : '';
		$back        = isset( $_POST['back'] ) ? sanitize_key( $_POST['back'] ) : 'aivt-settings';
		// phpcs:enable
		$competitors = (array) Settings::get( 'competitors' );
		$key         = Analyzer::normalize_name( $name );

		$competitors = array_values(
			array_filter(
				$competitors,
				function ( $c ) use ( $key ) {
					return Analyzer::normalize_name( $c['name'] ) !== $key;
				}
			)
		);
		if ( 'add' === $do && '' !== $key ) {
			$competitors[] = array(
				'name'   => $name,
				'domain' => $domain,
			);
		}
		Settings::update( array( 'competitors' => $competitors ) );
		$this->redirect( isset( $this->pages()[ $back ] ) ? $back : 'aivt-settings', 'add' === $do ? __( 'Competitor added.', 'ai-visibility-tracker' ) : __( 'Competitor removed.', 'ai-visibility-tracker' ) );
	}

	/**
	 * Re-run the readiness audit, optionally for another page on the site.
	 */
	public function handle_audit() {
		$this->verify( 'audit' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$args = array();
		if ( $url ) {
			if ( self::is_site_url( $url ) ) {
				$args['url'] = self::site_url_for( $url );
			} else {
				$this->redirect( 'aivt-readiness', __( 'Please enter a page on this website.', 'ai-visibility-tracker' ), array(), 'error' );
			}
		}
		Readiness::flush();
		$this->redirect( 'aivt-readiness', __( 'Checks refreshed.', 'ai-visibility-tracker' ), $args );
	}

	/**
	 * Whether a URL is on this site's host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_site_url( $url ) {
		return '' !== self::site_url_for( $url );
	}

	/**
	 * The same page on this site's own address: the path and query of $url on
	 * home_url()'s scheme, host and port. Only pages of this site can be
	 * audited, whatever port or scheme the given URL names. '' when $url is
	 * not on this site's domain.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function site_url_for( $url ) {
		if ( Analyzer::normalize_domain( $url ) !== Analyzer::normalize_domain( home_url() ) ) {
			return '';
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		// home_url() may include a subdirectory; don't repeat it.
		if ( '' !== trim( $home, '/' ) && 0 === strpos( $path, rtrim( $home, '/' ) ) ) {
			$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
		}
		return home_url( '/' . ltrim( $path, '/' ) ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Redirect back with a one-time notice.
	 *
	 * @param string $page    Page slug.
	 * @param string $message Notice.
	 * @param array  $args    Extra query args.
	 * @param string $type    success, warning or error.
	 */
	private function redirect( $page, $message, array $args = array(), $type = 'success' ) {
		set_transient(
			'aivt_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	/**
	 * Print and clear the one-time notice.
	 */
	private function notice() {
		$key = 'aivt_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		$message = is_array( $notice ) ? (string) $notice['message'] : (string) $notice;
		$type    = is_array( $notice ) && in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * View helpers
	 * ------------------------------------------------------------------ */

	/**
	 * URL of a plugin page.
	 *
	 * @param string $page Slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Hidden fields for an admin-post form.
	 *
	 * @param string $action Action without the aivt_ prefix.
	 */
	public static function form_fields( $action ) {
		echo '<input type="hidden" name="action" value="aivt_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'aivt_' . $action );
	}

	/**
	 * Split a comma/newline separated list.
	 *
	 * @param string $value Raw value.
	 * @return string[]
	 */
	public static function split_list( $value ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[,\n\r]+/', (string) $value ) ) ) );
	}

	/**
	 * Domains of tracked competitors.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function competitor_domains( array $settings ) {
		return array_values( array_filter( wp_list_pluck( (array) $settings['competitors'], 'domain' ) ) );
	}

	/**
	 * Whether a brand name is a tracked competitor.
	 *
	 * @param string $name     Brand name.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	public static function is_competitor( $name, array $settings ) {
		$key = Analyzer::normalize_name( $name );
		foreach ( (array) $settings['competitors'] as $c ) {
			if ( Analyzer::normalize_name( $c['name'] ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classify a cited domain: brand (you), competitor, or source.
	 *
	 * @param string $domain   Domain.
	 * @param array  $settings Settings.
	 * @return string brand|competitor|social|source
	 */
	public static function domain_type( $domain, array $settings ) {
		return Analyzer::source_type( $domain, $settings );
	}

	/**
	 * Badge HTML for a domain type.
	 *
	 * @param string $type brand|competitor|social|source.
	 * @return string
	 */
	public static function type_badge( $type ) {
		$labels = array(
			'brand'      => __( 'You', 'ai-visibility-tracker' ),
			'competitor' => __( 'Competitor', 'ai-visibility-tracker' ),
			'social'     => __( 'Social', 'ai-visibility-tracker' ),
			'source'     => __( 'Source', 'ai-visibility-tracker' ),
		);
		return '<span class="aivt-badge aivt-badge--' . esc_attr( $type ) . '">' . esc_html( $labels[ $type ] ) . '</span>';
	}

	/**
	 * Engine tag HTML (colored swatch + name).
	 *
	 * @param string $engine Engine ID.
	 * @return string
	 */
	public static function engine_tag( $engine ) {
		return '<span class="aivt-engine-tag aivt-engine--' . esc_attr( $engine ) . '"><span class="aivt-swatch" aria-hidden="true"></span>' . esc_html( Engines::label( $engine ) ) . '</span>';
	}

	/**
	 * Title (and edit link) for a post, or the URL path when unknown.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     URL or path.
	 * @return string HTML.
	 */
	public static function page_link( $post_id, $url ) {
		if ( $post_id && get_post( $post_id ) ) {
			$title = get_the_title( $post_id );
			$title = '' === $title ? __( '(no title)', 'ai-visibility-tracker' ) : $title;
			$edit  = get_edit_post_link( $post_id );
			return '<a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>'
				. ( $edit ? ' <a class="aivt-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'ai-visibility-tracker' ) . '</a>' : '' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === trim( $path, '/' ) || untrailingslashit( $path ) === untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ) {
			return '<a href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Homepage', 'ai-visibility-tracker' ) . '</a>';
		}
		return '<code>' . esc_html( $url ) . '</code>';
	}

	/**
	 * Percentage, or an em dash when there is no data.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return string
	 */
	public static function pct( $part, $whole ) {
		return $whole > 0 ? round( 100 * $part / $whole ) . '%' : '—';
	}

	/**
	 * Convert an AI answer (light Markdown) to safe HTML, highlighting the brand.
	 *
	 * @param string $text Answer.
	 * @return string
	 */
	public static function format_answer( $text ) {
		$settings = Settings::all();
		$links    = array();

		// Pull out Markdown links first so highlighting can't break URLs.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
			function ( $m ) use ( &$links ) {
				$links[] = '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $m[1] ) . '</a>';
				return "\x01" . ( count( $links ) - 1 ) . "\x02";
			},
			$text
		);

		$html = esc_html( $text );

		$terms = array_filter( array_merge( Analyzer::brand_names( $settings ), (array) $settings['brand_domains'] ) );
		if ( $terms ) {
			$alts = implode( '|', array_map( 'preg_quote', array_map( 'esc_html', $terms ), array_fill( 0, count( $terms ), '/' ) ) );
			$html = preg_replace( '/(?<![\p{L}\p{N}])(' . $alts . ')(?![\p{L}\p{N}])/iu', '<mark>$1</mark>', $html );
		}

		$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );

		$out     = '';
		$in_list = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $html ) as $line ) {
			$trim = trim( $line );
			if ( preg_match( '/^(?:[-*•]|\d+[.)])\s+(.*)$/', $trim, $m ) ) {
				if ( ! $in_list ) {
					$out    .= '<ul>';
					$in_list = true;
				}
				$out .= '<li>' . $m[1] . '</li>';
				continue;
			}
			if ( $in_list ) {
				$out    .= '</ul>';
				$in_list = false;
			}
			if ( '' === $trim ) {
				continue;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $trim, $m ) ) {
				$out .= '<h4>' . $m[1] . '</h4>';
			} elseif ( preg_match( '/^(-{3,}|\|[-:| ]+\|)$/', $trim ) ) {
				continue;
			} else {
				$out .= '<p>' . $trim . '</p>';
			}
		}
		if ( $in_list ) {
			$out .= '</ul>';
		}

		return preg_replace_callback(
			"/\x01(\d+)\x02/",
			function ( $m ) use ( $links ) {
				return isset( $links[ (int) $m[1] ] ) ? $links[ (int) $m[1] ] : '';
			},
			$out
		);
	}
}
