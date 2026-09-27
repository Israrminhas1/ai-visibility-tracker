<?php
/**
 * Serves an auto-generated /llms.txt.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * llms.txt is a plain Markdown file that gives AI assistants a short,
 * clean overview of a site and links to its most useful pages.
 * See https://llmstxt.org/.
 */
class Llms_Txt {

	const CACHE = 'aivt_llms_txt';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'save_post', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		if ( Settings::get( 'llms_enabled' ) && ! self::physical_file_exists() ) {
			add_action( 'parse_request', array( $this, 'maybe_serve' ), 0 );
		}
	}

	/**
	 * Whether a real llms.txt file exists in the site root (it always wins).
	 *
	 * @return bool
	 */
	public static function physical_file_exists() {
		return file_exists( ABSPATH . 'llms.txt' );
	}

	/**
	 * Public URL of llms.txt.
	 *
	 * @return string
	 */
	public static function url() {
		return home_url( '/llms.txt' );
	}

	/**
	 * Output llms.txt when it is requested.
	 */
	public function maybe_serve() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( untrailingslashit( $path ) !== untrailingslashit( (string) wp_parse_url( self::url(), PHP_URL_PATH ) ) ) {
			return;
		}

		if ( Settings::get( 'track_traffic' ) ) {
			$bot = Traffic::bot_from_ua( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
			if ( $bot ) {
				( new Repository() )->log_traffic( 'bot', $bot, '/llms.txt', 0 );
			}
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built from escaped parts.
		exit;
	}

	/**
	 * Clear the cached file.
	 */
	public static function flush() {
		delete_transient( self::CACHE );
	}

	/**
	 * The llms.txt content (cached for 12 hours).
	 *
	 * @return string
	 */
	public static function content() {
		$cached = get_transient( self::CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$text = self::build();
		set_transient( self::CACHE, $text, 12 * HOUR_IN_SECONDS );
		return $text;
	}

	/**
	 * Build llms.txt from settings and published content.
	 *
	 * @return string
	 */
	public static function build() {
		$settings = Settings::all();
		$name     = $settings['brand_name'] ? $settings['brand_name'] : get_bloginfo( 'name' );
		$summary  = $settings['business'] ? $settings['business'] : get_bloginfo( 'description' );

		$out = '# ' . self::clean( $name ) . "\n\n";
		if ( $summary ) {
			$out .= '> ' . self::clean( $summary ) . "\n\n";
		}
		if ( $settings['llms_intro'] ) {
			$out .= trim( wp_strip_all_tags( $settings['llms_intro'] ) ) . "\n\n";
		}
		if ( $settings['location'] ) {
			$out .= 'Location / service area: ' . self::clean( $settings['location'] ) . "\n\n";
		}

		$sections = array(
			__( 'Pages', 'ai-visibility-tracker' )    => array(
				'post_type'      => 'page',
				'posts_per_page' => 30,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			),
			__( 'Products', 'ai-visibility-tracker' ) => array(
				'post_type'      => 'product',
				'posts_per_page' => 30,
			),
			__( 'Articles', 'ai-visibility-tracker' ) => array(
				'post_type'      => 'post',
				'posts_per_page' => 20,
			),
		);

		foreach ( $sections as $title => $args ) {
			if ( ! post_type_exists( $args['post_type'] ) ) {
				continue;
			}
			$posts = get_posts(
				array_merge(
					$args,
					array(
						'post_status'  => 'publish',
						'has_password' => false,
					)
				)
			);
			$lines = array();
			foreach ( $posts as $p ) {
				if ( self::is_noindex( $p->ID ) ) {
					continue;
				}
				$desc    = self::description( $p );
				$lines[] = '- [' . self::clean( get_the_title( $p ) ) . '](' . esc_url_raw( get_permalink( $p ) ) . ')' . ( $desc ? ': ' . $desc : '' );
			}
			if ( $lines ) {
				$out .= '## ' . $title . "\n\n" . implode( "\n", $lines ) . "\n\n";
			}
		}

		/**
		 * Filter the generated llms.txt content.
		 *
		 * @param string $out Content.
		 */
		return (string) apply_filters( 'aivt_llms_txt', rtrim( $out ) . "\n" );
	}

	/**
	 * One-line description of a post: excerpt or the start of its content.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function description( $post ) {
		$text = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
		$text = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $text ) ), 25, '…' );
		return self::clean( $text );
	}

	/**
	 * Whether popular SEO plugins mark a post noindex.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_noindex( $post_id ) {
		if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );
		return is_array( $rank_math ) && in_array( 'noindex', $rank_math, true );
	}

	/**
	 * Single-line plain text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}
}
