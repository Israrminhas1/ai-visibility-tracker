<?php
/**
 * Counts visitors referred by AI assistants and visits from AI crawlers.
 *
 * @package AIVT
 */

namespace AIVT;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy-friendly: stores only daily hit counts per source and landing
 * path. No cookies, IP addresses or user agents are kept.
 */
class Traffic {

	const CRON_PRUNE = 'aivt_prune_traffic';

	/**
	 * AI assistants that send visitors, keyed by source ID.
	 * 'hosts' match the referrer host; 'utm' match a utm_source value.
	 *
	 * @return array
	 */
	public static function referrers() {
		return array(
			'chatgpt'    => array(
				'label' => 'ChatGPT',
				'hosts' => array( 'chatgpt.com', 'chat.openai.com' ),
				'utm'   => array( 'chatgpt.com', 'chatgpt', 'openai' ),
			),
			'perplexity' => array(
				'label' => 'Perplexity',
				'hosts' => array( 'perplexity.ai' ),
				'utm'   => array( 'perplexity', 'perplexity.ai' ),
			),
			'gemini'     => array(
				'label' => 'Gemini',
				'hosts' => array( 'gemini.google.com', 'bard.google.com' ),
				'utm'   => array( 'gemini' ),
			),
			'claude'     => array(
				'label' => 'Claude',
				'hosts' => array( 'claude.ai' ),
				'utm'   => array( 'claude', 'claude.ai' ),
			),
			'copilot'    => array(
				'label' => 'Copilot',
				'hosts' => array( 'copilot.microsoft.com', 'copilot.cloud.microsoft' ),
				'utm'   => array( 'copilot', 'copilot.com' ),
			),
			'grok'       => array(
				'label' => 'Grok',
				'hosts' => array( 'grok.com' ),
				'utm'   => array( 'grok', 'grok.com' ),
			),
			'deepseek'   => array(
				'label' => 'DeepSeek',
				'hosts' => array( 'chat.deepseek.com' ),
				'utm'   => array( 'deepseek' ),
			),
			'meta'       => array(
				'label' => 'Meta AI',
				'hosts' => array( 'meta.ai' ),
				'utm'   => array( 'meta.ai' ),
			),
			'mistral'    => array(
				'label' => 'Le Chat (Mistral)',
				'hosts' => array( 'chat.mistral.ai' ),
				'utm'   => array( 'mistral' ),
			),
			'you'        => array(
				'label' => 'You.com',
				'hosts' => array( 'you.com' ),
				'utm'   => array( 'you.com' ),
			),
		);
	}

	/**
	 * AI crawlers, keyed by the user-agent token they send.
	 *
	 * @return array token => [ label, vendor, purpose ]
	 */
	public static function bots() {
		return array(
			'GPTBot'             => array( 'OpenAI', 'training' ),
			'OAI-SearchBot'      => array( 'OpenAI', 'search' ),
			'ChatGPT-User'       => array( 'OpenAI', 'user' ),
			'ClaudeBot'          => array( 'Anthropic', 'training' ),
			'Claude-SearchBot'   => array( 'Anthropic', 'search' ),
			'Claude-User'        => array( 'Anthropic', 'user' ),
			'PerplexityBot'      => array( 'Perplexity', 'search' ),
			'Perplexity-User'    => array( 'Perplexity', 'user' ),
			'Google-CloudVertexBot' => array( 'Google', 'user' ),
			'Applebot'           => array( 'Apple', 'search' ),
			'Amazonbot'          => array( 'Amazon', 'search' ),
			'meta-externalagent' => array( 'Meta', 'training' ),
			'meta-externalfetcher' => array( 'Meta', 'user' ),
			'DuckAssistBot'      => array( 'DuckDuckGo', 'search' ),
			'MistralAI-User'     => array( 'Mistral', 'user' ),
			'CCBot'              => array( 'Common Crawl', 'training' ),
			'Bytespider'         => array( 'ByteDance', 'training' ),
		);
	}

	/**
	 * Most AI-referral hits counted per visitor per day.
	 */
	const HITS_PER_DAY = 20;

