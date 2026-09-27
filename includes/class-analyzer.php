<?php
/**
 * Brand, competitor and source detection.
 *
 * @package AIVT
 */

namespace AIVT;

use AIVT\Providers\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an AI answer into mentions and a visibility verdict.
 */
class Analyzer {

	/**
	 * AI provider used for brand extraction, or null to skip extraction.
	 *
	 * @var Provider|null
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param Provider|null $provider Provider.
	 */
	public function __construct( ?Provider $provider = null ) {
		$this->provider = $provider;
	}

	/**
	 * Analyze an answer.
	 *
	 * @param string $text      Answer text.
	 * @param array  $citations List of [ url, title ].
	 * @param array  $settings  Plugin settings.
	 * @return array {
	 *     @type bool     $brand_mentioned Brand named in the answer or cited.
	 *     @type bool     $brand_cited     One of the brand's domains is a cited source.
	 *     @type int|null $brand_position  1-based rank among brands in the answer.
	 *     @type array    $mentions        Ordered list of [ name, domain, position, is_brand ].
	 * }
	 */
	public function analyze( $text, array $citations, array $settings ) {
		$brand_names   = self::brand_names( $settings );
		$brand_domains = (array) $settings['brand_domains'];

		$brand_cited = false;
		foreach ( $citations as $c ) {
			if ( self::domain_matches( self::normalize_domain( $c['url'] ), $brand_domains ) ) {
				$brand_cited = true;
				break;
			}
		}

		// Where each brand first appears in the text decides its rank.
		$found = array();

		$brand_offset = self::first_offset( $text, array_merge( $brand_names, $brand_domains ) );
		if ( false !== $brand_offset ) {
			$found[] = array(
				'name'     => (string) $settings['brand_name'],
				'domain'   => isset( $brand_domains[0] ) ? $brand_domains[0] : '',
				'offset'   => $brand_offset,
				'is_brand' => 1,
			);
		}

		$seen = array_map( array( __CLASS__, 'normalize_name' ), $brand_names );
		foreach ( $this->extract_brands( $text ) as $b ) {
			$key = self::normalize_name( $b['name'] );
			if ( '' === $key || in_array( $key, $seen, true ) ) {
				continue;
			}
			$domain = self::registrable_domain( $b['domain'] );
			if ( '' !== $domain && self::domain_matches( $domain, $brand_domains ) ) {
				continue; // It's the user's own brand under another name.
			}
			if ( self::is_platform( $domain ) || self::is_platform_name( $b['name'] ) ) {
				continue; // Reddit, Wikipedia, YouTube... are places, not competitors.
			}
			$seen[] = $key;
			$offset = self::first_offset( $text, array( $b['name'] ) );
			$found[] = array(
				'name'     => sanitize_text_field( $b['name'] ),
				'domain'   => $domain,
				'offset'   => false === $offset ? PHP_INT_MAX : $offset,
				'is_brand' => 0,
			);
		}

		usort(
			$found,
			function ( $a, $b ) {
				return $a['offset'] <=> $b['offset'];
			}
		);

		$mentions       = array();
		$brand_position = null;
		foreach ( $found as $i => $m ) {
			$mentions[] = array(
				'name'     => $m['name'],
				'domain'   => $m['domain'],
				'position' => $i + 1,
				'is_brand' => $m['is_brand'],
			);
			if ( $m['is_brand'] ) {
				$brand_position = $i + 1;
			}
		}

		return array(
			'brand_mentioned' => false !== $brand_offset || $brand_cited,
			'brand_cited'     => $brand_cited,
			'brand_position'  => $brand_position,
			'mentions'        => $mentions,
		);
	}

	/**
	 * Ask the model which companies/products the answer names.
	 * A failure here is not fatal: we still know about the user's own brand.
	 *
	 * @param string $text Answer text.
	 * @return array List of [ name, domain ].
	 */
	private function extract_brands( $text ) {
		if ( null === $this->provider ) {
			return array();
		}
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'brands' ),
			'properties'           => array(
				'brands' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'name', 'domain' ),
						'properties'           => array(
							'name'   => array( 'type' => 'string' ),
							'domain' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);

		$data = $this->provider->json(
			'You extract brand mentions from text. List every company, product, service or brand that the text recommends or names as an option, in order of first appearance. '
			. 'Use the name exactly as written in the text. Set domain to the brand\'s official website domain (e.g. "example.com") only if you are confident, otherwise an empty string. '
			. 'Do NOT include publishers or websites that are only referenced as sources (news sites, review sites, Reddit, Wikipedia) unless the text recommends them as an option.',
			$text,
			'brand_mentions',
			$schema
		);

