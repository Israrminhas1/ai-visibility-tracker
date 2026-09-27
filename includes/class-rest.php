<?php
/**
 * REST endpoints used by the admin screens.
 *
 * @package AIVT
 */

namespace AIVT;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/aivt/v1. All require the manage_options capability.
 */
class Rest {

	const NS = 'aivt/v1';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		$admin = array( $this, 'can_manage' );

		register_rest_route(
			self::NS,
			'/engines/(?P<engine>[a-z]+)/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_engine' ),
				'permission_callback' => $admin,
				'args'                => array(
					'key'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( __CLASS__, 'sanitize_key_input' ),
					),
					'model' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/prompts/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_prompts' ),
				'permission_callback' => $admin,
				'args'                => array(
					'count' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/brand/names',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest_names' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/runs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_run' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/runs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/runs/(?P<id>\d+)/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'step_run' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/runs/(?P<id>\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_run' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/results/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_result' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * API keys: trim and strip whitespace/control characters only.
	 *
	 * @param string $value Raw key.
	 * @return string
	 */
	public static function sanitize_key_input( $value ) {
		return preg_replace( '/[\s\x00-\x1F\x7F]+/', '', (string) $value );
	}

	/**
	 * POST /engines/{engine}/test — test a key (typed or saved) and model.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function test_engine( WP_REST_Request $req ) {
		$engine = $req['engine'];
		if ( ! Engines::get( $engine ) ) {
			return new WP_Error( 'aivt_engine', __( 'Unknown engine.', 'ai-visibility-tracker' ), array( 'status' => 404 ) );
		}
		$key = '' !== $req['key'] ? $req['key'] : Settings::engine_key( $engine );
		if ( '' === $key ) {
			return new WP_Error( 'aivt_no_key', __( 'Please enter an API key.', 'ai-visibility-tracker' ), array( 'status' => 400 ) );
		}

		$result = Engines::provider( $engine, $key, $req['model'] )->test();
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return array(
			'ok'      => true,
			'message' => __( 'Connected! Your key works.', 'ai-visibility-tracker' ),
		);
	}

	/**
	 * POST /prompts/generate.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function generate_prompts( WP_REST_Request $req ) {
		$prompts = ( new Prompt_Generator() )->generate( (int) $req['count'] );
		if ( is_wp_error( $prompts ) ) {
			return new WP_Error( $prompts->get_error_code(), $prompts->get_error_message(), array( 'status' => 400 ) );
		}
		return array( 'prompts' => $prompts );
	}

	/**
	 * POST /brand/names — suggest other names for the brand.
	 *
	 * @return array|WP_Error
	 */
	public function suggest_names() {
		$names = ( new Prompt_Generator() )->suggest_brand_names();
		if ( is_wp_error( $names ) ) {
			return new WP_Error( $names->get_error_code(), $names->get_error_message(), array( 'status' => 400 ) );
		}
		return array( 'names' => $names );
	}

	/**
	 * POST /runs — start a run.
	 *
	 * @return array|WP_Error
	 */
	public function start_run() {
		$run = ( new Runner() )->start( 'manual' );
		if ( is_wp_error( $run ) ) {
			return new WP_Error( $run->get_error_code(), $run->get_error_message(), array( 'status' => 400 ) );
		}
		return $this->run_payload( $run );
	}

	/**
	 * GET /runs/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function get_run( WP_REST_Request $req ) {
		$run = ( new Repository() )->run( (int) $req['id'] );
		return $run ? $this->run_payload( $run ) : new WP_Error( 'aivt_not_found', __( 'Run not found.', 'ai-visibility-tracker' ), array( 'status' => 404 ) );
	}

	/**
	 * POST /runs/{id}/step — process one item.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function step_run( WP_REST_Request $req ) {
		$repo = new Repository();
		$run  = $repo->run( (int) $req['id'] );
		if ( ! $run ) {
			return new WP_Error( 'aivt_not_found', __( 'Run not found.', 'ai-visibility-tracker' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $run['status'] ) {
			$run = ( new Runner() )->step( (int) $run['id'] );
		}
		return $this->run_payload( $run );
	}

	/**
	 * POST /runs/{id}/cancel.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public function cancel_run( WP_REST_Request $req ) {
		$repo = new Repository();
		$repo->cancel_run( (int) $req['id'] );
		return $this->run_payload( $repo->run( (int) $req['id'] ) );
	}

	/**
	 * GET /results/{id} — full answer with citations and mentions.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function get_result( WP_REST_Request $req ) {
		$result = ( new Repository() )->result( (int) $req['id'] );
		if ( ! $result ) {
			return new WP_Error( 'aivt_not_found', __( 'Result not found.', 'ai-visibility-tracker' ), array( 'status' => 404 ) );
		}
		$settings = Settings::all();
		foreach ( $result['citations'] as &$c ) {
			$c['type'] = Analyzer::source_type( $c['domain'], $settings );
			$c['edit'] = $c['post_id'] ? get_edit_post_link( (int) $c['post_id'], 'raw' ) : '';
		}
		unset( $c );
		$result['engine_label'] = Engines::label( $result['engine'] );
		$result['response_html'] = Admin\Admin::format_answer( (string) $result['response'] );
		unset( $result['response'] );
		return $result;
	}

	/**
	 * Run data for the progress UI.
	 *
	 * @param array $run Run row.
	 * @return array
	 */
	private function run_payload( array $run ) {
		$total = max( 1, (int) $run['total'] );
		$done  = (int) $run['done'] + (int) $run['failed'];
		return array(
			'id'        => (int) $run['id'],
			'status'    => $run['status'],
			'total'     => (int) $run['total'],
			'done'      => (int) $run['done'],
			'failed'    => (int) $run['failed'],
			'mentioned' => (int) $run['mentioned'],
			'percent'   => (int) floor( 100 * $done / $total ),
		);
	}
}
