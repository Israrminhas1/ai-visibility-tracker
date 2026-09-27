<?php
/**
 * Database access.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names come from $wpdb->prefix.

/**
 * All queries against the plugin's tables.
 */
class Repository {

	/**
	 * Visibility points per answer: a link to your site counts 1, a mention
	 * without a link counts 0.5. Summed, then divided by answers.
	 */
	const SCORE_SQL = 'SUM(CASE WHEN brand_cited = 1 THEN 1 WHEN brand_mentioned = 1 THEN 0.5 ELSE 0 END)';

	/**
	 * Table names.
	 *
	 * @var array<string,string>
	 */
	private $t;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->t = Installer::tables();
	}

	/**
	 * Current time in MySQL format (UTC).
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}

	/* ---------------------------------------------------------------------
	 * Prompts
	 * ------------------------------------------------------------------ */

	/**
	 * All prompts, newest last.
	 *
	 * @param bool $active_only Only active prompts.
	 * @return array
	 */
	public function prompts( $active_only = false ) {
		global $wpdb;
		$where = $active_only ? 'WHERE active = 1' : '';
		return $wpdb->get_results( "SELECT * FROM {$this->t['prompts']} $where ORDER BY id ASC", ARRAY_A );
	}

	/**
	 * One prompt.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public function prompt( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['prompts']} WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Add a prompt unless the same text already exists.
	 *
	 * @param string $text   Prompt text.
	 * @param string $intent Intent label.
	 * @param string $source 'manual' or 'generated'.
	 * @param string $topic  Topic name.
	 * @return int|false New ID, or false for a duplicate/empty prompt.
	 */
	public function add_prompt( $text, $intent = '', $source = 'manual', $topic = '' ) {
		global $wpdb;
		$text = trim( sanitize_textarea_field( $text ) );
		if ( '' === $text ) {
			return false;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t['prompts']} WHERE prompt = %s", $text ) );
		if ( $exists ) {
			return false;
		}
		$wpdb->insert(
			$this->t['prompts'],
			array(
				'prompt'     => $text,
				'intent'     => sanitize_key( $intent ),
				'topic'      => mb_substr( sanitize_text_field( $topic ), 0, 100 ),
				'source'     => sanitize_key( $source ),
				'active'     => 1,
				'created_at' => self::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a prompt's text and/or active flag.
	 *
	 * @param int   $id   ID.
	 * @param array $data Keys: prompt, active, topic.
	 */
	public function update_prompt( $id, array $data ) {
		global $wpdb;
		$row = array();
		if ( isset( $data['prompt'] ) ) {
			$row['prompt'] = trim( sanitize_textarea_field( $data['prompt'] ) );
		}
		if ( isset( $data['active'] ) ) {
			$row['active'] = $data['active'] ? 1 : 0;
		}
		if ( isset( $data['topic'] ) ) {
			$row['topic'] = mb_substr( sanitize_text_field( $data['topic'] ), 0, 100 );
		}
		if ( $row ) {
			$wpdb->update( $this->t['prompts'], $row, array( 'id' => (int) $id ) );
		}
	}

	/**
	 * Distinct topic names in use.
	 *
	 * @return string[]
	 */
	public function topics() {
		global $wpdb;
		return $wpdb->get_col( "SELECT DISTINCT topic FROM {$this->t['prompts']} WHERE topic <> '' ORDER BY topic ASC" );
	}

	/**
	 * Delete a prompt (past results keep a copy of the prompt text).
	 *
	 * @param int $id ID.
	 */
	public function delete_prompt( $id ) {
		global $wpdb;
		$wpdb->delete( $this->t['prompts'], array( 'id' => (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Runs
	 * ------------------------------------------------------------------ */

	/**
	 * Create a run with one pending result per prompt per engine.
	 *
	 * @param string   $trigger 'manual' or 'scheduled'.
	 * @param string[] $engines Engine IDs.
	 * @param array    $prompts Prompt rows.
	 * @return int Run ID.
	 */
	public function create_run( $trigger, array $engines, array $prompts ) {
		global $wpdb;
		$now = self::now();
		$wpdb->insert(
			$this->t['runs'],
			array(
				'status'       => 'running',
				'trigger_type' => $trigger,
				'engines'      => implode( ',', $engines ),
				'total'        => count( $engines ) * count( $prompts ),
				'created_at'   => $now,
			)
		);
		$run_id = (int) $wpdb->insert_id;

		foreach ( $prompts as $p ) {
			foreach ( $engines as $engine ) {
				$wpdb->insert(
					$this->t['results'],
					array(
						'run_id'     => $run_id,
						'prompt_id'  => (int) $p['id'],
						'engine'     => $engine,
						'model'      => Settings::engine( $engine )['model'],
						'prompt'     => $p['prompt'],
						'topic'      => (string) $p['topic'],
						'status'     => 'pending',
						'created_at' => $now,
					)
				);
			}
		}
		return $run_id;
	}

	/**
	 * One run.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public function run( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['runs']} WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Most recent runs.
	 *
	 * @param int    $limit  Max rows.
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public function runs( $limit = 20, $status = '' ) {
		global $wpdb;
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['runs']} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t['runs']} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * The run currently in progress, if any.
	 *
	 * @return array|null
	 */
	public function active_run() {
		$runs = $this->runs( 1, 'running' );
		return $runs ? $runs[0] : null;
	}

	/**
	 * Latest finished run.
	 *
	 * @return array|null
	 */
	public function latest_completed_run() {
		$runs = $this->runs( 1, 'completed' );
		return $runs ? $runs[0] : null;
	}

	/**
	 * Recalculate a run's counters and close it when nothing is left.
	 *
	 * @param int $run_id Run ID.
	 * @return array|null Updated run.
	 */
	public function refresh_run( $run_id ) {
		global $wpdb;
		$c = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(status = 'done') AS done,
					SUM(status = 'failed') AS failed,
					SUM(status IN ('pending','processing')) AS open_items,
					SUM(status = 'done' AND brand_mentioned = 1) AS mentioned,
					SUM(status = 'done' AND brand_cited = 1) AS cited
				FROM {$this->t['results']} WHERE run_id = %d",
				$run_id
			),
			ARRAY_A
		);

		$row = array(
			'done'      => (int) $c['done'],
			'failed'    => (int) $c['failed'],
			'mentioned' => (int) $c['mentioned'],
			'cited'     => (int) $c['cited'],
		);
		if ( 0 === (int) $c['open_items'] ) {
			$row['status']      = 'completed';
			$row['finished_at'] = self::now();
		}
		$wpdb->update( $this->t['runs'], $row, array( 'id' => (int) $run_id ) );
		return $this->run( $run_id );
	}

	/**
	 * Stop a run: remaining items are marked failed.
	 *
	 * @param int $run_id Run ID.
	 */
	public function cancel_run( $run_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->t['results']} SET status = 'failed', error = %s WHERE run_id = %d AND status IN ('pending','processing')", __( 'Cancelled.', 'ai-visibility-tracker' ), $run_id ) );
		$this->refresh_run( $run_id );
	}

	/* ---------------------------------------------------------------------
	 * Results
	 * ------------------------------------------------------------------ */

	/**
	 * Claim the next pending item so no other worker processes it.
	 * Items stuck in "processing" for 10+ minutes (a crashed request) are retried.
	 *
	 * @param int $run_id Run ID.
	 * @return array|null Claimed result row.
	 */
	public function claim_next( $run_id ) {
		global $wpdb;

		$stale = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->t['results']} SET status = 'pending' WHERE run_id = %d AND status = 'processing' AND completed_at < %s", $run_id, $stale ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t['results']} WHERE run_id = %d AND status = 'pending' ORDER BY attempts ASC, id ASC LIMIT 1", $run_id ) );
			if ( ! $id ) {
				return null;
			}
			// completed_at doubles as the "claimed at" time while processing.
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['results']} SET status = 'processing', attempts = attempts + 1, completed_at = %s WHERE id = %d AND status = 'pending'", self::now(), $id ) );
			if ( $claimed ) {
				return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['results']} WHERE id = %d", $id ), ARRAY_A );
			}
		}
		return null;
	}

	/**
	 * Save a successful answer with its citations and mentions.
	 *
	 * @param array $result   Result row.
	 * @param array $answer   Provider answer.
	 * @param array $analysis Analyzer output.
	 */
	public function complete_result( array $result, array $answer, array $analysis ) {
		global $wpdb;
		$id = (int) $result['id'];

		$wpdb->update(
			$this->t['results'],
			array(
				'status'          => 'done',
				'response'        => $answer['text'],
				'brand_mentioned' => $analysis['brand_mentioned'] ? 1 : 0,
				'brand_cited'     => $analysis['brand_cited'] ? 1 : 0,
				'brand_position'  => $analysis['brand_position'],
				'input_tokens'    => (int) $answer['input_tokens'],
				'output_tokens'   => (int) $answer['output_tokens'],
				'error'           => null,
				'completed_at'    => self::now(),
			),
			array( 'id' => $id )
		);

		foreach ( $answer['citations'] as $c ) {
			$wpdb->insert(
				$this->t['citations'],
				array(
					'result_id' => $id,
					'run_id'    => (int) $result['run_id'],
					'engine'    => $result['engine'],
					'url'       => $c['url'],
					'domain'    => Analyzer::registrable_domain( $c['url'] ),
					'title'     => $c['title'],
					'post_id'   => self::local_post_id( $c['url'] ),
				)
			);
		}
		foreach ( $analysis['mentions'] as $m ) {
			$wpdb->insert(
				$this->t['mentions'],
				array(
					'result_id' => $id,
					'run_id'    => (int) $result['run_id'],
					'engine'    => $result['engine'],
					'name'      => mb_substr( $m['name'], 0, 190 ),
					'domain'    => $m['domain'],
					'position'  => (int) $m['position'],
					'is_brand'  => (int) $m['is_brand'],
				)
			);
		}
	}

	/**
	 * The WordPress post a cited URL points to, if it is on one of the brand's domains.
	 *
	 * @param string $url Cited URL.
	 * @return int Post ID or 0.
	 */
	public static function local_post_id( $url ) {
		if ( ! Analyzer::domain_matches( Analyzer::normalize_domain( $url ), (array) Settings::get( 'brand_domains' ) ) ) {
			return 0;
		}
		// Rebuild the URL on this site's own host (the brand domain may differ, e.g. staging).
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === trim( $path, '/' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return (int) url_to_postid( home_url( $path ) );
	}

	/**
	 * Record a failure. The item is retried until it has had $max attempts.
	 *
	 * @param array  $result Result row.
	 * @param string $error  Message.
	 * @param int    $max    Max attempts.
	 */
	public function fail_result( array $result, $error, $max = 2 ) {
		global $wpdb;
		$wpdb->update(
			$this->t['results'],
			array(
				'status'       => (int) $result['attempts'] >= $max ? 'failed' : 'pending',
				'error'        => mb_substr( (string) $error, 0, 1000 ),
				'completed_at' => self::now(),
			),
			array( 'id' => (int) $result['id'] )
		);
	}

	/**
	 * Results of a run with optional filters.
	 *
	 * @param int   $run_id  Run ID.
	 * @param array $filters Keys: engine, mentioned ('yes'|'no'), prompt_id.
	 * @return array
	 */
	public function results( $run_id, array $filters = array() ) {
		global $wpdb;
		$sql  = "SELECT id, run_id, prompt_id, engine, model, topic, prompt, status, brand_mentioned, brand_cited, brand_position, error, completed_at FROM {$this->t['results']} WHERE run_id = %d";
		$args = array( $run_id );
		if ( ! empty( $filters['engine'] ) ) {
			$sql   .= ' AND engine = %s';
			$args[] = $filters['engine'];
		}
		if ( isset( $filters['topic'] ) && '' !== $filters['topic'] ) {
			$sql   .= ' AND topic = %s';
			$args[] = '-' === $filters['topic'] ? '' : $filters['topic']; // "-" means "no topic".
		}
		if ( ! empty( $filters['prompt_id'] ) ) {
			$sql   .= ' AND prompt_id = %d';
			$args[] = (int) $filters['prompt_id'];
		}
		if ( isset( $filters['mentioned'] ) && in_array( $filters['mentioned'], array( 'yes', 'no' ), true ) ) {
			$sql   .= " AND status = 'done' AND brand_mentioned = %d";
			$args[] = 'yes' === $filters['mentioned'] ? 1 : 0;
		}
		$sql .= ' ORDER BY prompt_id ASC, id ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A result with its citations and mentions.
	 *
	 * @param int $id Result ID.
	 * @return array|null
	 */
	public function result( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t['results']} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['citations'] = $wpdb->get_results( $wpdb->prepare( "SELECT url, domain, title, post_id FROM {$this->t['citations']} WHERE result_id = %d ORDER BY id ASC", $id ), ARRAY_A );
		$row['mentions']  = $wpdb->get_results( $wpdb->prepare( "SELECT name, domain, position, is_brand FROM {$this->t['mentions']} WHERE result_id = %d ORDER BY position ASC", $id ), ARRAY_A );
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Reporting
	 * ------------------------------------------------------------------ */

	/**
	 * Visibility numbers per engine for a run.
	 *
	 * @param int $run_id Run ID.
	 * @return array Keyed by engine: done, mentioned, cited, avg_position.
	 */
	public function engine_stats( $run_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT engine,
					COUNT(*) AS done,
					' . self::SCORE_SQL . " AS points,
					SUM(brand_mentioned) AS mentioned,
					SUM(brand_cited) AS cited,
					AVG(brand_position) AS avg_position
				FROM {$this->t['results']} WHERE run_id = %d AND status = 'done' GROUP BY engine",
				$run_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['engine'] ] = array(
				'done'         => (int) $r['done'],
				'score'        => $r['done'] ? (int) round( 100 * $r['points'] / $r['done'] ) : 0,
				'mentioned'    => (int) $r['mentioned'],
				'cited'        => (int) $r['cited'],
				'avg_position' => null === $r['avg_position'] ? null : round( (float) $r['avg_position'], 1 ),
			);
		}
		return $out;
	}

	/**
	 * Brands mentioned in a run (other than the user's), by number of answers.
	 *
	 * @param int $run_id Run ID.
	 * @param int $limit  Max rows.
	 * @return array Rows: name, domain, answers, avg_position.
	 */
	public function top_brands( $run_id, $limit = 15 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, MAX(domain) AS domain, COUNT(DISTINCT result_id) AS answers, ROUND(AVG(position), 1) AS avg_position
				FROM {$this->t['mentions']} WHERE run_id = %d AND is_brand = 0
				GROUP BY name ORDER BY answers DESC, avg_position ASC LIMIT %d",
				$run_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Most cited domains in a run.
	 *
	 * @param int $run_id Run ID.
	 * @param int $limit  Max rows.
	 * @return array Rows: domain, answers, citations.
	 */
	public function top_domains( $run_id, $limit = 15 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain, COUNT(DISTINCT result_id) AS answers, COUNT(*) AS citations
				FROM {$this->t['citations']} WHERE run_id = %d AND domain <> ''
				GROUP BY domain ORDER BY answers DESC, citations DESC LIMIT %d",
				$run_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Visibility over the last completed runs, oldest first.
	 *
	 * @param int $limit Number of runs.
	 * @return array Rows: id, created_at, done, mentioned, engines => [engine => [done, mentioned]].
	 */
	public function trend( $limit = 12 ) {
		global $wpdb;
		$runs = array_reverse( $this->runs( $limit, 'completed' ) );
		if ( ! $runs ) {
			return array();
		}
		$ids  = array_map( 'intval', wp_list_pluck( $runs, 'id' ) );
		$rows = $wpdb->get_results(
			'SELECT run_id, engine, COUNT(*) AS done, SUM(brand_mentioned) AS mentioned, ' . self::SCORE_SQL . " AS points
			FROM {$this->t['results']} WHERE status = 'done' AND run_id IN (" . implode( ',', $ids ) . ')
			GROUP BY run_id, engine',
			ARRAY_A
		);
		$by_run = array();
		foreach ( $rows as $r ) {
			$by_run[ $r['run_id'] ][ $r['engine'] ] = array(
				'done'      => (int) $r['done'],
				'mentioned' => (int) $r['mentioned'],
				'points'    => (float) $r['points'],
			);
		}
		foreach ( $runs as &$run ) {
			$run['by_engine'] = isset( $by_run[ $run['id'] ] ) ? $by_run[ $run['id'] ] : array();
		}
		return $runs;
	}

	/**
	 * Overall visibility score (0-100) of a run.
	 *
	 * @param int $run_id Run ID.
	 * @return int|null Null when nothing was answered.
	 */
	public function run_score( $run_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS done, ' . self::SCORE_SQL . " AS points FROM {$this->t['results']} WHERE run_id = %d AND status = 'done'", $run_id ), ARRAY_A );
		return $r && (int) $r['done'] ? (int) round( 100 * $r['points'] / $r['done'] ) : null;
	}

	/**
	 * Visibility per topic for a run.
	 *
	 * @param int $run_id Run ID.
	 * @return array Rows: topic, done, prompts, mentioned, score.
	 */
	public function topic_stats( $run_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT topic, COUNT(*) AS done, COUNT(DISTINCT prompt_id) AS prompts, SUM(brand_mentioned) AS mentioned, ' . self::SCORE_SQL . " AS points
				FROM {$this->t['results']} WHERE run_id = %d AND status = 'done'
				GROUP BY topic ORDER BY topic ASC",
				$run_id
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['score'] = $r['done'] ? (int) round( 100 * $r['points'] / $r['done'] ) : 0;
		}
		return $rows;
	}

	/**
	 * The user's own pages cited in a run, most cited first.
	 *
	 * @param int $run_id Run ID.
	 * @param int $limit  Max rows.
	 * @return array Rows: post_id, url, answers, engines.
	 */
	public function cited_pages( $run_id, $limit = 10 ) {
		global $wpdb;
		$domains = (array) Settings::get( 'brand_domains' );
		if ( ! $domains ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT result_id, engine, url, domain, post_id FROM {$this->t['citations']} WHERE run_id = %d", $run_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( ! Analyzer::domain_matches( $r['domain'], $domains ) ) {
				continue;
			}
			$url = preg_replace( '/[?#].*$/', '', $r['url'] );
			$key = $r['post_id'] ? 'p' . $r['post_id'] : $url;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'post_id' => (int) $r['post_id'],
					'url'     => $url,
					'answers' => array(),
					'engines' => array(),
				);
			}
			$out[ $key ]['answers'][ $r['result_id'] ] = true;
			$out[ $key ]['engines'][ $r['engine'] ]    = true;
		}
		foreach ( $out as &$o ) {
			$o['answers'] = count( $o['answers'] );
			$o['engines'] = array_keys( $o['engines'] );
		}
		unset( $o );
		$out = array_values( $out );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['answers'] <=> $a['answers'];
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Prompts where AI answered without mentioning the brand, with who it
	 * recommended instead and which sources it relied on.
	 *
	 * @param int $run_id Run ID.
	 * @return array Rows sorted by how often the brand was missed.
	 */
	public function opportunities( $run_id ) {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT id, prompt_id, prompt, topic, engine, brand_mentioned FROM {$this->t['results']} WHERE run_id = %d AND status = 'done'", $run_id ), ARRAY_A );
		if ( ! $results ) {
			return array();
		}
		$mentions  = $wpdb->get_results( $wpdb->prepare( "SELECT result_id, name FROM {$this->t['mentions']} WHERE run_id = %d AND is_brand = 0", $run_id ), ARRAY_A );
		$citations = $wpdb->get_results( $wpdb->prepare( "SELECT result_id, domain FROM {$this->t['citations']} WHERE run_id = %d AND domain <> ''", $run_id ), ARRAY_A );

		$brands_by_result = array();
		foreach ( $mentions as $m ) {
			$brands_by_result[ $m['result_id'] ][] = $m['name'];
		}
		$domains_by_result = array();
		foreach ( $citations as $c ) {
			$domains_by_result[ $c['result_id'] ][ $c['domain'] ] = true;
		}

		$prompts = array();
		foreach ( $results as $r ) {
			$pid = $r['prompt_id'];
			if ( ! isset( $prompts[ $pid ] ) ) {
				$prompts[ $pid ] = array(
					'prompt_id' => (int) $pid,
					'prompt'    => $r['prompt'],
					'topic'     => $r['topic'],
					'answers'   => 0,
					'missed'    => array(),
					'found'     => array(),
					'brands'    => array(),
					'domains'   => array(),
				);
			}
			++$prompts[ $pid ]['answers'];
			if ( $r['brand_mentioned'] ) {
				$prompts[ $pid ]['found'][] = $r['engine'];
				continue;
			}
			$prompts[ $pid ]['missed'][ $r['id'] ] = $r['engine'];
			$names                                 = isset( $brands_by_result[ $r['id'] ] ) ? $brands_by_result[ $r['id'] ] : array();
			foreach ( $names as $name ) {
				$prompts[ $pid ]['brands'][ $name ] = isset( $prompts[ $pid ]['brands'][ $name ] ) ? $prompts[ $pid ]['brands'][ $name ] + 1 : 1;
			}
			$domains = isset( $domains_by_result[ $r['id'] ] ) ? array_keys( $domains_by_result[ $r['id'] ] ) : array();
			foreach ( $domains as $d ) {
				$prompts[ $pid ]['domains'][ $d ] = isset( $prompts[ $pid ]['domains'][ $d ] ) ? $prompts[ $pid ]['domains'][ $d ] + 1 : 1;
			}
		}

		$out = array();
		foreach ( $prompts as $p ) {
			if ( ! $p['missed'] ) {
				continue;
			}
			arsort( $p['brands'] );
			arsort( $p['domains'] );
			// Missed on every engine while competitors are named = most urgent.
			$p['severity'] = (int) round( 100 * count( $p['missed'] ) / $p['answers'] * ( $p['brands'] ? 1 : 0.6 ) );
			$out[]         = $p;
		}
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['severity'] !== $b['severity'] ) {
					return $b['severity'] <=> $a['severity'];
				}
				return count( $b['brands'] ) <=> count( $a['brands'] );
			}
		);
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * AI traffic
	 * ------------------------------------------------------------------ */

	/**
	 * Add a hit for today's day/kind/source/path, creating the row if needed.
	 *
	 * @param string $kind    'referral' or 'bot'.
	 * @param string $source  Source key, e.g. chatgpt or GPTBot.
	 * @param string $path    Landing path.
	 * @param int    $post_id Post ID or 0.
	 */
	public function log_traffic( $kind, $source, $path, $post_id ) {
		global $wpdb;
		$day     = current_time( 'Y-m-d' );
		$path    = mb_substr( $path, 0, 190 );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->t['traffic']} SET hits = hits + 1 WHERE day = %s AND kind = %s AND source = %s AND path = %s", $day, $kind, $source, $path ) );
		if ( ! $updated ) {
			$wpdb->insert(
				$this->t['traffic'],
				array(
					'day'     => $day,
					'kind'    => $kind,
					'source'  => $source,
					'path'    => $path,
					'post_id' => (int) $post_id,
					'hits'    => 1,
				)
			);
		}
	}

	/**
	 * Hits per day for a kind over a date range (inclusive).
	 *
	 * @param string $kind 'referral' or 'bot'.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array day => hits.
	 */
	public function traffic_by_day( $kind, $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(hits) AS hits FROM {$this->t['traffic']} WHERE kind = %s AND day BETWEEN %s AND %s GROUP BY day", $kind, $from, $to ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ substr( $r['day'], 0, 10 ) ] = (int) $r['hits'];
		}
		return $out;
	}

	/**
	 * Total hits for a kind over a date range.
	 *
	 * @param string $kind 'referral' or 'bot'.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	public function traffic_total( $kind, $from, $to ) {
		return (int) array_sum( $this->traffic_by_day( $kind, $from, $to ) );
	}

	/**
	 * Hits grouped by source or landing path for a kind over a date range.
	 *
	 * @param string $kind  'referral' or 'bot'.
	 * @param string $by    'source' or 'path'.
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param int    $limit Max rows.
	 * @return array Rows: k, post_id, hits, last_day.
	 */
	public function traffic_by( $kind, $by, $from, $to, $limit = 20 ) {
		global $wpdb;
		$col = 'path' === $by ? 'path' : 'source';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $col AS k, MAX(post_id) AS post_id, SUM(hits) AS hits, MAX(day) AS last_day
				FROM {$this->t['traffic']} WHERE kind = %s AND day BETWEEN %s AND %s
				GROUP BY $col ORDER BY hits DESC LIMIT %d",
				$kind,
				$from,
				$to,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Delete traffic rows older than $days.
	 *
	 * @param int $days Days to keep.
	 */
	public function prune_traffic( $days = 400 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->t['traffic']} WHERE day < %s", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Delete runs (and their results) beyond the newest $keep.
	 *
	 * @param int $keep Runs to keep.
	 */
	public function prune( $keep = 52 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->t['runs']} ORDER BY id DESC LIMIT 1000000 OFFSET %d", $keep ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		foreach ( array( 'citations', 'mentions', 'results' ) as $table ) {
			$wpdb->query( "DELETE FROM {$this->t[ $table ]} WHERE run_id IN ($in)" );
		}
		$wpdb->query( "DELETE FROM {$this->t['runs']} WHERE id IN ($in)" );
	}
}