		if ( is_wp_error( $data ) || empty( $data['brands'] ) || ! is_array( $data['brands'] ) ) {
			return array();
		}
		return array_slice( $data['brands'], 0, 30 );
	}

	/**
	 * Brand name plus aliases, non-empty.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function brand_names( array $settings ) {
		$names = array_merge( array( (string) $settings['brand_name'] ), (array) $settings['brand_aliases'] );
		return array_values( array_filter( array_map( 'trim', $names ) ) );
	}

	/**
	 * Earliest case-insensitive, whole-word position of any needle in the text.
	 *
	 * @param string   $text    Haystack.
	 * @param string[] $needles Terms.
	 * @return int|false
	 */
	public static function first_offset( $text, array $needles ) {
		$best = false;
		foreach ( $needles as $needle ) {
			$needle = trim( (string) $needle );
			if ( '' === $needle ) {
				continue;
			}
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu';
			if ( preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				if ( false === $best || $m[0][1] < $best ) {
					$best = $m[0][1];
				}
			}
		}
		return $best;
	}

	/**
	 * Lowercase host without "www.", from a URL or bare domain.
	 *
	 * @param string $url URL or domain.
	 * @return string
	 */
	public static function normalize_domain( $url ) {
		$url = trim( strtolower( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '://' ) ) {
			$url = 'http://' . $url;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Whether a domain equals, or is a subdomain of, any listed domain.
	 *
	 * @param string   $domain  Domain to test.
	 * @param string[] $domains Domains to match.
	 * @return bool
	 */
	public static function domain_matches( $domain, array $domains ) {
		if ( '' === $domain ) {
			return false;
		}
		foreach ( $domains as $d ) {
			$d = self::normalize_domain( $d );
			if ( '' !== $d && ( $domain === $d || substr( $domain, -strlen( '.' . $d ) ) === '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Comparable form of a brand name: "Acme, Inc." => "acme".
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function normalize_name( $name ) {
		$name = strtolower( remove_accents( (string) $name ) );
		$name = preg_replace( '/\b(inc|llc|ltd|limited|gmbh|co|corp|corporation|plc|sa|bv|ag)\b\.?/', '', $name );
		return preg_replace( '/[^a-z0-9]/', '', $name );
	}

	/**
	 * Suffixes that take three labels for the registrable domain (example.co.uk).
	 */
	const MULTI_PART_SUFFIXES = array(
		'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'ac.uk', 'gov.uk', 'net.uk',
		'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
		'co.nz', 'org.nz', 'net.nz', 'co.za', 'org.za', 'co.in', 'net.in', 'org.in',
		'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'com.br', 'net.br', 'org.br', 'com.mx', 'com.ar',
		'com.tr', 'com.sg', 'com.my', 'com.hk', 'com.tw', 'com.cn', 'com.pk', 'com.ng',
		'co.il', 'com.co', 'com.pe', 'com.ph', 'com.vn', 'com.ua', 'com.pl', 'co.at', 'or.at', 'co.id',
	);

	/**
	 * Social and community sites.
	 */
	const SOCIAL = array(
		'reddit.com', 'youtube.com', 'youtu.be', 'facebook.com', 'instagram.com', 'x.com', 'twitter.com',
		'linkedin.com', 'tiktok.com', 'pinterest.com', 'quora.com', 'medium.com', 'threads.net',
		'tumblr.com', 'substack.com', 'discord.com', 'stackexchange.com', 'stackoverflow.com',
	);

	/**
	 * Big platforms that AI cites as sources but that are not competitors.
	 */
	const PLATFORMS = array(
		'wikipedia.org', 'wikimedia.org', 'wikihow.com', 'google.com', 'bing.com', 'apple.com',
		'amazon.com', 'ebay.com', 'yelp.com', 'tripadvisor.com', 'trustpilot.com', 'g2.com',
		'capterra.com', 'github.com', 'forbes.com', 'nytimes.com', 'bbc.co.uk', 'bbc.com',
		'cnn.com', 'theguardian.com', 'businessinsider.com', 'investopedia.com', 'healthline.com',
		'webmd.com', 'mayoclinic.org', 'nih.gov', 'yellowpages.com', 'bbb.org', 'glassdoor.com',
		'indeed.com', 'openai.com', 'chatgpt.com', 'perplexity.ai', 'anthropic.com', 'claude.ai',
	);

	/**
	 * Registrable domain: "blog.shop.example.co.uk" => "example.co.uk".
	 *
	 * @param string $url URL or host.
	 * @return string
	 */
	public static function registrable_domain( $url ) {
		$host = self::normalize_domain( $url );
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$parts = explode( '.', $host );
		$n     = count( $parts );
		if ( $n <= 2 ) {
			return $host;
		}
		$last_two = $parts[ $n - 2 ] . '.' . $parts[ $n - 1 ];
		$take     = in_array( $last_two, self::MULTI_PART_SUFFIXES, true ) ? 3 : 2;
		return implode( '.', array_slice( $parts, -$take ) );
	}

	/**
	 * Whether a domain is a social/community site.
	 *
	 * @param string $domain Domain.
	 * @return bool
	 */
	public static function is_social( $domain ) {
		return self::domain_matches( self::registrable_domain( $domain ), self::SOCIAL );
	}

	/**
	 * Whether a domain is a social site or big platform (never a competitor).
	 *
	 * @param string $domain Domain.
	 * @return bool
	 */
	public static function is_platform( $domain ) {
		$d = self::registrable_domain( $domain );
		return '' !== $d && ( self::domain_matches( $d, self::SOCIAL ) || self::domain_matches( $d, self::PLATFORMS ) );
	}

	/**
	 * Whether a brand name is really a platform ("Reddit", "Wikipedia", "Yelp").
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_platform_name( $name ) {
		$key = self::normalize_name( $name );
		foreach ( array_merge( self::SOCIAL, self::PLATFORMS ) as $d ) {
			if ( self::normalize_name( strtok( $d, '.' ) ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classify a cited domain.
	 *
	 * @param string $domain   Domain.
	 * @param array  $settings Settings.
	 * @return string brand|competitor|social|source
	 */
	public static function source_type( $domain, array $settings ) {
		if ( self::domain_matches( $domain, (array) $settings['brand_domains'] ) ) {
			return 'brand';
		}
		$competitors = array_filter( wp_list_pluck( (array) $settings['competitors'], 'domain' ) );
		if ( self::domain_matches( $domain, $competitors ) ) {
			return 'competitor';
		}
		return self::is_social( $domain ) ? 'social' : 'source';
	}
}
