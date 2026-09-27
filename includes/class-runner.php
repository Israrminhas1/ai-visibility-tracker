<?php
/**
 * Runs prompts against engines in small background steps.
 *
 * @package AIVT
 */

namespace AIVT;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Each step answers one prompt on one engine, so no single PHP request runs
 * too long. Steps are driven by the admin page while it is open, and by
 * WP-Cron otherwise.
 */
class Runner {

	const CRON_PROCESS   = 'aivt_process_queue';
	const CRON_SCHEDULED = 'aivt_scheduled_run';

	/**
	 * How long one cron request may keep processing, in seconds.
	 */
	const CRON_BUDGET = 60;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_PROCESS, array( $this, 'process_cron' ) );
		add_action( self::CRON_SCHEDULED, array( $this, 'scheduled_run' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * Add a monthly interval (WordPress has weekly built in).
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['aivt_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month', 'ai-visibility-tracker' ),
		);
		return $schedules;
	}

	/**
	 * Keep the recurring event in line with the schedule setting.
	 */
	public static function sync_schedule() {
		$map        = array(
			'weekly'  => 'weekly',
			'monthly' => 'aivt_monthly',
		);
		$setting    = Settings::get( 'schedule' );
		$want       = isset( $map[ $setting ] ) ? $map[ $setting ] : '';
		$event      = wp_get_scheduled_event( self::CRON_SCHEDULED );
		$current    = $event ? $event->schedule : '';

		if ( $current === $want ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_SCHEDULED );
		if ( $want ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $want, self::CRON_SCHEDULED );
		}
	}

	/**
	 * Start a new run over all active prompts and engines.
	 *
	 * @param string $trigger 'manual' or 'scheduled'.
	 * @return array|WP_Error The run.
	 */
	public function start( $trigger = 'manual' ) {
		$repo = new Repository();

		$active = $repo->active_run();
		if ( $active ) {
			return new WP_Error( 'aivt_running', __( 'A run is already in progress.', 'ai-visibility-tracker' ), array( 'run' => $active ) );
		}
		$engines = Engines::active();
		if ( ! $engines ) {
			return new WP_Error( 'aivt_no_engine', __( 'Connect and enable at least one AI engine in Settings first.', 'ai-visibility-tracker' ) );
		}
		$prompts = $repo->prompts( true );
		if ( ! $prompts ) {
			return new WP_Error( 'aivt_no_prompts', __( 'Add or generate some prompts first.', 'ai-visibility-tracker' ) );
		}

		$run_id = $repo->create_run( $trigger, $engines, $prompts );
		$this->schedule_processing( 5 );
		return $repo->run( $run_id );
	}

	/**
	 * Process one item of a run.
	 *
	 * @param int $run_id Run ID.
	 * @return array Updated run.
	 */
	public function step( $run_id ) {
		$repo   = new Repository();
		$result = $repo->claim_next( $run_id );

		if ( $result ) {
			$this->process( $repo, $result );
		}

		$run = $repo->refresh_run( $run_id );
		if ( $run && 'completed' === $run['status'] ) {
			$repo->prune();
		}
		return $run;
	}

	/**
	 * Answer and analyze one prompt/engine pair.
	 *
	 * @param Repository $repo   Repository.
	 * @param array      $result Claimed result row.
	 */
	private function process( Repository $repo, array $result ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on some hosts.
		}

		$provider = Engines::provider( $result['engine'] );
		if ( ! $provider ) {
			$repo->fail_result( $result, __( 'Unknown engine.', 'ai-visibility-tracker' ), 0 );
			return;
		}

		$answer = $provider->ask( $result['prompt'] );
		if ( is_wp_error( $answer ) ) {
			// A bad key won't fix itself, so don't spend a retry on it.
			$auth_error = in_array( $answer->get_error_code(), array( 'aivt_http_401', 'aivt_http_403' ), true );
			$repo->fail_result( $result, $answer->get_error_message(), $auth_error ? 0 : 2 );
			return;
		}

		$analyzer = new Analyzer( Engines::utility_provider() );
		$analysis = $analyzer->analyze( $answer['text'], $answer['citations'], Settings::all() );
		$repo->complete_result( $result, $answer, $analysis );
	}

	/**
	 * Cron worker: process items until the time budget is used, then hand
	 * off to a new cron request if work remains.
	 */
	public function process_cron() {
		$repo = new Repository();
		$run  = $repo->active_run();
		if ( ! $run ) {
			return;
		}

		$start = time();
		while ( time() - $start < self::CRON_BUDGET ) {
			$run = $this->step( (int) $run['id'] );
			if ( ! $run || 'running' !== $run['status'] ) {
				return;
			}
		}
		$this->schedule_processing( 10 );
	}

	/**
	 * Recurring schedule handler.
	 */
	public function scheduled_run() {
		$this->start( 'scheduled' );
	}

	/**
	 * Queue a cron processing request.
	 *
	 * @param int $delay Seconds from now.
	 */
	private function schedule_processing( $delay ) {
		if ( ! wp_next_scheduled( self::CRON_PROCESS ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_PROCESS );
		}
	}
}
