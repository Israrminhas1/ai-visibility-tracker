<?php
/**
 * AI provider contract.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Every answer engine (OpenAI today, others later) implements this.
 */
interface Provider {

	/**
	 * Check the API key and model work.
	 *
	 * @return true|\WP_Error
	 */
	public function test();

	/**
	 * Ask a question the way a user would, with web search enabled.
	 *
	 * @param string $prompt The question.
	 * @return array|\WP_Error {
	 *     @type string $text          Answer text.
	 *     @type array  $citations     List of [ 'url' => string, 'title' => string ].
	 *     @type int    $input_tokens  Tokens in.
	 *     @type int    $output_tokens Tokens out.
	 * }
	 */
	public function ask( $prompt );

	/**
	 * Get structured JSON back that matches a JSON schema.
	 *
	 * @param string $instructions System instructions.
	 * @param string $input        User input.
	 * @param string $name         Schema name.
	 * @param array  $schema       JSON schema (object).
	 * @return array|\WP_Error Decoded JSON.
	 */
	public function json( $instructions, $input, $name, array $schema );
}
