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

		// A scheduled run and a click can arrive together: only one may create a run.
		if ( ! self::lock() ) {
			return new WP_Error( 'aivt_running', __( 'A run is already starting.', 'ai-visibility-tracker' ) );
		}

		try {
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

			$budget = self::budget_problem( $engines, count( $prompts ) );
			if ( $budget ) {
				return new WP_Error( 'aivt_budget', $budget );
			}

			$run_id = $repo->create_run( $trigger, $engines, $prompts );
		} finally {
			self::unlock();
		}

		$this->schedule_processing( 5 );
		return $repo->run( $run_id );
	}

	/**
	 * Estimated cost of a run over the active prompts and engines.
	 *
	 * @param array|null $engines Engine keys (default: active engines).
	 * @param int|null   $prompts Number of prompts (default: active prompts).
	 * @return float USD.
	 */
	public static function estimate( $engines = null, $prompts = null ) {
		$repo    = new Repository();
		$engines = null === $engines ? Engines::active() : $engines;
		$prompts = null === $prompts ? count( $repo->prompts( true ) ) : (int) $prompts;
		$total   = 0.0;

		foreach ( $engines as $engine ) {
			$cfg = Settings::engine( $engine );
			// Learned from recent answers once there are some; the typical cost until then.
			$per    = $repo->average_cost( $engine, $cfg['model'] );
			$total += $prompts * ( null !== $per ? $per : Pricing::typical_answer( $engine, $cfg['model'] ) );
		}
		return $total;
	}

	/**
	 * Why a run would go over the monthly budget, or '' when it fits (or there is no budget).
	 *
	 * @param array $engines Engine keys.
	 * @param int   $prompts Number of prompts.
	 * @return string
	 */
	public static function budget_problem( array $engines, $prompts ) {
		$budget = (float) Settings::get( 'monthly_budget' );
		if ( $budget <= 0 ) {
			return '';
		}

		$spent    = ( new Repository() )->spent_since( gmdate( 'Y-m-01 00:00:00' ) );
		$estimate = self::estimate( $engines, $prompts );
		if ( $spent + $estimate <= $budget ) {
			return '';
		}

		return sprintf(
			/* translators: 1: estimated cost of the run, 2: amount spent this month, 3: monthly budget */
			__( 'This run would cost about %1$s, but %2$s of the %3$s monthly budget is already spent. Raise the budget in Settings, or remove prompts or engines.', 'ai-visibility-tracker' ),
			Pricing::format( $estimate ),
			Pricing::format( $spent ),
			Pricing::format( $budget )
		);
	}

	/**
	 * Take the start lock. A lock older than a minute is left over from a crash.
	 *
	 * @return bool
	 */
	private static function lock() {
		if ( add_option( 'aivt_start_lock', time(), '', false ) ) {
			return true;
		}
		if ( (int) get_option( 'aivt_start_lock' ) < time() - MINUTE_IN_SECONDS ) {
			delete_option( 'aivt_start_lock' );
			return add_option( 'aivt_start_lock', time(), '', false );
		}
		return false;
	}

	/**
	 * Release the start lock.
	 */
	private static function unlock() {
		delete_option( 'aivt_start_lock' );
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
			$repo->prune( max( 1, (int) Settings::get( 'keep_runs' ) ) );
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
			$kind = Providers\Base_Provider::kind_of( $answer );

			// A bad key or an empty balance won't fix itself: stop this engine for
			// the rest of the run instead of failing each prompt in turn.
			if ( in_array( $kind, array( Providers\Base_Provider::KIND_AUTH, Providers\Base_Provider::KIND_CREDITS ), true ) ) {
				$repo->fail_result( $result, $answer->get_error_message(), 0 );
				$repo->fail_engine( (int) $result['run_id'], $result['engine'], $answer->get_error_message() );
				return;
			}

			// Rate limits and outages usually pass; other errors get one more try.
			$temporary = in_array( $kind, array( Providers\Base_Provider::KIND_RATE, Providers\Base_Provider::KIND_OUTAGE ), true );
			$repo->fail_result( $result, $answer->get_error_message(), $temporary ? Repository::MAX_ATTEMPTS : 2 );
			return;
		}

		$answer['cost'] = Pricing::cost(
			$result['engine'],
			$result['model'],
			(int) $answer['input_tokens'],
			(int) $answer['output_tokens'],
			isset( $answer['searches'] ) ? (int) $answer['searches'] : 0,
			isset( $answer['reported_cost'] ) ? $answer['reported_cost'] : null
		);

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

		// Queue the next request first: if this one dies (host time limit, fatal
		// error), the run still carries on without anyone opening the admin.
		$this->schedule_processing( self::CRON_BUDGET + 120 );

		$start = time();
		while ( time() - $start < self::CRON_BUDGET ) {
			$run = $this->step( (int) $run['id'] );
			if ( ! $run || 'running' !== $run['status'] ) {
				wp_clear_scheduled_hook( self::CRON_PROCESS );
				return;
			}
		}

		// Finished cleanly: carry on soon rather than waiting for the safety net.
		wp_clear_scheduled_hook( self::CRON_PROCESS );
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
