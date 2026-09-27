<?php
/**
 * Generates buyer-style prompts from the site's own content.
 *
 * @package AIVT
 */

namespace AIVT;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a site profile and asks an AI model for realistic prompts.
 */
class Prompt_Generator {

	/**
	 * Generate prompts and save them.
	 *
	 * @param int $count How many to generate.
	 * @return array|WP_Error New prompt rows.
	 */
	public function generate( $count = 10 ) {
		$provider = Engines::utility_provider();
		if ( ! $provider ) {
			return new WP_Error( 'aivt_no_engine', __( 'Connect at least one AI engine in Settings first.', 'ai-visibility-tracker' ) );
		}

		$repo     = new Repository();
		$existing = wp_list_pluck( $repo->prompts(), 'prompt' );
		$settings = Settings::all();

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'prompts' ),
			'properties'           => array(
				'prompts' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'prompt', 'topic', 'intent' ),
						'properties'           => array(
							'prompt' => array( 'type' => 'string' ),
							'topic'  => array( 'type' => 'string' ),
							'intent' => array(
								'type' => 'string',
								'enum' => array( 'best_of', 'comparison', 'alternatives', 'how_to', 'problem', 'local', 'pricing' ),
							),
						),
					),
				),
			),
		);

		$instructions = sprintf(
			'You help a business find out whether AI assistants (ChatGPT, Claude, Gemini, Perplexity, Grok) recommend it. '
			. 'Write %1$d realistic questions that a potential customer would type into an AI assistant when looking for what this business offers. '
			. "Rules:\n"
			. "- NEVER mention the business's own name, domain or product names. The questions must be ones where the AI could recommend this business OR a competitor.\n"
			. "- Write like a real person: natural, specific, 6-20 words.\n"
			. "- Mix intents: 'best X for Y' recommendations, comparisons, alternatives, how-to, problem-solving, pricing, and local questions if the business serves a location.\n"
			. "- Cover the business's main products/services, not just one.\n"
			. "- Give each question a short topic (2-4 words) naming the product/service area it is about, e.g. 'Wedding cakes'. Reuse the same topic names for related questions, and reuse existing topics where they fit.\n"
			. "- Write every question in %2\$s.\n"
			. '- Do not repeat or closely paraphrase any of the existing questions.',
			$count,
			$settings['language'] ? $settings['language'] : 'English'
		);

		$input  = $this->site_profile( $settings );
		$topics = $repo->topics();
		if ( $topics ) {
			$input .= "\n\nExisting topics: " . implode( '; ', $topics );
		}
		if ( $existing ) {
			$input .= "\n\nExisting questions (do not repeat):\n- " . implode( "\n- ", array_slice( $existing, 0, 100 ) );
		}

		$data = $provider->json( $instructions, $input, 'prompts', $schema );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['prompts'] ) || ! is_array( $data['prompts'] ) ) {
			return new WP_Error( 'aivt_no_prompts', __( 'The AI did not return any prompts. Please try again.', 'ai-visibility-tracker' ) );
		}

		$brand_terms = array_merge( Analyzer::brand_names( $settings ), (array) $settings['brand_domains'] );
		$added       = array();
		foreach ( array_slice( $data['prompts'], 0, $count ) as $p ) {
			$text = isset( $p['prompt'] ) ? (string) $p['prompt'] : '';
			// Branded questions would inflate visibility; skip any that slipped through.
			if ( '' === $text || false !== Analyzer::first_offset( $text, $brand_terms ) ) {
				continue;
			}
			$id = $repo->add_prompt( $text, isset( $p['intent'] ) ? $p['intent'] : '', 'generated', isset( $p['topic'] ) ? $p['topic'] : '' );
			if ( $id ) {
				$added[] = $repo->prompt( $id );
			}
		}
		return $added;
	}

	/**
	 * Suggest other names customers or AI might use for the brand.
	 *
	 * @return string[]|WP_Error
	 */
	public function suggest_brand_names() {
		$provider = Engines::utility_provider();
		if ( ! $provider ) {
			return new WP_Error( 'aivt_no_engine', __( 'Connect at least one AI engine in Settings first.', 'ai-visibility-tracker' ) );
		}
		$settings = Settings::all();
		$data     = $provider->json(
			'List other names that people or AI assistants might use when referring to this business: short forms, the name without legal suffixes, common misspellings or spacing variants, and flagship product names. '
			. 'Only include names that clearly refer to this specific business, not generic words for its category. Return at most 6.',
			$this->site_profile( $settings ),
			'brand_names',
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'names' ),
				'properties'           => array(
					'names' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$known = array_map( array( Analyzer::class, 'normalize_name' ), Analyzer::brand_names( $settings ) );
		$out   = array();
		foreach ( isset( $data['names'] ) ? (array) $data['names'] : array() as $name ) {
			$name = sanitize_text_field( (string) $name );
			$key  = Analyzer::normalize_name( $name );
			if ( '' !== $key && strlen( $key ) > 1 && ! in_array( $key, $known, true ) ) {
				$known[] = $key;
				$out[]   = $name;
			}
		}
		return array_slice( $out, 0, 6 );
	}

	/**
	 * Plain-text description of the business built from settings and site content.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private function site_profile( array $settings ) {
		$lines   = array();
		$lines[] = 'Business name: ' . $settings['brand_name'];
		$lines[] = 'Website: ' . home_url();
		if ( $settings['business'] ) {
			$lines[] = 'What the business does: ' . $settings['business'];
		}
		if ( $settings['location'] ) {
			$lines[] = 'Location / service area: ' . $settings['location'];
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
		if ( $pages ) {
			$lines[] = 'Pages: ' . implode( '; ', wp_list_pluck( $pages, 'post_title' ) );
		}

		$front = (int) get_option( 'page_on_front' );
		if ( $front ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $front ) ) ), 120, '' );
			if ( $excerpt ) {
				$lines[] = 'Homepage text: ' . $excerpt;
			}
		}

		if ( post_type_exists( 'product' ) ) {
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 30,
				)
			);
			if ( $products ) {
				$lines[] = 'Products: ' . implode( '; ', wp_list_pluck( $products, 'post_title' ) );
			}
			if ( taxonomy_exists( 'product_cat' ) ) {
				$cats = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'orderby'    => 'count',
						'order'      => 'DESC',
						'number'     => 20,
						'hide_empty' => true,
						'fields'     => 'names',
					)
				);
				if ( $cats && ! is_wp_error( $cats ) ) {
					$lines[] = 'Product categories: ' . implode( '; ', $cats );
				}
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
			)
		);
		if ( $posts ) {
			$lines[] = 'Recent blog posts: ' . implode( '; ', wp_list_pluck( $posts, 'post_title' ) );
		}

		$cats = get_terms(
			array(
				'taxonomy'   => 'category',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 15,
				'hide_empty' => true,
				'fields'     => 'names',
			)
		);
		if ( $cats && ! is_wp_error( $cats ) ) {
			$cats = array_diff( $cats, array( 'Uncategorized' ) );
			if ( $cats ) {
				$lines[] = 'Blog categories: ' . implode( '; ', $cats );
			}
		}

		return mb_substr( implode( "\n", $lines ), 0, 12000 );
	}
}
