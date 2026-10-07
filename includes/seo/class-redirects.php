<?php
/**
 * Redirects (301, 302, 307, 410 and 451) and a 404 monitor. Both work with or
 * without an SEO plugin, so an AI can find broken URLs and fix them.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\SEO;

use ViagentProduction\Installer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Viagent Production's own tables; redirects are looked up by an indexed column.

/**
 * Redirects and 404 log.
 */
class Redirects {

	const TYPES              = array( 301, 302, 307, 410, 451 );
	const MATCH_TYPES        = array( 'exact', 'regex' );
	const MAX_404_ROW        = 2000;
	const MAX_404_PER_IP     = 20;
	const MAX_PATTERN_LENGTH = 200;
	const COUNT_TRANSIENT    = 'viagent_production_404_count';
	const REGEX_TRANSIENT    = 'viagent_production_regex_redirects';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( self::class, 'handle' ), 0 );
		// After WordPress's own redirect guessing (redirect_canonical), so URLs it fixes are not logged.
		add_action( 'template_redirect', array( self::class, 'maybe_log_404' ), 1000 );
		add_action( \ViagentProduction\Log\Activity_Log::PRUNE_HOOK, array( self::class, 'prune' ) );
	}

	/**
	 * Normalizes a request path for matching: lowercase, leading slash, no
	 * trailing slash, query string kept.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function normalize( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		$path  = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $home && 0 === strpos( $path, untrailingslashit( $home ) . '/' ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home ) ) );
		}
		$path = '/' === $path ? '/' : untrailingslashit( $path );
		$path = strtolower( rawurldecode( $path ) );
		return isset( $parts['query'] ) && '' !== $parts['query'] ? $path . '?' . $parts['query'] : $path;
	}

	/**
	 * Normalized path (with query string) of the current request.
	 *
	 * Passing the URI through sanitize_text_field() would strip %XX sequences
	 * and turn /caf%C3%A9 into /caf, so the raw URI is parsed and decoded
	 * instead. The result is
	 * only matched against saved rules and written through prepared
	 * statements; it is never output.
	 *
	 * @return string
	 */
	private static function request_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, decoded and UTF-8 checked by normalize()/wp_check_invalid_utf8(); matched and prepared only, never output.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$full  = self::normalize( '' !== $query ? $path . '?' . $query : $path );
		return wp_check_invalid_utf8( $full );
	}

	/**
	 * Compiles a saved regex source into a pattern. Patterns containing the
	 * delimiter are rejected on save, so no escaping is needed here.
	 *
	 * @param string $source Regex source.
	 * @return string
	 */
	private static function pattern( $source ) {
		return '~' . $source . '~i';
	}

	/**
	 * Checks that a regex source is safe to run against every request.
	 *
	 * @param string $source Regex source.
	 * @return true|WP_Error
	 */
	private static function validate_regex( $source ) {
		if ( '' === $source ) {
			return new WP_Error( 'viagent_production_invalid_regex', __( 'The source pattern cannot be empty.', 'viagent-production' ) );
		}
		if ( strlen( $source ) > self::MAX_PATTERN_LENGTH ) {
			/* translators: %d: maximum length */
			return new WP_Error( 'viagent_production_invalid_regex', sprintf( __( 'The source pattern must be %d characters or shorter.', 'viagent-production' ), self::MAX_PATTERN_LENGTH ) );
		}
		if ( false !== strpos( $source, '~' ) ) {
			return new WP_Error( 'viagent_production_invalid_regex', __( 'The source pattern cannot contain the "~" character.', 'viagent-production' ) );
		}

		// Run it against a long synthetic path with a low backtrack limit to catch catastrophic patterns.
		$previous = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '100000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- lowered only for this check and restored right after.
		$start  = microtime( true );
		$result = @preg_match( self::pattern( $source ), '/' . str_repeat( 'a', 5000 ) . '!' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing whether the pattern compiles.
		$error  = preg_last_error();
		$took   = microtime( true ) - $start;
		ini_set( 'pcre.backtrack_limit', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the previous value.

		if ( in_array( $error, array( PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR ), true ) || $took > 0.05 ) {
			return new WP_Error( 'viagent_production_invalid_regex', __( 'The source pattern is too slow to run on every request. Simplify it (avoid nested repetitions such as (a+)+).', 'viagent-production' ) );
		}
		if ( false === $result || PREG_NO_ERROR !== $error ) {
			return new WP_Error( 'viagent_production_invalid_regex', __( 'The source is not a valid regular expression.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Checks the redirect type and match type.
	 *
	 * @param int    $type       HTTP status.
	 * @param string $match_type Match type.
	 * @return true|WP_Error
	 */
	private static function validate_kind( $type, $match_type ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'viagent_production_invalid_type', __( 'The redirect type must be 301, 302, 307, 410 or 451.', 'viagent-production' ) );
		}
		if ( ! in_array( $match_type, self::MATCH_TYPES, true ) ) {
			return new WP_Error( 'viagent_production_invalid_match_type', __( 'The match type must be "exact" or "regex".', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Checks that a target is a path or an http(s) URL with a literal host, so
	 * $1-style back-references cannot change where a redirect goes.
	 *
	 * @param string $target Target.
	 * @return true|WP_Error
	 */
	private static function validate_target( $target ) {
		if ( 0 === strpos( $target, '/' ) && 0 !== strpos( $target, '//' ) ) {
			return true;
		}
		if ( ! preg_match( '#^https?://[^/\\\\$]+(?:/|$)#i', $target ) ) {
			return new WP_Error( 'viagent_production_invalid_url', __( 'The target must be a path such as /new-page or a full http(s) URL with a fixed domain name.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Regex rules, cached between requests.
	 *
	 * @return object[]
	 */
	private static function regex_rules() {
		global $wpdb;
		$rules = get_transient( self::REGEX_TRANSIENT );
		if ( ! is_array( $rules ) ) {
			$rules = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, target, type FROM %i WHERE match_type = %s ORDER BY id ASC LIMIT 200', Installer::table( 'seo_redirects' ), 'regex' ) );
			set_transient( self::REGEX_TRANSIENT, $rules, DAY_IN_SECONDS );
		}
		return $rules;
	}

	/**
	 * Redirects the current request when a rule matches.
	 */
	public static function handle() {
		global $wpdb;

		$full  = self::request_path();
		$path  = strtok( $full, '?' );
		$table = Installer::table( 'seo_redirects' );
		if ( '' === $full ) {
			return;
		}

		$rule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE match_type = %s AND source IN (%s, %s) ORDER BY id DESC LIMIT 1', $table, 'exact', $full, $path ) );
		if ( ! $rule ) {
			foreach ( self::regex_rules() as $candidate ) {
				if ( false !== strpos( $candidate->source, '~' ) ) {
					continue;
				}
				if ( @preg_match( self::pattern( $candidate->source ), $full, $matches ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- patterns are validated on save; this guards against edge cases.
					$rule = clone $candidate;
					if ( $candidate->target ) {
						$rule->target = preg_replace_callback(
							'/\$(\d+)/',
							static function ( $m ) use ( $matches ) {
								return $matches[ (int) $m[1] ] ?? '';
							},
							$candidate->target
						);
					}
					break;
				}
			}
		}

		if ( $rule ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit_at = %s WHERE id = %d', $table, current_time( 'mysql', true ), $rule->id ) );
			$code = (int) $rule->type;
			if ( in_array( $code, array( 410, 451 ), true ) ) {
				status_header( $code );
				nocache_headers();
				$GLOBALS['wp_query']->set_404();
				return;
			}
			$target = 0 === strpos( $rule->target, '/' ) ? home_url( $rule->target ) : $rule->target;
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirects to external sites are the point of this feature; targets are set by the site owner or their AI.
			wp_redirect( $target, $code, 'Viagent Production' );
			exit;
		}
	}

	/**
	 * Logs the current request when it ends in a 404.
	 */
	public static function maybe_log_404() {
		if ( is_404() && 404 === http_response_code() && Head::settings()['log_404'] ) {
			$path = self::request_path();
			if ( '' !== $path && ! self::is_scanner_noise( $path ) ) {
				self::log_404( $path );
			}
		}
	}

	/**
	 * Whether a 404 path is vulnerability-scanner noise not worth logging.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	private static function is_scanner_noise( $path ) {
		$bare = strtok( $path, '?' );
		if ( preg_match( '#\.(php|asp|aspx|env|git)$#', $bare ) ) {
			return true;
		}
		foreach ( array( 'wp-admin', 'wp-login', 'xmlrpc', 'wp-content/', 'wp-includes/' ) as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Records a 404. Existing paths only get their hit count bumped; new
	 * paths are capped per table and per visitor so a scanner cannot bloat
	 * the log.
	 *
	 * @param string $path Normalized path.
	 */
	private static function log_404( $path ) {
		global $wpdb;
		$table    = Installer::table( 'seo_404' );
		$path     = mb_substr( $path, 0, 190 );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 255 ) : '';
		$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$now      = current_time( 'mysql', true );

		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE path = %s', $table, $path ) );
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s, referrer = %s, user_agent = %s WHERE id = %d', $table, $now, $referrer, $agent, $id ) );
			return;
		}

		$count = get_transient( self::COUNT_TRANSIENT );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			set_transient( self::COUNT_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS );
		}
		if ( (int) $count >= self::MAX_404_ROW ) {
			return;
		}

		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip_key   = 'viagent_production_404_ip_' . md5( $ip );
		$ip_count = (int) get_transient( $ip_key );
		if ( $ip_count >= self::MAX_404_PER_IP ) {
			return;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'path'       => $path,
				'hits'       => 1,
				'referrer'   => $referrer,
				'user_agent' => $agent,
				'first_seen' => $now,
				'last_seen'  => $now,
			)
		);
		if ( $inserted ) {
			set_transient( self::COUNT_TRANSIENT, (int) $count + 1, 5 * MINUTE_IN_SECONDS );
			set_transient( $ip_key, $ip_count + 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Keeps the 404 log to a fixed size. Runs daily.
	 */
	public static function prune() {
		global $wpdb;
		$table = Installer::table( 'seo_404' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $count > self::MAX_404_ROW ) {
			$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen FROM %i ORDER BY last_seen DESC LIMIT 1 OFFSET %d', $table, self::MAX_404_ROW ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen <= %s', $table, $cutoff ) );
			delete_transient( self::COUNT_TRANSIENT );
		}
	}

	/**
	 * Lists redirects.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_redirects( $input ) {
		global $wpdb;
		$table    = Installer::table( 'seo_redirects' );
		$per_page = (int) ( $input['per_page'] ?? 50 );
		$offset   = ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page;
		$search   = (string) ( $input['search'] ?? '' );

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source LIKE %s OR target LIKE %s', $table, $like, $like ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source LIKE %s OR target LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $like, $like, $per_page, $offset ) );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $per_page, $offset ) );
		}
		return array(
			'total' => $total,
			'items' => array_map( array( self::class, 'item' ), (array) $rows ),
		);
	}

	/**
	 * Formats a redirect row.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	public static function item( $row ) {
		return array(
			'id'          => (int) $row->id,
			'source'      => $row->source,
			'target'      => $row->target,
			'type'        => (int) $row->type,
			'match_type'  => $row->match_type,
			'hits'        => (int) $row->hits,
			'last_hit_at' => $row->last_hit_at,
			'note'        => $row->note,
			'created_at'  => $row->created_at,
		);
	}

	/**
	 * Creates a redirect.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create( $input ) {
		global $wpdb;
		$table      = Installer::table( 'seo_redirects' );
		$type       = (int) ( $input['type'] ?? 301 );
		$match_type = (string) ( $input['match_type'] ?? 'exact' );
		$target     = trim( (string) ( $input['target'] ?? '' ) );

		$kind = self::validate_kind( $type, $match_type );
		if ( is_wp_error( $kind ) ) {
			return $kind;
		}

		if ( 'regex' === $match_type ) {
			$source = trim( (string) ( $input['source'] ?? '' ) );
			$valid  = self::validate_regex( $source );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		} else {
			$source = self::normalize( $input['source'] );
			if ( '/' === $source ) {
				return new WP_Error( 'viagent_production_invalid_source', __( 'The homepage cannot be redirected.', 'viagent-production' ) );
			}
		}

		if ( ! in_array( $type, array( 410, 451 ), true ) ) {
			if ( '' === $target ) {
				return new WP_Error( 'viagent_production_missing_target', __( 'A target URL is needed for 301, 302 and 307 redirects.', 'viagent-production' ) );
			}
			$target = 0 === strpos( $target, '/' ) ? '/' . ltrim( $target, '/' ) : esc_url_raw( $target );
			if ( '' === $target ) {
				return new WP_Error( 'viagent_production_invalid_url', __( 'The target must be a path such as /new-page or a full http(s) URL.', 'viagent-production' ) );
			}
			$valid = self::validate_target( $target );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			if ( 'exact' === $match_type && self::normalize( $target ) === $source && ( 0 === strpos( $target, '/' ) || wp_parse_url( $target, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return new WP_Error( 'viagent_production_redirect_loop', __( 'The source and target are the same page, which would cause a redirect loop.', 'viagent-production' ) );
			}
		} else {
			$target = '';
		}

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s AND match_type = %s', $table, $source, $match_type ) );
		if ( $existing ) {
			/* translators: %d: redirect ID */
			return new WP_Error( 'viagent_production_duplicate_redirect', sprintf( __( 'A redirect for this source already exists (ID %d). Delete it first to replace it.', 'viagent-production' ), $existing->id ) );
		}

		$wpdb->insert(
			$table,
			array(
				'source'     => $source,
				'target'     => $target,
				'type'       => $type,
				'match_type' => $match_type,
				'hits'       => 0,
				'note'       => sanitize_text_field( (string) ( $input['note'] ?? '' ) ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'viagent_production_save_failed', __( 'The redirect could not be saved.', 'viagent-production' ) );
		}
		delete_transient( self::REGEX_TRANSIENT );

		// The 404 is fixed now, so drop it from the log.
		if ( 'exact' === $match_type ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE path = %s', Installer::table( 'seo_404' ), $source ) );
			delete_transient( self::COUNT_TRANSIENT );
		}

		return self::item( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $wpdb->insert_id ) ) );
	}

	/**
	 * Gets a redirect row.
	 *
	 * @param int $id Redirect ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::table( 'seo_redirects' ), $id ) );
	}

	/**
	 * Deletes a redirect.
	 *
	 * @param int $id Redirect ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		delete_transient( self::REGEX_TRANSIENT );
		return (bool) $wpdb->delete( Installer::table( 'seo_redirects' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Restores a deleted redirect row.
	 *
	 * @param array $row Row data.
	 * @return true|WP_Error
	 */
	public static function restore( array $row ) {
		global $wpdb;
		$allowed = array_intersect_key( $row, array_flip( array( 'source', 'target', 'type', 'match_type', 'hits', 'last_hit_at', 'note', 'created_at' ) ) );

		$type       = (int) ( $allowed['type'] ?? 0 );
		$match_type = (string) ( $allowed['match_type'] ?? '' );
		$kind       = self::validate_kind( $type, $match_type );
		if ( is_wp_error( $kind ) ) {
			return $kind;
		}
		if ( 'regex' === $match_type ) {
			$valid = self::validate_regex( (string) ( $allowed['source'] ?? '' ) );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		if ( ! in_array( $type, array( 410, 451 ), true ) ) {
			$valid = self::validate_target( (string) ( $allowed['target'] ?? '' ) );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		delete_transient( self::REGEX_TRANSIENT );
		return $wpdb->insert( Installer::table( 'seo_redirects' ), $allowed ) ? true : new WP_Error( 'viagent_production_revert_failed', __( 'The redirect could not be restored.', 'viagent-production' ) );
	}

	/**
	 * Lists logged 404s, most hit first.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_404( $input ) {
		global $wpdb;
		$table    = Installer::table( 'seo_404' );
		$per_page = (int) ( $input['per_page'] ?? 50 );
		$offset   = ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page;
		$order    = 'recent' === ( $input['order'] ?? 'hits' ) ? 'last_seen' : 'hits';

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order is one of two fixed column names.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY {$order} DESC LIMIT %d OFFSET %d", $table, $per_page, $offset ) );

		return array(
			'total'   => $total,
			'logging' => (bool) Head::settings()['log_404'],
			'items'   => array_map(
				static function ( $row ) {
					return array(
						'path'       => $row->path,
						'url'        => home_url( $row->path ),
						'hits'       => (int) $row->hits,
						'referrer'   => $row->referrer,
						'user_agent' => $row->user_agent,
						'first_seen' => $row->first_seen,
						'last_seen'  => $row->last_seen,
					);
				},
				(array) $rows
			),
		);
	}

	/**
	 * Clears the 404 log, or one path.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function clear_404( $input ) {
		global $wpdb;
		$table = Installer::table( 'seo_404' );
		if ( ! empty( $input['path'] ) ) {
			$deleted = $wpdb->delete( $table, array( 'path' => self::normalize( $input['path'] ) ) );
		} else {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
		delete_transient( self::COUNT_TRANSIENT );
		return array( 'deleted' => (int) $deleted );
	}
}
