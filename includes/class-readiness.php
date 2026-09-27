<?php
/**
 * AI readiness audit: can AI assistants find, read and understand the site?
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * Runs site-level and page-level checks. Results are cached for 12 hours.
 */
class Readiness {

	const CACHE_PREFIX = 'aivt_readiness_';

	/**
	 * Robots tokens AI companies use, with what blocking them means.
	 * purpose: search (appears in AI answers), user (fetched when a user asks), training.
	 *
	 * @return array token => [ vendor, purpose ]
	 */
	public static function robot_tokens() {
		return array(
			'OAI-SearchBot'      => array( 'ChatGPT search', 'search' ),
			'ChatGPT-User'       => array( 'ChatGPT', 'user' ),
			'GPTBot'             => array( 'OpenAI', 'training' ),
			'Claude-SearchBot'   => array( 'Claude search', 'search' ),
			'Claude-User'        => array( 'Claude', 'user' ),
			'ClaudeBot'          => array( 'Anthropic', 'training' ),
			'PerplexityBot'      => array( 'Perplexity', 'search' ),
			'Perplexity-User'    => array( 'Perplexity', 'user' ),
			'Googlebot'          => array( 'Google Search, AI Overviews & AI Mode', 'search' ),
			'Google-Extended'    => array( 'Gemini', 'training' ),
			'Bingbot'            => array( 'Bing & Copilot', 'search' ),
			'Applebot'           => array( 'Siri & Spotlight', 'search' ),
			'Applebot-Extended'  => array( 'Apple Intelligence', 'training' ),
			'meta-externalagent' => array( 'Meta AI', 'training' ),
			'CCBot'              => array( 'Common Crawl', 'training' ),
		);
	}

	/**
	 * Run (or load cached) audit.
	 *
	 * @param string $url   Page to check; '' for the homepage.
	 * @param bool   $fresh Ignore the cache.
	 * @return array
	 */
	public function audit( $url = '', $fresh = false ) {
		$url = $url ? $url : home_url( '/' );
		$key = self::CACHE_PREFIX . (int) get_option( 'aivt_readiness_ver', 1 ) . '_' . md5( $url );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$robots = $this->robots_txt();
		$groups = self::parse_robots( $robots['content'] );

		$bots = array();
		foreach ( self::robot_tokens() as $token => $info ) {
			$v      = self::robots_verdict( $groups, $token, '/' );
			$bots[] = array(
				'token'   => $token,
				'label'   => $info[0],
				'purpose' => $info[1],
				'allowed' => $v['allowed'],
				'rule'    => $v['rule'],
			);
		}

		$report = array(
			'time'   => time(),
			'url'    => $url,
			'robots' => $robots,
			'bots'   => $bots,
			'site'   => $this->site_checks( $robots, $bots ),
			'page'   => $this->page_checks( $url ),
		);

		$all  = array_merge( $report['site'], $report['page']['checks'] );
		$pass = 0;
		$tot  = 0;
		foreach ( $all as $c ) {
			if ( 'info' === $c['status'] ) {
				continue;
			}
			++$tot;
			$pass += 'pass' === $c['status'] ? 1 : 0;
		}
		$report['passed'] = $pass;
		$report['total']  = $tot;

		set_transient( $key, $report, 12 * HOUR_IN_SECONDS );
		return $report;
	}

	/**
	 * Clear all cached audits.
	 */
	public static function flush() {
		// Changing the version makes every cached key stale; old transients expire on their own.
		update_option( 'aivt_readiness_ver', (int) get_option( 'aivt_readiness_ver', 1 ) + 1, false );
	}

	/* ---------------------------------------------------------------------
	 * Site-level checks
	 * ------------------------------------------------------------------ */

