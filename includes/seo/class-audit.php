<?php
/**
 * SEO analysis: a full on-page audit of one post (content and the live page),
 * a site-wide issue finder, internal link suggestions and a broken link check.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\SEO;

use ViagentProduction\Abilities\Content;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * SEO audits.
 */
class Audit {

	const TITLE_MAX       = 60;
	const DESCRIPTION_MIN = 120;
	const DESCRIPTION_MAX = 160;
	const THIN_WORDS      = 300;
	const SLUG_MAX        = 75;

	/**
	 * Post types an audit covers.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array_keys( Content::post_types() );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		return array_values( array_unique( array_filter( $types, 'is_post_type_viewable' ) ) );
	}

	/**
	 * Content HTML with blocks and shortcodes rendered.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function rendered( WP_Post $post ) {
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- needed so blocks and shortcodes render for this post; restored below.
		setup_postdata( $post );
		$html            = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the original value.
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}
		return $html;
	}

	/**
	 * Plain text of HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function text( $html ) {
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $html );
		// Keep words in separate blocks apart, without splitting inline tags like links.
		$html = preg_replace( '#<(/?(?:p|div|h[1-6]|li|ul|ol|br|figure|figcaption|section|article|header|footer|table|tr|td|th|blockquote|pre)\b)#i', ' <$1', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Words of a text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function words( $text ) {
		return array_values( array_filter( preg_split( '/[^\p{L}\p{N}\'’-]+/u', (string) $text ) ) );
	}

	/**
	 * Facts about a block of HTML: headings, images, links.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function structure( $html ) {
		$headings = array();
		if ( preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$headings[] = array(
					'level' => (int) $match[1],
					'text'  => self::text( $match[2] ),
				);
			}
		}

		$images = array();
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$alt      = preg_match( '#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $a ) ? trim( html_entity_decode( $a[2], ENT_QUOTES, 'UTF-8' ) ) : null;
				$src      = preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $s ) ? $s[2] : '';
				$images[] = array(
					'src' => $src,
					'alt' => $alt,
				);
			}
		}

		$links = array();
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				if ( ! preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#is', $match[1], $h ) ) {
					continue;
				}
				$href = html_entity_decode( trim( $h[2] ), ENT_QUOTES, 'UTF-8' );
				if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript|data):#i', $href ) ) {
					continue;
				}
				$links[] = array(
					'href'     => $href,
					'text'     => self::text( $match[2] ),
					'internal' => self::is_internal( $href ),
					'nofollow' => (bool) preg_match( '#\brel\s*=\s*(["\'])[^"\']*nofollow#i', $match[1] ),
				);
			}
		}

		return array(
			'headings' => $headings,
			'images'   => $images,
			'links'    => $links,
		);
	}

	/**
	 * Whether a link points at this site: relative, or http(s) on the home
	 * host and port.
	 *
	 * @param string $href Link URL.
	 * @return bool
	 */
	public static function is_internal( $href ) {
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return '' === $scheme;
		}
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$home = wp_parse_url( home_url() );
		if ( strtolower( (string) ( $home['host'] ?? '' ) ) !== $host ) {
			return false;
		}
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$allowed = ! empty( $home['port'] ) ? array( (int) $home['port'] ) : array( 80, 443 );
		return 0 === $port || in_array( $port, $allowed, true );
	}

	/**
	 * Estimated syllables in an English word.
	 *
	 * @param string $word Word.
	 * @return int
	 */
	private static function syllables( $word ) {
		$word = strtolower( preg_replace( '/[^a-z]/i', '', $word ) );
		if ( strlen( $word ) <= 3 ) {
			return 1;
		}
		$word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word = preg_replace( '/^y/', '', $word );
		return max( 1, preg_match_all( '/[aeiouy]{1,2}/', $word ) );
	}

	/**
	 * Readability statistics.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	public static function readability( $text ) {
		$sentences = array_values( array_filter( array_map( 'trim', preg_split( '/(?<=[.!?。！？])\s+/u', $text ) ) ) );
		$words     = self::words( $text );
		$count     = max( 1, count( $words ) );
		$long      = 0;
		foreach ( $sentences as $sentence ) {
			if ( count( self::words( $sentence ) ) > 20 ) {
				++$long;
			}
		}
		$result = array(
			'sentences'                => count( $sentences ),
			'avg_words_per_sentence'   => round( $count / max( 1, count( $sentences ) ), 1 ),
			'long_sentences_percent'   => count( $sentences ) ? round( 100 * $long / count( $sentences ) ) : 0,
			'flesch_reading_ease'      => null,
			'flesch_reading_ease_note' => '',
		);
		if ( 0 === strpos( get_locale(), 'en' ) && $sentences ) {
			$syllables                          = array_sum( array_map( array( self::class, 'syllables' ), $words ) );
			$result['flesch_reading_ease']      = round( 206.835 - 1.015 * ( $count / count( $sentences ) ) - 84.6 * ( $syllables / $count ), 1 );
			$result['flesch_reading_ease_note'] = '60+ is easy for most readers; under 30 is very difficult.';
		}
		return $result;
	}

	/**
	 * Case-insensitive count of a phrase in text, on word boundaries.
	 *
	 * @param string $phrase Phrase.
	 * @param string $text   Text.
	 * @return int
	 */
	public static function occurrences( $phrase, $text ) {
		$phrase = trim( $phrase );
		if ( '' === $phrase ) {
			return 0;
		}
		return (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu', $text );
	}

	/**
	 * The search title as it will appear (custom, with variables replaced, or the default).
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $fields SEO fields.
	 * @return string
	 */
	public static function effective_title( WP_Post $post, array $fields ) {
		if ( '' !== $fields['title'] ) {
			return Head::replace_vars( $fields['title'], get_the_title( $post ) );
		}
		return get_the_title( $post ) . ' ' . Head::settings()['title_separator'] . ' ' . get_bloginfo( 'name' );
	}

	/**
	 * Adds a check to a result list.
	 *
	 * @param array  $checks  Checks.
	 * @param string $id      Check ID.
	 * @param string $status  pass, warn or fail.
	 * @param string $message Message.
	 */
	private static function check( array &$checks, $id, $status, $message ) {
		$checks[] = array(
			'check'   => $id,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Full SEO audit of one post or page.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function analyze( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No public post, page or product found with that ID.', 'viagent-production' ) );
		}

		$fields    = Store::read( 'post', $post->ID );
		$keyword   = trim( (string) ( $input['focus_keyword'] ?? $fields['focus_keyword'] ) );
		$html      = self::rendered( $post );
		$text      = self::text( $html );
		$words     = self::words( $text );
		$structure = self::structure( $html );
		$title     = self::effective_title( $post, $fields );
		$checks    = array();

		// Title.
		$title_length = mb_strlen( $title );
		if ( $title_length > self::TITLE_MAX ) {
			/* translators: 1: length, 2: max */
			self::check( $checks, 'title_length', 'warn', sprintf( 'The search title is %1$d characters; keep it under %2$d so it is not cut off.', $title_length, self::TITLE_MAX ) );
		} elseif ( $title_length < 30 ) {
			self::check( $checks, 'title_length', 'warn', sprintf( 'The search title is only %d characters; 50–60 uses the space well.', $title_length ) );
		} else {
			self::check( $checks, 'title_length', 'pass', sprintf( 'The search title is %d characters.', $title_length ) );
		}

		// Description.
		$description = $fields['description'];
		$desc_length = mb_strlen( $description );
		if ( '' === $description ) {
			self::check( $checks, 'description', 'fail', 'No meta description is set; search engines will pick a random snippet.' );
		} elseif ( $desc_length < self::DESCRIPTION_MIN || $desc_length > self::DESCRIPTION_MAX ) {
			self::check( $checks, 'description', 'warn', sprintf( 'The meta description is %d characters; 120–155 works best.', $desc_length ) );
		} else {
			self::check( $checks, 'description', 'pass', sprintf( 'The meta description is %d characters.', $desc_length ) );
		}

		// Content length.
		$word_count = count( $words );
		if ( $word_count < self::THIN_WORDS ) {
			self::check( $checks, 'content_length', 'post' === $post->post_type ? 'fail' : 'warn', sprintf( 'Only %d words of content; aim for at least %d (more for competitive topics).', $word_count, self::THIN_WORDS ) );
		} else {
			self::check( $checks, 'content_length', 'pass', sprintf( '%d words of content.', $word_count ) );
		}

		// Headings.
		$h1          = count(
			array_filter(
				$structure['headings'],
				static function ( $h ) {
					return 1 === $h['level'];
				}
			)
		);
		$subheadings = count( $structure['headings'] ) - $h1;
		if ( $h1 > 0 ) {
			self::check( $checks, 'h1_in_content', 'warn', sprintf( 'The content has %d H1 heading(s); the theme already shows the title as H1, so use H2 and below.', $h1 ) );
		}
		if ( $word_count >= self::THIN_WORDS && 0 === $subheadings ) {
			self::check( $checks, 'subheadings', 'warn', 'No subheadings; break longer content up with H2/H3 headings.' );
		} elseif ( $subheadings ) {
			self::check( $checks, 'subheadings', 'pass', sprintf( '%d subheadings.', $subheadings ) );
		}

		// Images.
		$missing_alt = array_values(
			array_filter(
				$structure['images'],
				static function ( $img ) {
					return null === $img['alt'] || '' === $img['alt'];
				}
			)
		);
		if ( $missing_alt ) {
			self::check( $checks, 'image_alt', 'fail', sprintf( '%d of %d images have no alt text.', count( $missing_alt ), count( $structure['images'] ) ) );
		} elseif ( $structure['images'] ) {
			self::check( $checks, 'image_alt', 'pass', 'All images have alt text.' );
		} elseif ( $word_count >= self::THIN_WORDS ) {
			self::check( $checks, 'images', 'warn', 'No images in the content; images make pages more engaging and can rank in image search.' );
		}
		if ( post_type_supports( $post->post_type, 'thumbnail' ) && ! has_post_thumbnail( $post ) && '' === $fields['social_image'] ) {
			self::check( $checks, 'featured_image', 'warn', 'No featured image; it is used when the page is shared on social media.' );
		}

		// Links.
		$internal = count( wp_list_filter( $structure['links'], array( 'internal' => true ) ) );
		$external = count( $structure['links'] ) - $internal;
		self::check( $checks, 'internal_links', $internal ? 'pass' : 'warn', $internal ? sprintf( '%d internal links.', $internal ) : 'No internal links; link to related pages on your site (try suggest_internal_links).' );
		if ( 'post' === $post->post_type && ! $external && $word_count >= self::THIN_WORDS ) {
			self::check( $checks, 'external_links', 'warn', 'No outbound links; linking to authoritative sources can help readers and trust.' );
		}

		// Slug.
		$slug_length = strlen( urldecode( $post->post_name ) );
		if ( $slug_length > self::SLUG_MAX ) {
			self::check( $checks, 'slug_length', 'warn', sprintf( 'The URL slug is %d characters; shorter slugs are easier to read and share.', $slug_length ) );
		}

		// Indexing.
		if ( $fields['noindex'] ) {
			self::check( $checks, 'noindex', 'fail', 'This page is set to noindex, so it will not appear in search results.' );
		}
		if ( ! get_option( 'blog_public' ) ) {
			self::check( $checks, 'site_visibility', 'fail', 'The whole site asks search engines not to index it (Settings → Reading).' );
		}

		// Focus keyword.
		$keyword_stats = null;
		if ( '' === $keyword ) {
			self::check( $checks, 'focus_keyword', 'warn', 'No focus keyword is set, so keyword checks were skipped. Pass focus_keyword or set one with update_seo.' );
		} else {
			$intro       = implode( ' ', array_slice( $words, 0, 100 ) );
			$count       = self::occurrences( $keyword, $text );
			$density     = $word_count ? round( 100 * $count * count( self::words( $keyword ) ) / $word_count, 2 ) : 0;
			$in_headings = 0;
			$in_alt      = 0;
			foreach ( $structure['headings'] as $heading ) {
				$in_headings += self::occurrences( $keyword, $heading['text'] ) ? 1 : 0;
			}
			foreach ( $structure['images'] as $image ) {
				$in_alt += self::occurrences( $keyword, (string) $image['alt'] ) ? 1 : 0;
			}
			$keyword_stats = array(
				'keyword'         => $keyword,
				'occurrences'     => $count,
				'density_percent' => $density,
			);

			self::check( $checks, 'keyword_in_title', self::occurrences( $keyword, $title ) ? 'pass' : 'fail', self::occurrences( $keyword, $title ) ? 'The focus keyword is in the search title.' : 'Add the focus keyword to the search title, ideally near the start.' );
			self::check( $checks, 'keyword_in_description', self::occurrences( $keyword, $description ) ? 'pass' : 'warn', self::occurrences( $keyword, $description ) ? 'The focus keyword is in the meta description.' : 'Use the focus keyword in the meta description.' );
			$slug_has = false !== strpos( $post->post_name, sanitize_title( $keyword ) ) || self::occurrences( $keyword, str_replace( '-', ' ', urldecode( $post->post_name ) ) );
			self::check( $checks, 'keyword_in_slug', $slug_has ? 'pass' : 'warn', $slug_has ? 'The focus keyword is in the URL.' : 'The URL does not contain the focus keyword (changing a published URL needs a redirect).' );
			self::check( $checks, 'keyword_in_intro', self::occurrences( $keyword, $intro ) ? 'pass' : 'warn', self::occurrences( $keyword, $intro ) ? 'The focus keyword appears in the first 100 words.' : 'Use the focus keyword in the first paragraph.' );
			if ( $subheadings ) {
				self::check( $checks, 'keyword_in_subheadings', $in_headings ? 'pass' : 'warn', $in_headings ? sprintf( 'The focus keyword is in %d subheading(s).', $in_headings ) : 'Use the focus keyword (or a variation) in at least one subheading.' );
			}
			if ( $structure['images'] ) {
				self::check( $checks, 'keyword_in_alt', $in_alt ? 'pass' : 'warn', $in_alt ? 'The focus keyword is in image alt text.' : 'Use the focus keyword in the alt text of a relevant image.' );
			}
			if ( 0 === $count ) {
				self::check( $checks, 'keyword_density', 'fail', 'The focus keyword does not appear in the content.' );
			} elseif ( $density > 3 ) {
				self::check( $checks, 'keyword_density', 'warn', sprintf( 'Keyword density is %s%%, which can look like keyword stuffing; 0.5–2.5%% is natural.', $density ) );
			} elseif ( $density < 0.5 && $word_count > 300 ) {
				self::check( $checks, 'keyword_density', 'warn', sprintf( 'Keyword density is %s%%; use the keyword a few more times naturally.', $density ) );
			} else {
				self::check( $checks, 'keyword_density', 'pass', sprintf( 'Keyword density is %s%%.', $density ) );
			}
			$duplicates = self::posts_with_keyword( $keyword, $post->ID );
			if ( $duplicates ) {
				self::check( $checks, 'keyword_cannibalization', 'warn', sprintf( 'Other content targets the same focus keyword (IDs %s); they may compete in search results.', implode( ', ', $duplicates ) ) );
			}
		}

		$readability = self::readability( $text );
		if ( $readability['long_sentences_percent'] > 25 ) {
			self::check( $checks, 'sentence_length', 'warn', sprintf( '%d%% of sentences are longer than 20 words; shorter sentences are easier to read.', $readability['long_sentences_percent'] ) );
		}

		$result = array(
			'post_id'  => $post->ID,
			'title'    => get_the_title( $post ),
			'url'      => get_permalink( $post ),
			'status'   => $post->post_status,
			'provider' => Store::provider_name(),
			'score'    => self::score( $checks ),
			'seo'      => array_merge(
				$fields,
				array(
					'effective_title'    => $title,
					'title_length'       => $title_length,
					'description_length' => $desc_length,
				)
			),
			'keyword'  => $keyword_stats,
			'stats'    => array(
				'words'          => $word_count,
				'headings'       => $structure['headings'],
				'images'         => count( $structure['images'] ),
				'images_no_alt'  => array_column( $missing_alt, 'src' ),
				'internal_links' => $internal,
				'external_links' => $external,
				'readability'    => $readability,
			),
			'checks'   => $checks,
		);

		if ( ( $input['live'] ?? true ) && 'publish' === $post->post_status ) {
			$result['live'] = self::live( get_permalink( $post ) );
			foreach ( $result['live']['problems'] ?? array() as $problem ) {
				self::check( $result['checks'], 'live', 'fail', $problem );
			}
			$result['score'] = self::score( $result['checks'] );
		}
		return $result;
	}

	/**
	 * Score from 0 to 100.
	 *
	 * @param array $checks Checks.
	 * @return int
	 */
	private static function score( array $checks ) {
		if ( ! $checks ) {
			return 100;
		}
		$points = 0;
		foreach ( $checks as $check ) {
			$points += 'pass' === $check['status'] ? 2 : ( 'warn' === $check['status'] ? 1 : 0 );
		}
		return (int) round( 100 * $points / ( 2 * count( $checks ) ) );
	}

	/**
	 * Other published content with the same focus keyword.
	 *
	 * @param string $keyword Keyword.
	 * @param int    $exclude Post ID to leave out.
	 * @return int[]
	 */
	private static function posts_with_keyword( $keyword, $exclude ) {
		$ids = array();
		foreach ( self::recent_posts( 300 ) as $post ) {
			if ( $post->ID !== $exclude && 0 === strcasecmp( trim( Store::read( 'post', $post->ID )['focus_keyword'] ), $keyword ) ) {
				$ids[] = $post->ID;
			}
		}
		return array_slice( $ids, 0, 10 );
	}

	/**
	 * Recently published content of the audited types.
	 *
	 * @param int      $limit Max posts.
	 * @param string[] $types Post types (defaults to all audited types).
	 * @return WP_Post[]
	 */
	public static function recent_posts( $limit, array $types = array() ) {
		return get_posts(
			array(
				'post_type'        => $types ? $types : self::post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Fetches the live page and checks what search engines actually see.
	 *
	 * @param string $url Page URL.
	 * @return array
	 */
	public static function live( $url ) {
		$start    = microtime( true );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 2,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; ViagentProductionSEO/1.0; +' . home_url( '/' ) . ')',
			)
		);
		$elapsed  = (int) round( 1000 * ( microtime( true ) - $start ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'fetched' => false,
				'error'   => $response->get_error_message(),
			);
		}

		// Only parse pages served by this site, even if the request was redirected elsewhere.
		$final = $url;
		if ( isset( $response['http_response'] ) && $response['http_response'] instanceof \WP_HTTP_Requests_Response ) {
			$final = (string) $response['http_response']->get_response_object()->url;
		}
		if ( strtolower( (string) wp_parse_url( $final, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return array(
				'fetched' => false,
				/* translators: %s: URL */
				'error'   => sprintf( __( 'The page redirected to another site (%s), so it was not checked.', 'viagent-production' ), $final ),
			);
		}

		$html     = (string) wp_remote_retrieve_body( $response );
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$problems = array();
		$result   = array(
			'fetched'          => true,
			'url'              => $url,
			'http_status'      => $code,
			'response_time_ms' => $elapsed,
			'html_kb'          => round( strlen( $html ) / 1024, 1 ),
			'x_robots_tag'     => (string) wp_remote_retrieve_header( $response, 'x-robots-tag' ),
		);

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );

		$first = static function ( $query, $attr = null ) use ( $xpath ) {
			$node = $xpath->query( $query )->item( 0 );
			if ( ! $node ) {
				return null;
			}
			return trim( $attr ? $node->getAttribute( $attr ) : $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		};

		$result['title']            = $first( '//head/title' );
		$result['meta_description'] = $first( '//meta[@name="description"]', 'content' );
		$result['canonical']        = $first( '//link[@rel="canonical"]', 'href' );
		$result['robots']           = $first( '//meta[@name="robots"]', 'content' );
		$result['lang']             = $first( '//html', 'lang' );
		$result['viewport']         = null !== $first( '//meta[@name="viewport"]', 'content' );
		$result['og_title']         = $first( '//meta[@property="og:title"]', 'content' );
		$result['og_image']         = $first( '//meta[@property="og:image"]', 'content' );
		$result['twitter_card']     = $first( '//meta[@name="twitter:card"]', 'content' );
		$result['h1']               = array();
		foreach ( $xpath->query( '//h1' ) as $node ) {
			$result['h1'][] = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		}
		$result['hreflang'] = $xpath->query( '//link[@rel="alternate"][@hreflang]' )->length;

		$images = $xpath->query( '//body//img' );
		$no_alt = 0;
		foreach ( $images as $img ) {
			if ( ! $img->hasAttribute( 'alt' ) ) {
				++$no_alt;
			}
		}
		$result['images']        = $images->length;
		$result['images_no_alt'] = $no_alt;

		$types = array();
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$data = json_decode( $script->textContent, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			array_walk_recursive(
				$data,
				static function ( $value, $key ) use ( &$types ) {
					if ( '@type' === $key && is_string( $value ) ) {
						$types[] = $value;
					}
				}
			);
		}
		$result['schema_types'] = array_values( array_unique( $types ) );

		if ( $code >= 400 ) {
			$problems[] = sprintf( 'The live page returns HTTP %d.', $code );
		}
		if ( ! $result['title'] ) {
			$problems[] = 'The live page has no <title> tag.';
		}
		if ( ! $result['meta_description'] ) {
			$problems[] = 'The live page has no meta description tag.';
		}
		if ( count( $result['h1'] ) !== 1 ) {
			$problems[] = sprintf( 'The live page has %d H1 headings; it should have exactly one.', count( $result['h1'] ) );
		}
		if ( ( $result['robots'] && false !== stripos( $result['robots'], 'noindex' ) ) || false !== stripos( $result['x_robots_tag'], 'noindex' ) ) {
			$problems[] = 'The live page tells search engines not to index it (noindex).';
		}
		if ( $result['canonical'] && untrailingslashit( strtok( $result['canonical'], '?' ) ) !== untrailingslashit( strtok( $url, '?' ) ) ) {
			$problems[] = sprintf( 'The canonical URL points elsewhere (%s), so this page may not be indexed itself.', $result['canonical'] );
		}
		if ( ! $result['viewport'] ) {
			$problems[] = 'No viewport meta tag; the page may not be mobile friendly.';
		}
		if ( ! $result['og_image'] ) {
			$problems[] = 'No og:image, so social media shares will have no picture.';
		}
		if ( $elapsed > 2500 ) {
			$problems[] = sprintf( 'The server took %d ms to respond; aim for under 800 ms (caching helps).', $elapsed );
		}

		$result['problems'] = $problems;
		return $result;
	}

	/**
	 * Site-wide SEO checks.
	 *
	 * @return array[]
	 */
	public static function site_checks() {
		$issues = array();
		if ( 'rankmath' === Store::provider() && is_callable( array( '\RankMath\Helper', 'is_invalid_registration' ) ) && \RankMath\Helper::is_invalid_registration() ) {
			$issues[] = array(
				'severity' => 'critical',
				'issue'    => 'Rank Math\'s setup wizard is not finished, so it prints no SEO tags at all. Finish or skip it under Rank Math → Dashboard.',
			);
		}
		if ( ! get_option( 'blog_public' ) ) {
			$issues[] = array(
				'severity' => 'critical',
				'issue'    => 'The site asks search engines not to index it (Settings → Reading → Search engine visibility).',
			);
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$issues[] = array(
				'severity' => 'high',
				'issue'    => 'Plain permalinks (?p=123) are in use; choose "Post name" under Settings → Permalinks.',
			);
		}
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			$issues[] = array(
				'severity' => 'high',
				'issue'    => 'The site address does not use HTTPS.',
			);
		}
		if ( ! Head::sitemap_url() ) {
			$issues[] = array(
				'severity' => 'high',
				'issue'    => 'No XML sitemap is available.',
			);
		}
		if ( in_array( get_bloginfo( 'description' ), array( 'Just another WordPress site', '' ), true ) ) {
			$issues[] = array(
				'severity' => 'low',
				'issue'    => 'The site tagline is empty or still the WordPress default.',
			);
		}
		if ( ! get_site_icon_url() ) {
			$issues[] = array(
				'severity' => 'low',
				'issue'    => 'No site icon (favicon) is set; it shows next to your site in mobile search results.',
			);
		}
		$robots = apply_filters( 'robots_txt', '', (bool) get_option( 'blog_public' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		if ( preg_match( '#^\s*Disallow:\s*/\s*$#mi', (string) $robots ) && preg_match( '#^\s*User-agent:\s*\*\s*$#mi', (string) $robots ) ) {
			$issues[] = array(
				'severity' => 'critical',
				'issue'    => 'robots.txt blocks all crawlers with "Disallow: /".',
			);
		}
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			$issues[] = array(
				'severity' => 'info',
				'issue'    => 'A physical robots.txt file exists, so WordPress robots.txt settings are ignored.',
			);
		}
		if ( 'native' === Store::provider() && ! array_filter( Head::settings()['verification'] ) ) {
			$issues[] = array(
				'severity' => 'info',
				'issue'    => 'No search engine verification code is set (Google Search Console, Bing Webmaster Tools).',
			);
		}
		return $issues;
	}

	/**
	 * Finds SEO issues across published content.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function find_issues( $input ) {
		$types = self::post_types();
		if ( ! empty( $input['post_type'] ) && 'any' !== $input['post_type'] ) {
			if ( ! in_array( $input['post_type'], $types, true ) ) {
				/* translators: %s: post type */
				return new WP_Error( 'viagent_production_invalid_post_type', sprintf( __( 'Unknown or non-public post type "%s".', 'viagent-production' ), $input['post_type'] ) );
			}
			$types = array( $input['post_type'] );
		}

		$posts   = self::recent_posts( (int) ( $input['limit'] ?? 100 ), $types );
		$titles  = array();
		$descs   = array();
		$keys    = array();
		$rows    = array();
		$summary = array();

		foreach ( $posts as $post ) {
			$fields  = Store::read( 'post', $post->ID );
			$content = (string) $post->post_content;
			$text    = self::text( strip_shortcodes( $content ) );
			$words   = count( self::words( $text ) );
			$struct  = self::structure( $content );
			$title   = self::effective_title( $post, $fields );
			$issues  = array();

			if ( '' === $fields['description'] ) {
				$issues[] = 'missing_meta_description';
			} elseif ( mb_strlen( $fields['description'] ) > self::DESCRIPTION_MAX ) {
				$issues[] = 'meta_description_too_long';
			} elseif ( mb_strlen( $fields['description'] ) < 70 ) {
				$issues[] = 'meta_description_too_short';
			}
			if ( mb_strlen( $title ) > self::TITLE_MAX ) {
				$issues[] = 'title_too_long';
			}
			if ( '' === $fields['focus_keyword'] ) {
				$issues[] = 'no_focus_keyword';
			} elseif ( ! self::occurrences( $fields['focus_keyword'], $title ) ) {
				$issues[] = 'focus_keyword_not_in_title';
			}
			if ( $fields['noindex'] ) {
				$issues[] = 'noindex';
			}
			if ( $words < self::THIN_WORDS ) {
				$issues[] = 'thin_content';
			}
			if ( post_type_supports( $post->post_type, 'thumbnail' ) && ! has_post_thumbnail( $post ) ) {
				$issues[] = 'no_featured_image';
			}
			$no_alt = count(
				array_filter(
					$struct['images'],
					static function ( $img ) {
						return null === $img['alt'] || '' === $img['alt'];
					}
				)
			);
			if ( $no_alt ) {
				$issues[] = 'images_missing_alt';
			}
			if ( ! wp_list_filter( $struct['links'], array( 'internal' => true ) ) && $words >= self::THIN_WORDS ) {
				$issues[] = 'no_internal_links';
			}
			if ( strlen( urldecode( $post->post_name ) ) > self::SLUG_MAX ) {
				$issues[] = 'long_url';
			}

			$titles[ mb_strtolower( $title ) ][] = $post->ID;
			if ( '' !== $fields['description'] ) {
				$descs[ mb_strtolower( $fields['description'] ) ][] = $post->ID;
			}
			if ( '' !== $fields['focus_keyword'] ) {
				$keys[ mb_strtolower( $fields['focus_keyword'] ) ][] = $post->ID;
			}

			$rows[ $post->ID ] = array(
				'post_id' => $post->ID,
				'type'    => $post->post_type,
				'title'   => get_the_title( $post ),
				'link'    => get_permalink( $post ),
				'words'   => $words,
				'issues'  => $issues,
			);
		}

		foreach ( array(
			'duplicate_title'            => $titles,
			'duplicate_meta_description' => $descs,
			'duplicate_focus_keyword'    => $keys,
		) as $issue => $groups ) {
			foreach ( $groups as $ids ) {
				if ( count( $ids ) > 1 ) {
					foreach ( $ids as $id ) {
						$rows[ $id ]['issues'][] = $issue;
					}
				}
			}
		}

		$items = array();
		foreach ( $rows as $row ) {
			foreach ( $row['issues'] as $issue ) {
				$summary[ $issue ] = ( $summary[ $issue ] ?? 0 ) + 1;
			}
			if ( $row['issues'] ) {
				$items[] = $row;
			}
		}
		arsort( $summary );

		return array(
			'provider'    => Store::provider_name(),
			'site_issues' => self::site_checks(),
			'checked'     => count( $posts ),
			'with_issues' => count( $items ),
			'summary'     => $summary,
			'items'       => $items,
			'note'        => 'Word counts use the saved post content; pages built with a page builder may show as thin. Use analyze_seo for a full check of one page.',
		);
	}

	/**
	 * Suggests internal links for a post: pages it could link to (outbound),
	 * or pages that could link to it (inbound).
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function suggest_links( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No public post, page or product found with that ID.', 'viagent-production' ) );
		}
		$direction = $input['direction'] ?? 'outbound';
		$limit     = (int) ( $input['limit'] ?? 10 );
		$others    = array_filter(
			self::recent_posts( 300 ),
			static function ( $other ) use ( $post ) {
				return $other->ID !== $post->ID;
			}
		);
		$suggest   = array();

		if ( 'inbound' === $direction ) {
			$phrases = array_filter( array_unique( array( Store::read( 'post', $post->ID )['focus_keyword'], get_the_title( $post ) ) ) );
			$url     = get_permalink( $post );
			foreach ( $others as $other ) {
				if ( false !== strpos( $other->post_content, untrailingslashit( $url ) ) ) {
					continue;
				}
				$text = self::text( strip_shortcodes( $other->post_content ) );
				foreach ( $phrases as $phrase ) {
					if ( mb_strlen( $phrase ) >= 3 && self::occurrences( $phrase, $text ) ) {
						$suggest[] = array(
							'from_post_id' => $other->ID,
							'from_title'   => get_the_title( $other ),
							'anchor_text'  => $phrase,
							'context'      => self::context( $phrase, $text ),
							'link_to'      => $url,
						);
						break;
					}
				}
				if ( count( $suggest ) >= $limit ) {
					break;
				}
			}
		} else {
			$html   = self::rendered( $post );
			$text   = self::text( $html );
			$linked = array_column( self::structure( $html )['links'], 'href' );
			foreach ( $others as $other ) {
				$url = get_permalink( $other );
				foreach ( $linked as $href ) {
					if ( untrailingslashit( strtok( $href, '#?' ) ) === untrailingslashit( $url ) ) {
						continue 2;
					}
				}
				$phrases = array_filter( array_unique( array( Store::read( 'post', $other->ID )['focus_keyword'], get_the_title( $other ) ) ) );
				foreach ( $phrases as $phrase ) {
					if ( mb_strlen( $phrase ) >= 3 && self::occurrences( $phrase, $text ) ) {
						$suggest[] = array(
							'to_post_id'  => $other->ID,
							'to_title'    => get_the_title( $other ),
							'url'         => $url,
							'anchor_text' => $phrase,
							'context'     => self::context( $phrase, $text ),
						);
						break;
					}
				}
				if ( count( $suggest ) >= $limit ) {
					break;
				}
			}
		}

		return array(
			'post_id'     => $post->ID,
			'direction'   => $direction,
			'suggestions' => $suggest,
			'note'        => $suggest ? 'Add links with update_post by wrapping the anchor text in <a href="..."> inside the block markup.' : 'No exact title or focus keyword matches were found; consider linking related topics manually.',
		);
	}

	/**
	 * Snippet of text around a phrase.
	 *
	 * @param string $phrase Phrase.
	 * @param string $text   Text.
	 * @return string
	 */
	private static function context( $phrase, $text ) {
		$pos = mb_stripos( $text, $phrase );
		if ( false === $pos ) {
			return '';
		}
		$start = max( 0, $pos - 60 );
		return ( $start ? '…' : '' ) . mb_substr( $text, $start, mb_strlen( $phrase ) + 120 ) . '…';
	}

	/**
	 * Checks the links in a post for broken targets.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function check_links( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No public post found with that ID.', 'viagent-production' ) );
		}
		$links    = self::structure( self::rendered( $post ) )['links'];
		$max      = (int) ( $input['limit'] ?? 25 );
		$checked  = array();
		$broken   = array();
		$skipped  = 0;
		$deadline = microtime( true ) + 20; // Total time budget for the whole check.
		$base     = (string) get_permalink( $post );
		$agent    = 'Mozilla/5.0 (compatible; ViagentProductionSEO/1.0; +' . home_url( '/' ) . ')';

		foreach ( $links as $link ) {
			$href = \WP_Http::make_absolute_url( $link['href'], $base );
			$href = strtok( $href, '#' );
			// Internal links are allowed by wp_http_validate_url() because they share the site's host.
			if ( ! wp_http_validate_url( $href ) ) {
				continue;
			}
			if ( isset( $checked[ $href ] ) ) {
				continue;
			}
			if ( count( $checked ) >= $max ) {
				break;
			}
			if ( microtime( true ) > $deadline ) {
				++$skipped;
				continue;
			}

			$args     = array(
				'timeout'     => 4,
				'redirection' => 3,
				'user-agent'  => $agent,
			);
			$response = wp_safe_remote_head( $href, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 403, 405, 501 ), true ) ) {
				$args['limit_response_size'] = MB_IN_BYTES;
				$response                    = wp_safe_remote_get( $href, $args );
				$code                        = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			}
			$checked[ $href ] = $code;

			if ( 0 === $code || $code >= 400 ) {
				$broken[] = array(
					'url'         => $href,
					'anchor_text' => $link['text'],
					'internal'    => $link['internal'],
					'status'      => $code ? $code : ( is_wp_error( $response ) ? $response->get_error_message() : 'no response' ),
				);
			}
		}

		$result = array(
			'post_id' => $post->ID,
			'checked' => count( $checked ),
			'total'   => count( $links ),
			'broken'  => $broken,
		);
		if ( $skipped ) {
			$result['skipped'] = $skipped;
			$result['note']    = sprintf( 'The check stopped after 20 seconds; %d link(s) were not checked. Run check_links again with a smaller limit to check the rest.', $skipped );
		}
		return $result;
	}
}