	/**
	 * Path under which visits to archives, search and other non-post pages are counted.
	 */
	const OTHER_PATH = '/(other)';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( self::CRON_PRUNE, array( $this, 'prune' ) );
		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_PRUNE );
		}
		if ( ! Settings::get( 'track_traffic' ) ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'log_bot' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'beacon' ) );
	}

	/**
	 * Public endpoint the beacon posts to.
	 */
	public function routes() {
		register_rest_route(
			Rest::NS,
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_referral' ),
				'permission_callback' => '__return_true', // Anonymous visitors; input is validated against an allowlist.
				'args'                => array(
					'ref'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'src'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'path' => array(
						'type'    => 'string',
						'default' => '/',
					),
				),
			)
		);
	}

	/**
	 * Record a visit referred by an AI assistant.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public function record_referral( WP_REST_Request $req ) {
		if ( ! Settings::get( 'track_traffic' ) ) {
			return array( 'ok' => false );
		}
		$source = self::referrer_source( (string) $req['ref'], (string) $req['src'] );
		if ( ! $source ) {
			return array( 'ok' => false );
		}
		// Anyone can call this endpoint, so it must not be usable to fill the
		// database or inflate the numbers: a few hits per visitor per day, and
		// only for real pages.
		if ( self::over_limit() ) {
			return array( 'ok' => false );
		}
		$path    = self::clean_path( (string) $req['path'] );
		$post_id = self::path_post_id( $path );
		if ( ! $post_id && ! self::is_home_path( $path ) ) {
			$path = self::OTHER_PATH;
		}
		( new Repository() )->log_traffic( 'referral', $source, $path, $post_id );
		return array( 'ok' => true );
	}

	/**
	 * Whether this visitor has already reported HITS_PER_DAY visits today.
	 * Only a hash of the IP address is kept, and only until the next day.
	 *
	 * @return bool
	 */
	private static function over_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'aivt_hits_' . substr( wp_hash( $ip . gmdate( 'Y-m-d' ) ), 0, 20 );
		$n   = (int) get_transient( $key );
		if ( $n >= self::HITS_PER_DAY ) {
			return true;
		}
		set_transient( $key, $n + 1, DAY_IN_SECONDS );
		return false;
	}

	/**
	 * Whether a path is the site's front page.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_home_path( $path ) {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return untrailingslashit( $path ) === untrailingslashit( $home );
	}

	/**
	 * Log a front-end request from a known AI crawler.
	 */
	public function log_bot() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$bot = self::bot_from_ua( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
		if ( ! $bot ) {
			return;
		}
		// Not-found pages would let anyone add a row per made-up URL.
		if ( is_404() ) {
			return;
		}
		$path = self::clean_path( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
		$post = is_singular() ? get_queried_object_id() : 0;
		if ( ! $post && ! is_front_page() && ! is_home() ) {
			$path = self::OTHER_PATH;
		}
		( new Repository() )->log_traffic( 'bot', $bot, $path, $post );
	}

	/**
	 * Tiny script that reports AI referrals. It runs in the browser so visits
	 * are counted even when a caching plugin serves the page.
	 */
	public function beacon() {
		if ( is_admin() || is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return; // Don't count the site's own editors.
		}
		$pattern = array();
		foreach ( self::referrers() as $r ) {
			$pattern = array_merge( $pattern, $r['hosts'], $r['utm'] );
		}
		$pattern = implode( '|', array_map( 'preg_quote', array_unique( $pattern ) ) );
		$script  = sprintf(
			'(function(){try{var r=document.referrer,h=r?new URL(r).hostname:"",m=/[?&]utm_source=([^&#]+)/.exec(location.search),s=m?decodeURIComponent(m[1]):"";if(h===location.hostname){h="";}if(!/%1$s/i.test(h+" "+s)){return;}var d=new URLSearchParams({ref:h,src:s,path:location.pathname});if(navigator.sendBeacon){navigator.sendBeacon(%2$s,d);}else{fetch(%2$s,{method:"POST",body:d,keepalive:true});}}catch(e){}})();',
			str_replace( '/', '\/', $pattern ),
			wp_json_encode( esc_url_raw( rest_url( Rest::NS . '/hit' ) ) )
		);
		wp_register_script( 'aivt-referral', false, array(), AIVT_VERSION, true );
		wp_enqueue_script( 'aivt-referral' );
		wp_add_inline_script( 'aivt-referral', $script );
	}

	/**
	 * Map a referrer host and/or utm_source to a source ID.
	 *
	 * @param string $host Referrer host.
	 * @param string $utm  utm_source value.
	 * @return string Source ID or ''.
	 */
	public static function referrer_source( $host, $utm ) {
		$host = strtolower( trim( $host ) );
		$utm  = strtolower( trim( $utm ) );
		foreach ( self::referrers() as $id => $r ) {
			if ( '' !== $host && Analyzer::domain_matches( preg_replace( '/^www\./', '', $host ), $r['hosts'] ) ) {
				return $id;
			}
			if ( '' !== $utm && in_array( $utm, $r['utm'], true ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Crawler token found in a user agent, or ''.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function bot_from_ua( $ua ) {
		if ( '' === $ua ) {
			return '';
		}
		foreach ( array_keys( self::bots() ) as $token ) {
			if ( false !== stripos( $ua, $token ) ) {
				return $token;
			}
		}
		return '';
	}

	/**
	 * Label for a referral source ID.
	 *
	 * @param string $id Source ID.
	 * @return string
	 */
	public static function source_label( $id ) {
		$r = self::referrers();
		return isset( $r[ $id ] ) ? $r[ $id ]['label'] : $id;
	}

	/**
	 * Path only, no query string, limited length.
	 *
	 * @param string $path Raw path or URI.
	 * @return string
	 */
	private static function clean_path( $path ) {
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$path = '/' . ltrim( preg_replace( '#[^A-Za-z0-9/_.~%-]#', '', $path ), '/' );
		return mb_substr( $path, 0, 190 );
	}

	/**
	 * Post ID for a site path.
	 *
	 * @param string $path Path.
	 * @return int
	 */
	private static function path_post_id( $path ) {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( untrailingslashit( $path ) === untrailingslashit( $home ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return (int) url_to_postid( home_url( substr( $path, strlen( rtrim( $home, '/' ) ) ) ) );
	}

	/**
	 * Daily cleanup.
	 */
	public function prune() {
		( new Repository() )->prune_traffic( 400 );
	}
}