	/**
	 * Checks that apply to the whole site.
	 *
	 * @param array $robots robots.txt info.
	 * @param array $bots   Bot verdicts.
	 * @return array
	 */
	private function site_checks( array $robots, array $bots ) {
		$checks = array();

		$public   = (bool) get_option( 'blog_public' );
		$checks[] = self::check(
			'search_visibility',
			__( 'Search engines are allowed', 'ai-visibility-tracker' ),
			$public ? 'pass' : 'fail',
			$public ? __( 'Your site is not hidden from search engines.', 'ai-visibility-tracker' ) : __( '"Discourage search engines from indexing this site" is turned on, so AI search tools will mostly ignore your site.', 'ai-visibility-tracker' ),
			$public ? '' : __( 'Go to Settings → Reading and untick "Discourage search engines".', 'ai-visibility-tracker' ),
			$public ? '' : admin_url( 'options-reading.php' )
		);

		$https    = 0 === strpos( home_url(), 'https://' );
		$checks[] = self::check(
			'https',
			__( 'Site uses HTTPS', 'ai-visibility-tracker' ),
			$https ? 'pass' : 'warn',
			$https ? __( 'Your site address uses a secure connection.', 'ai-visibility-tracker' ) : __( 'Your site address starts with http://. AI tools and search engines prefer secure sites.', 'ai-visibility-tracker' ),
			$https ? '' : __( 'Ask your host for a free SSL certificate, then update the addresses in Settings → General.', 'ai-visibility-tracker' )
		);

		$blocked = array();
		foreach ( $bots as $b ) {
			if ( ! $b['allowed'] && 'training' !== $b['purpose'] ) {
				$blocked[] = $b['token'];
			}
		}
		$checks[] = self::check(
			'robots_ai',
			__( 'AI search crawlers can access your site', 'ai-visibility-tracker' ),
			$blocked ? 'fail' : 'pass',
			$blocked
				/* translators: %s: list of crawler names */
				? sprintf( __( 'robots.txt blocks: %s. These crawlers decide whether you appear in AI answers.', 'ai-visibility-tracker' ), implode( ', ', $blocked ) )
				: __( 'No AI search crawler is blocked by robots.txt.', 'ai-visibility-tracker' ),
			$blocked ? __( 'Remove the Disallow rules for these crawlers from robots.txt (see the table below).', 'ai-visibility-tracker' ) : ''
		);

		$llms_file   = Llms_Txt::physical_file_exists();
		$llms_plugin = (bool) Settings::get( 'llms_enabled' );
		$checks[]    = self::check(
			'llms_txt',
			__( 'llms.txt file', 'ai-visibility-tracker' ),
			$llms_file || $llms_plugin ? 'pass' : 'warn',
			$llms_file
				? __( 'A llms.txt file exists in your site root.', 'ai-visibility-tracker' )
				: ( $llms_plugin ? __( 'This plugin is serving an automatic llms.txt.', 'ai-visibility-tracker' ) : __( 'No llms.txt found. It gives AI assistants a clean summary of your site and its key pages.', 'ai-visibility-tracker' ) ),
			$llms_file || $llms_plugin ? '' : __( 'Turn on the automatic llms.txt in this plugin\'s Settings.', 'ai-visibility-tracker' ),
			$llms_file || $llms_plugin ? Llms_Txt::url() : admin_url( 'admin.php?page=aivt-settings#llms' )
		);

		$sitemap  = $this->sitemap_info( $robots['content'] );
		$checks[] = self::check(
			'sitemap',
			__( 'XML sitemap', 'ai-visibility-tracker' ),
			$sitemap ? 'pass' : 'warn',
			/* translators: %s: where the sitemap comes from */
			$sitemap ? sprintf( __( 'Sitemap found (%s).', 'ai-visibility-tracker' ), $sitemap ) : __( 'No sitemap found. Sitemaps help crawlers discover all your pages.', 'ai-visibility-tracker' ),
			$sitemap ? '' : __( 'Enable the sitemap in your SEO plugin, or make sure WordPress\'s built-in sitemap is not disabled.', 'ai-visibility-tracker' )
		);

		$schema_plugin = $this->seo_plugin();
		$checks[]      = self::check(
			'seo_plugin',
			__( 'SEO plugin', 'ai-visibility-tracker' ),
			'info',
			/* translators: %s: plugin name */
			$schema_plugin ? sprintf( __( '%s is active. It handles titles, descriptions and schema.', 'ai-visibility-tracker' ), $schema_plugin ) : __( 'No SEO plugin detected. The page checks below show whether titles, descriptions and schema are in place anyway.', 'ai-visibility-tracker' ),
			''
		);

		return $checks;
	}

	/**
	 * robots.txt content and where it came from.
	 *
	 * @return array content, source (file|http|virtual)
	 */
	private function robots_txt() {
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			return array(
				'content' => (string) file_get_contents( ABSPATH . 'robots.txt' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'source'  => 'file',
			);
		}

		$res = wp_remote_get( home_url( '/robots.txt' ), $this->http_args() );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			return array(
				'content' => (string) wp_remote_retrieve_body( $res ),
				'source'  => 'http',
			);
		}

		// Loopback failed: rebuild the virtual file the way WordPress does.
		$path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output = "User-agent: *\nDisallow: $path/wp-admin/\nAllow: $path/wp-admin/admin-ajax.php\n";
		return array(
			'content' => (string) apply_filters( 'robots_txt', $output, get_option( 'blog_public' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			'source'  => 'virtual',
		);
	}

	/**
	 * Where the sitemap comes from, or ''.
	 *
	 * @param string $robots robots.txt content.
	 * @return string
	 */
	private function sitemap_info( $robots ) {
		if ( preg_match( '/^\s*sitemap:\s*(\S+)/im', $robots, $m ) ) {
			return 'robots.txt → ' . $m[1];
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}
		if ( function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
			return 'WordPress → ' . home_url( '/wp-sitemap.xml' );
		}
		return '';
	}

	/**
	 * Name of the active SEO plugin, or ''.
	 *
	 * @return string
	 */
	private function seo_plugin() {
		$plugins = array(
			'WPSEO_VERSION'    => 'Yoast SEO',
			'RANK_MATH_VERSION' => 'Rank Math',
			'AIOSEO_VERSION'   => 'All in One SEO',
			'SEOPRESS_VERSION' => 'SEOPress',
			'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
		);
		foreach ( $plugins as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Page-level checks
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch a page and check what AI crawlers see.
	 *
	 * @param string $url URL on this site.
	 * @return array fetched, error, checks, schema
	 */
	private function page_checks( $url ) {
		$res = wp_remote_get( $url, $this->http_args() );
		if ( is_wp_error( $res ) ) {
			return array(
				'fetched' => false,
				/* translators: %s: error message */
				'error'   => sprintf( __( 'Could not load the page from the server: %s. Some hosts block a site from loading itself; the site checks above still apply.', 'ai-visibility-tracker' ), $res->get_error_message() ),
				'checks'  => array(),
				'schema'  => array(),
			);
		}

		$code   = (int) wp_remote_retrieve_response_code( $res );
		$html   = (string) wp_remote_retrieve_body( $res );
		$checks = array();

		$checks[] = self::check(
			'status',
			__( 'Page loads', 'ai-visibility-tracker' ),
			200 === $code ? 'pass' : 'fail',
			/* translators: %d: HTTP status code */
			sprintf( __( 'The server answered with status %d.', 'ai-visibility-tracker' ), $code ),
			200 === $code ? '' : __( 'Make sure the page is public and not behind a login, maintenance mode or firewall.', 'ai-visibility-tracker' )
		);

		$robots_meta = '';
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]*>/i', $html, $m ) && preg_match( '/content=["\']([^"\']*)["\']/i', $m[0], $c ) ) {
			$robots_meta = strtolower( $c[1] );
		}
		$header_robots = strtolower( (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ) );
		$noindex       = false !== strpos( $robots_meta . ' ' . $header_robots, 'noindex' );
		$checks[]      = self::check(
			'noindex',
			__( 'Page can be indexed', 'ai-visibility-tracker' ),
			$noindex ? 'fail' : 'pass',
			$noindex ? __( 'This page tells search engines not to index it (noindex).', 'ai-visibility-tracker' ) : __( 'No noindex tag found.', 'ai-visibility-tracker' ),
			$noindex ? __( 'Remove the noindex setting for this page in your SEO plugin.', 'ai-visibility-tracker' ) : ''
		);

		$title    = preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ? trim( wp_strip_all_tags( html_entity_decode( $m[1] ) ) ) : '';
		$checks[] = self::check(
			'title',
			__( 'Page title', 'ai-visibility-tracker' ),
			$title ? 'pass' : 'fail',
			$title ? $title : __( 'The page has no <title>.', 'ai-visibility-tracker' ),
			$title ? '' : __( 'Your theme should output a title. Check that it supports the "title-tag" feature.', 'ai-visibility-tracker' )
		);

		$desc = '';
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]*>/i', $html, $m ) && preg_match( '/content=["\']([^"\']*)["\']/i', $m[0], $c ) ) {
			$desc = trim( html_entity_decode( $c[1] ) );
		}
		$checks[] = self::check(
			'description',
			__( 'Meta description', 'ai-visibility-tracker' ),
			$desc ? 'pass' : 'warn',
			$desc ? $desc : __( 'No meta description. AI tools often use it to understand what the page is about.', 'ai-visibility-tracker' ),
			$desc ? '' : __( 'Add a description for this page in your SEO plugin.', 'ai-visibility-tracker' )
		);

		$canonical = preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html );
		$checks[]  = self::check(
			'canonical',
			__( 'Canonical URL', 'ai-visibility-tracker' ),
			$canonical ? 'pass' : 'warn',
			$canonical ? __( 'The page declares its main URL.', 'ai-visibility-tracker' ) : __( 'No canonical link found, so duplicate URLs may split your authority.', 'ai-visibility-tracker' ),
			$canonical ? '' : __( 'Most SEO plugins add canonical links automatically.', 'ai-visibility-tracker' )
		);

		$schema   = self::schema_types( $html );
		$checks[] = self::check(
			'schema',
			__( 'Structured data (schema)', 'ai-visibility-tracker' ),
			$schema ? 'pass' : 'warn',
			/* translators: %s: schema types */
			$schema ? sprintf( __( 'Found: %s', 'ai-visibility-tracker' ), implode( ', ', $schema ) ) : __( 'No JSON-LD structured data found. Schema tells AI exactly what your business, products and FAQs are.', 'ai-visibility-tracker' ),
			$schema ? '' : __( 'Add Organization or LocalBusiness schema (most SEO plugins can do this), plus Product or FAQ schema where relevant.', 'ai-visibility-tracker' )
		);

		$h1       = preg_match_all( '/<h1[\s>]/i', $html );
		$checks[] = self::check(
			'h1',
			__( 'One main heading (H1)', 'ai-visibility-tracker' ),
			1 === $h1 ? 'pass' : 'warn',
			/* translators: %d: number of H1 headings */
			sprintf( _n( 'The page has %d H1 heading.', 'The page has %d H1 headings.', $h1, 'ai-visibility-tracker' ), $h1 ),
			1 === $h1 ? '' : __( 'Use exactly one H1 that says what the page is about.', 'ai-visibility-tracker' )
		);

		$words    = self::visible_words( $html );
		$checks[] = self::check(
			'text',
			__( 'Readable without JavaScript', 'ai-visibility-tracker' ),
			$words >= 150 ? 'pass' : 'warn',
			/* translators: %d: number of words */
			sprintf( __( 'About %d words of text are in the page HTML.', 'ai-visibility-tracker' ), $words ),
			$words >= 150 ? '' : __( 'Most AI crawlers do not run JavaScript. Make sure your main content is in the HTML, not loaded by scripts.', 'ai-visibility-tracker' )
		);

		return array(
			'fetched' => true,
			'error'   => '',
			'checks'  => $checks,
			'schema'  => $schema,
		);
	}

	/**
	 * Schema.org @type values found in JSON-LD blocks.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function schema_types( $html ) {
		$types = array();
		if ( ! preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			return $types;
		}
		foreach ( $m[1] as $json ) {
			$data = json_decode( trim( $json ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			array_walk_recursive(
				$data,
				function ( $value, $key ) use ( &$types ) {
					if ( '@type' === $key && is_string( $value ) ) {
						$types[ $value ] = true;
					}
				}
			);
		}
		return array_slice( array_keys( $types ), 0, 12 );
	}

	/**
	 * Words of visible text in the HTML body.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	public static function visible_words( $html ) {
		$body = preg_match( '/<body[^>]*>(.*)<\/body>/is', $html, $m ) ? $m[1] : $html;
		$body = preg_replace( '/<(script|style|noscript|svg|template)[^>]*>.*?<\/\1>/is', ' ', $body );
		$text = html_entity_decode( wp_strip_all_tags( $body ) );
		return str_word_count( $text );
	}

	/* ---------------------------------------------------------------------
	 * robots.txt parsing (RFC 9309)
	 * ------------------------------------------------------------------ */

	/**
	 * Parse robots.txt into groups.
	 *
	 * @param string $content robots.txt.
	 * @return array List of [ agents => string[], rules => [ [ type, pattern, line ] ] ].
	 */
	public static function parse_robots( $content ) {
		$groups  = array();
		$current = null;
		$last    = '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $content ) as $raw ) {
			$line = trim( preg_replace( '/#.*$/', '', $raw ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                  = strtolower( $field );

			if ( 'user-agent' === $field ) {
				if ( 'user-agent' !== $last || null === $current ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current  = count( $groups ) - 1;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
			} elseif ( ( 'allow' === $field || 'disallow' === $field ) && null !== $current ) {
				$groups[ $current ]['rules'][] = array(
					'type'    => $field,
					'pattern' => $value,
					'line'    => trim( $raw ),
				);
			}
			$last = $field;
		}
		return $groups;
	}

	/**
	 * Whether a crawler may fetch a path.
	 *
	 * @param array  $groups Parsed groups.
	 * @param string $token  Crawler product token.
	 * @param string $path   Path.
	 * @return array allowed (bool), rule (the deciding line or '').
	 */
	public static function robots_verdict( array $groups, $token, $path ) {
		$token = strtolower( $token );
		$rules = array();
		foreach ( $groups as $g ) {
			if ( in_array( $token, $g['agents'], true ) ) {
				$rules = array_merge( $rules, $g['rules'] );
			}
		}
		if ( ! $rules ) {
			foreach ( $groups as $g ) {
				if ( in_array( '*', $g['agents'], true ) ) {
					$rules = array_merge( $rules, $g['rules'] );
				}
			}
		}

		$best     = null;
		$best_len = -1;
		foreach ( $rules as $r ) {
			if ( '' === $r['pattern'] ) {
				continue; // "Disallow:" with no value allows everything.
			}
			if ( ! self::robots_match( $r['pattern'], $path ) ) {
				continue;
			}
			$len = strlen( $r['pattern'] );
			if ( $len > $best_len || ( $len === $best_len && 'allow' === $r['type'] ) ) {
				$best     = $r;
				$best_len = $len;
			}
		}

		return array(
			'allowed' => null === $best || 'allow' === $best['type'],
			'rule'    => null === $best ? '' : $best['line'],
		);
	}

	/**
	 * robots.txt path pattern match with * and $ support.
	 *
	 * @param string $pattern Pattern.
	 * @param string $path    Path.
	 * @return bool
	 */
	private static function robots_match( $pattern, $path ) {
		$anchor = '$' === substr( $pattern, -1 );
		if ( $anchor ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchor ? '$' : '' ) . '#';
		return (bool) preg_match( $regex, $path );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * HTTP args for loopback requests.
	 *
	 * @return array
	 */
	private function http_args() {
		return array(
			'timeout'     => 15,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			'user-agent'  => 'Mozilla/5.0 (compatible; AIVT-Readiness/' . AIVT_VERSION . '; +' . home_url( '/' ) . ')',
		);
	}

	/**
	 * One check result.
	 *
	 * @param string $id     ID.
	 * @param string $label  Label.
	 * @param string $status pass|warn|fail|info.
	 * @param string $detail What we found.
	 * @param string $fix    How to fix.
	 * @param string $link   Optional link.
	 * @return array
	 */
	private static function check( $id, $label, $status, $detail, $fix, $link = '' ) {
		return compact( 'id', 'label', 'status', 'detail', 'fix', 'link' );
	}
}
