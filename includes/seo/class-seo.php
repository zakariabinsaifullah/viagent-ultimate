<?php
/**
 * SEO tools for AI agents. Built in, so they work on every site: with Yoast
 * SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework the data is
 * read and written in that plugin's format; without one, Viagent Production
 * prints the SEO tags itself.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\SEO;

use ViagentProduction\Abilities\Abilities;
use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SEO abilities.
 */
class SEO {

	const CATEGORY       = 'viagent-production-seo';
	const INDEXNOW_KEY   = 'viagent_production_seo_indexnow_key';
	const INDEXNOW_API   = 'https://api.indexnow.org/indexnow';
	const MAX_BULK_ITEMS = 50;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		Head::init();
		Redirects::init();
		add_action( 'parse_request', array( self::class, 'serve_indexnow_key' ), 0 );
		add_filter( 'viagent_production_apply_undo', array( self::class, 'apply_undo' ), 10, 2 );
		add_filter( 'viagent_production_undo_label', array( self::class, 'undo_label' ), 10, 2 );
		add_filter( 'viagent_production_tool_summaries', array( self::class, 'summaries' ) );
		add_filter( 'viagent_production_instructions', array( self::class, 'instructions' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_categories() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SEO', 'viagent-production' ),
				'description' => __( 'Search titles, descriptions, audits, redirects, robots.txt, structured data and indexing.', 'viagent-production' ),
			)
		);
	}

	/**
	 * Tells AI apps how to use the SEO tools.
	 *
	 * @param string[] $lines Instruction lines.
	 * @return string[]
	 */
	public static function instructions( $lines ) {
		$lines[] = sprintf(
			'SEO is handled by %s. Start SEO work with get_seo_overview; use analyze_seo for one page and find_seo_issues for the whole site. Keep search titles under 60 characters and meta descriptions at 120–155. When you change a published URL, add a 301 with create_redirect. Never set noindex or block crawlers in robots.txt unless the user asks.',
			Store::provider_name()
		);
		return $lines;
	}

	/**
	 * Input schema for normalized SEO fields.
	 *
	 * @return array
	 */
	private static function field_schema() {
		return array(
			'title'              => array(
				'type'        => 'string',
				'description' => __( 'Search result title (under 60 characters). Variables like %%title%%, %%sep%% and %%sitename%% are allowed.', 'viagent-production' ),
			),
			'description'        => array(
				'type'        => 'string',
				'description' => __( 'Meta description, ideally 120–155 characters.', 'viagent-production' ),
			),
			'focus_keyword'      => array( 'type' => 'string' ),
			'canonical'          => array(
				'type'        => 'string',
				'description' => __( 'Canonical URL; leave empty to use the page itself.', 'viagent-production' ),
			),
			'noindex'            => array(
				'type'        => 'boolean',
				'description' => __( 'true hides the page from search results.', 'viagent-production' ),
			),
			'nofollow'           => array(
				'type'        => 'boolean',
				'description' => __( 'true asks search engines not to follow links on the page.', 'viagent-production' ),
			),
			'social_title'       => array( 'type' => 'string' ),
			'social_description' => array( 'type' => 'string' ),
			'social_image'       => array(
				'type'        => 'string',
				'description' => __( 'Image URL used when the page is shared on social media (1200×630 is ideal).', 'viagent-production' ),
			),
			'schema'             => array(
				'type'        => 'string',
				'description' => __( 'Extra JSON-LD structured data for this page (e.g. FAQPage, HowTo, Product, LocalBusiness), as a JSON string without <script>. Posts and pages only.', 'viagent-production' ),
			),
		);
	}

	/**
	 * Permission to read or edit a post or term's SEO.
	 *
	 * @param array $input Input.
	 * @return bool
	 */
	public static function can_edit_object( $input ) {
		if ( ! empty( $input['term_id'] ) ) {
			return current_user_can( 'edit_term', (int) $input['term_id'] );
		}
		if ( ! empty( $input['post_id'] ) ) {
			return current_user_can( 'edit_post', (int) $input['post_id'] ) || ! get_post( (int) $input['post_id'] );
		}
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$post_id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$target  = array(
			'post_id' => $post_id,
			'term_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Category, tag or other term ID (instead of post_id).', 'viagent-production' ),
			),
		);
		$paging  = Abilities::paging( 50 );

		self::add(
			'get-seo-overview',
			__( 'SEO overview', 'viagent-production' ),
			__( 'Start here for SEO work: which SEO plugin is active, sitemap and robots.txt addresses, site-wide SEO problems (indexing blocked, plain permalinks, no HTTPS…), redirect and 404 counts, and which tools apply.', 'viagent-production' ),
			null,
			array(),
			array( self::class, 'overview' ),
			'edit_posts',
			Abilities::read_meta()
		);

		self::add(
			'get-seo',
			__( 'Get SEO settings', 'viagent-production' ),
			__( 'Gets the search title, meta description, focus keyword, canonical URL, noindex/nofollow, social sharing text and image, and custom schema of a post, page, product, category or tag.', 'viagent-production' ),
			$target,
			array(),
			array( self::class, 'get_seo' ),
			array( self::class, 'can_edit_object' ),
			Abilities::read_meta()
		);

		self::add(
			'update-seo',
			__( 'Update SEO settings', 'viagent-production' ),
			__( 'Sets SEO fields of a post, page, product (post_id) or category/tag (term_id). Only the fields you pass change; pass an empty string or false to reset one. Saved in the active SEO plugin\'s format.', 'viagent-production' ),
			array_merge( $target, self::field_schema() ),
			array(),
			array( self::class, 'update_seo' ),
			array( self::class, 'can_edit_object' ),
			Abilities::write_meta(
				Policy::CONTENT,
				array(
					'idempotent' => true,
					'draft_safe' => true,
				)
			)
		);

		self::add(
			'bulk-update-seo',
			__( 'Bulk update SEO', 'viagent-production' ),
			__( 'Sets SEO fields for up to 50 posts, pages or terms in one call, e.g. after find_seo_issues. Each item has post_id or term_id plus the fields to change. Undo restores all of them.', 'viagent-production' ),
			array(
				'items' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_BULK_ITEMS,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array_merge( $target, self::field_schema() ),
						'additionalProperties' => false,
					),
				),
			),
			array( 'items' ),
			array( self::class, 'bulk_update' ),
			'edit_posts',
			Abilities::write_meta(
				Policy::CONTENT,
				array(
					'idempotent' => true,
					'draft_safe' => true,
				)
			)
		);

		self::add(
			'analyze-seo',
			__( 'Analyze page SEO', 'viagent-production' ),
			__( 'Full SEO audit of one post or page with a 0–100 score: title and description length, focus keyword placement and density, headings, word count, readability, image alt text, internal/external links, keyword cannibalization, and (for published pages) what the live page really outputs: title tag, meta description, canonical, robots, H1, Open Graph, schema types, speed.', 'viagent-production' ),
			array(
				'post_id'       => $post_id,
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => __( 'Keyword to check (defaults to the saved focus keyword).', 'viagent-production' ),
				),
				'live'          => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Also fetch and check the live page.', 'viagent-production' ),
				),
			),
			array( 'post_id' ),
			array( Audit::class, 'analyze' ),
			array( self::class, 'can_edit_object' ),
			Abilities::read_meta()
		);

		self::add(
			'find-seo-issues',
			__( 'Find SEO issues', 'viagent-production' ),
			__( 'Site-wide SEO audit of published content: missing, short or long meta descriptions, long titles, missing focus keywords, duplicate titles/descriptions/keywords, noindex pages, thin content, missing featured images, images without alt text, no internal links and long URLs — plus site-level problems. Returns a summary by issue type.', 'viagent-production' ),
			array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'any',
				),
				'limit'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 500,
					'default' => 100,
				),
			),
			array(),
			array( Audit::class, 'find_issues' ),
			'edit_posts',
			Abilities::read_meta()
		);

		self::add(
			'suggest-internal-links',
			__( 'Suggest internal links', 'viagent-production' ),
			__( 'Finds internal linking opportunities for a post: other pages whose title or focus keyword appears in its text (direction "outbound"), or pages that mention this post and could link to it (direction "inbound", good for orphan pages). Returns anchor text and context.', 'viagent-production' ),
			array(
				'post_id'   => $post_id,
				'direction' => array(
					'type'    => 'string',
					'enum'    => array( 'outbound', 'inbound' ),
					'default' => 'outbound',
				),
				'limit'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				),
			),
			array( 'post_id' ),
			array( Audit::class, 'suggest_links' ),
			array( self::class, 'can_edit_object' ),
			Abilities::read_meta()
		);

		self::add(
			'check-links',
			__( 'Check for broken links', 'viagent-production' ),
			__( 'Checks the links in a post or page and lists broken ones (404s, server errors, unreachable sites).', 'viagent-production' ),
			array(
				'post_id' => $post_id,
				'limit'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 25,
				),
			),
			array( 'post_id' ),
			array( Audit::class, 'check_links' ),
			array( self::class, 'can_edit_object' ),
			Abilities::read_meta()
		);

		self::add(
			'get-seo-settings',
			__( 'Get site SEO settings', 'viagent-production' ),
			__( 'Gets site-wide SEO settings: homepage title and description, title separator, default social image, organization or person details for structured data, social profiles, search engine verification codes, archive indexing and 404 logging.', 'viagent-production' ),
			null,
			array(),
			array( self::class, 'get_settings' ),
			'manage_options',
			Abilities::read_meta( Policy::ADMIN )
		);

		self::add(
			'update-seo-settings',
			__( 'Update site SEO settings', 'viagent-production' ),
			__( 'Changes site-wide SEO settings. Only the settings you pass change. Verification codes are the content value only (e.g. from Google Search Console\'s HTML tag). search_engine_visibility true makes the site visible to search engines (it cannot be turned off here).', 'viagent-production' ),
			array(
				'home_title'               => array( 'type' => 'string' ),
				'home_description'         => array( 'type' => 'string' ),
				'title_separator'          => array(
					'type'      => 'string',
					'maxLength' => 5,
				),
				'default_social_image'     => array( 'type' => 'string' ),
				'twitter_username'         => array( 'type' => 'string' ),
				'organization_type'        => array(
					'type' => 'string',
					'enum' => array( 'Organization', 'Person' ),
				),
				'organization_name'        => array( 'type' => 'string' ),
				'organization_logo'        => array( 'type' => 'string' ),
				'social_profiles'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'verification'             => array(
					'type'                 => 'object',
					'properties'           => array_fill_keys( array_keys( Head::VERIFICATION ), array( 'type' => 'string' ) ),
					'additionalProperties' => false,
				),
				'noindex_author_archives'  => array( 'type' => 'boolean' ),
				'noindex_date_archives'    => array( 'type' => 'boolean' ),
				'log_404'                  => array( 'type' => 'boolean' ),
				'search_engine_visibility' => array(
					'type' => 'boolean',
					'enum' => array( true ),
				),
			),
			array(),
			array( self::class, 'update_settings' ),
			'manage_options',
			Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) )
		);

		self::add(
			'get-robots-txt',
			__( 'Get robots.txt', 'viagent-production' ),
			__( 'Gets the robots.txt that search engines see, and whether it is custom, generated by WordPress/the SEO plugin, or a physical file.', 'viagent-production' ),
			null,
			array(),
			array( self::class, 'get_robots' ),
			'manage_options',
			Abilities::read_meta( Policy::ADMIN )
		);

		self::add(
			'update-robots-txt',
			__( 'Update robots.txt', 'viagent-production' ),
			__( 'Replaces robots.txt with custom content (pass an empty string to go back to the default). Include a Sitemap: line. Never disallow the whole site unless the user asks.', 'viagent-production' ),
			array(
				'content' => array(
					'type'      => 'string',
					'maxLength' => 20000,
				),
			),
			array( 'content' ),
			array( self::class, 'update_robots' ),
			'manage_options',
			Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) )
		);

		self::add(
			'list-redirects',
			__( 'List redirects', 'viagent-production' ),
			__( 'Lists redirects with their hit counts. Search by source or target.', 'viagent-production' ),
			array_merge( array( 'search' => array( 'type' => 'string' ) ), $paging ),
			array(),
			array( Redirects::class, 'list_redirects' ),
			'manage_options',
			Abilities::read_meta( Policy::ADMIN )
		);

		self::add(
			'create-redirect',
			__( 'Create redirect', 'viagent-production' ),
			__( 'Redirects an old URL to a new one: 301 (permanent, default), 302/307 (temporary), or marks it 410 (gone) / 451. source is a path like /old-page (or a regex with match_type "regex", where $1 in the target inserts a group). target is a path or full URL.', 'viagent-production' ),
			array(
				'source'     => array( 'type' => 'string' ),
				'target'     => array( 'type' => 'string' ),
				'type'       => array(
					'type'    => 'integer',
					'enum'    => Redirects::TYPES,
					'default' => 301,
				),
				'match_type' => array(
					'type'    => 'string',
					'enum'    => array( 'exact', 'regex' ),
					'default' => 'exact',
				),
				'note'       => array( 'type' => 'string' ),
			),
			array( 'source' ),
			array( self::class, 'create_redirect' ),
			'manage_options',
			Abilities::write_meta( Policy::ADMIN )
		);

		self::add(
			'delete-redirect',
			__( 'Delete redirect', 'viagent-production' ),
			__( 'Deletes a redirect by ID (see list_redirects).', 'viagent-production' ),
			array( 'id' => $post_id ),
			array( 'id' ),
			array( self::class, 'delete_redirect' ),
			'manage_options',
			Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) )
		);

		self::add(
			'list-404-errors',
			__( 'List 404 errors', 'viagent-production' ),
			__( 'Lists URLs visitors and crawlers requested that do not exist (404s), with hit counts and referrers. Fix important ones with create_redirect.', 'viagent-production' ),
			array_merge(
				array(
					'order' => array(
						'type'    => 'string',
						'enum'    => array( 'hits', 'recent' ),
						'default' => 'hits',
					),
				),
				$paging
			),
			array(),
			array( Redirects::class, 'list_404' ),
			'manage_options',
			Abilities::read_meta( Policy::ADMIN )
		);

		self::add(
			'clear-404-errors',
			__( 'Clear 404 log', 'viagent-production' ),
			__( 'Clears the 404 log, or just one path.', 'viagent-production' ),
			array( 'path' => array( 'type' => 'string' ) ),
			array(),
			array( Redirects::class, 'clear_404' ),
			'manage_options',
			Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) )
		);

		self::add(
			'request-indexing',
			__( 'Request indexing', 'viagent-production' ),
			__( 'Notifies search engines that support IndexNow (Bing, Yandex, Seznam, Naver…) that pages were added or changed, so they are crawled sooner. Pass post_ids or urls on this site. Google does not use IndexNow; it picks changes up from the sitemap.', 'viagent-production' ),
			array(
				'post_ids' => array(
					'type'     => 'array',
					'items'    => $post_id,
					'maxItems' => 100,
				),
				'urls'     => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'maxItems' => 100,
				),
			),
			array(),
			array( self::class, 'request_indexing' ),
			'manage_options',
			Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) )
		);
	}

	/**
	 * Registers one ability in the SEO category.
	 *
	 * @param string          $name        Name.
	 * @param string          $label       Label.
	 * @param string          $description Description.
	 * @param array|null      $input       Input properties.
	 * @param string[]        $required    Required properties.
	 * @param callable        $execute     Execute callback.
	 * @param string|callable $permission  Capability or callback.
	 * @param array           $meta        Meta.
	 */
	private static function add( $name, $label, $description, $input, array $required, $execute, $permission, array $meta ) {
		$args = array(
			'category'    => self::CATEGORY,
			'label'       => $label,
			'description' => $description,
			'execute'     => $execute,
			'permission'  => $permission,
			'meta'        => $meta,
		);
		if ( null !== $input ) {
			$args['input'] = $input;
		}
		if ( $required ) {
			$args['required'] = $required;
		}
		Abilities::add( $name, $args );
	}

	/**
	 * Resolves the post or term an input points at.
	 *
	 * @param array $input Input with post_id or term_id.
	 * @return array{type:string,id:int,label:string,link:string,post:?\WP_Post}|WP_Error
	 */
	private static function target( array $input ) {
		if ( ! empty( $input['term_id'] ) ) {
			$term = get_term( (int) $input['term_id'] );
			if ( ! $term || is_wp_error( $term ) || ! is_taxonomy_viewable( $term->taxonomy ) ) {
				return new WP_Error( 'viagent_production_not_found', __( 'No public category, tag or term found with that ID.', 'viagent-production' ) );
			}
			$link = get_term_link( $term );
			return array(
				'type'  => 'term',
				'id'    => $term->term_id,
				'label' => $term->name,
				'link'  => is_wp_error( $link ) ? '' : $link,
				'post'  => null,
			);
		}
		$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'Pass the post_id of a public post, page or product, or a term_id.', 'viagent-production' ) );
		}
		return array(
			'type'  => 'post',
			'id'    => $post->ID,
			'label' => $post->post_title,
			'link'  => (string) get_permalink( $post ),
			'post'  => $post,
		);
	}

	/**
	 * SEO overview.
	 *
	 * @return array
	 */
	public static function overview() {
		global $wpdb;
		$robots_url = home_url( '/robots.txt' );
		$result     = array(
			'provider'          => Store::provider_name(),
			'built_in_output'   => 'native' === Store::provider(),
			'site_url'          => home_url( '/' ),
			'search_engines'    => get_option( 'blog_public' ) ? 'allowed' : 'discouraged (the site is hidden from search engines)',
			'permalinks'        => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain',
			'https'             => 0 === strpos( home_url(), 'https://' ),
			'sitemap'           => Head::sitemap_url(),
			'robots_txt'        => $robots_url,
			'robots_txt_custom' => '' !== trim( (string) get_option( Head::ROBOTS_OPTION, '' ) ),
			'site_issues'       => Audit::site_checks(),
			'published'         => array(),
			'redirects'         => 0,
			'not_found_urls'    => 0,
			'notes'             => array(),
		);
		foreach ( Audit::post_types() as $type ) {
			$result['published'][ $type ] = (int) wp_count_posts( $type )->publish;
		}
		if ( current_user_can( 'manage_options' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counting rows in Viagent Production's own tables.
			$result['redirects']      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \ViagentProduction\Installer::table( 'seo_redirects' ) ) );
			$result['not_found_urls'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \ViagentProduction\Installer::table( 'seo_404' ) ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		if ( 'native' === Store::provider() ) {
			$result['notes'][] = 'No SEO plugin is active, so Viagent Production outputs titles, meta descriptions, canonical, robots, Open Graph, Twitter cards and JSON-LD itself. Configure site-wide details with update_seo_settings.';
		} else {
			$result['notes'][] = sprintf( '%s outputs the SEO tags. get_seo/update_seo read and write its fields; its own site-wide settings (title templates, schema, social) are managed in its settings screens. Custom schema, verification codes, robots.txt and redirects from Viagent Production work alongside it.', Store::provider_name() );
		}
		return $result;
	}

	/**
	 * Gets SEO fields.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_seo( $input ) {
		$target = self::target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$fields = Store::read( $target['type'], $target['id'] );
		$extra  = array();
		if ( $target['post'] ) {
			$extra['effective_title'] = Audit::effective_title( $target['post'], $fields );
		}
		return array_merge(
			array(
				'type'     => $target['type'],
				'id'       => $target['id'],
				'name'     => $target['label'],
				'link'     => $target['link'],
				'provider' => Store::provider_name(),
			),
			$fields,
			$extra,
			array(
				'title_length'       => mb_strlen( $fields['title'] ),
				'description_length' => mb_strlen( $fields['description'] ),
			)
		);
	}

	/**
	 * Cleans the field values of an input.
	 *
	 * @param array  $input Input.
	 * @param string $type  "post" or "term".
	 * @return array|WP_Error
	 */
	private static function clean_fields( array $input, $type ) {
		$allowed = 'term' === $type ? Store::TERM_FIELDS : Store::POST_FIELDS;
		$fields  = array();
		foreach ( array_keys( self::field_schema() ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			if ( ! in_array( $field, $allowed, true ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'viagent_production_unsupported_field', sprintf( __( 'The field "%s" is only available for posts and pages.', 'viagent-production' ), $field ) );
			}
			$value = Store::sanitize( $field, $input[ $field ] );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$fields[ $field ] = $value;
		}
		if ( ! $fields ) {
			return new WP_Error( 'viagent_production_nothing_to_update', __( 'Pass at least one SEO field to change.', 'viagent-production' ) );
		}
		return $fields;
	}

	/**
	 * Applies a change to one target and returns its undo snapshot.
	 *
	 * @param array $input Input with post_id/term_id and fields.
	 * @return array{target:array,fields:array,previous:array}|WP_Error
	 */
	private static function apply_change( array $input ) {
		$target = self::target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! self::can_edit_object( $input ) ) {
			/* translators: %d: object ID */
			return new WP_Error( 'viagent_production_forbidden', sprintf( __( 'You are not allowed to edit item %d.', 'viagent-production' ), $target['id'] ) );
		}
		if ( $target['post'] ) {
			$editable = Policy::check_post_editable( $target['post'] );
			if ( is_wp_error( $editable ) ) {
				return $editable;
			}
		} elseif ( 'term' === $target['type'] && Policy::is_draft_only() ) {
			return new WP_Error(
				'viagent_production_draft_only',
				__( 'Draft-only mode is on, so the SEO settings of categories, tags and other terms (which visitors already see) cannot be changed. Ask the site owner for more access in Viagent Production.', 'viagent-production' )
			);
		}
		$fields = self::clean_fields( $input, $target['type'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$previous = array_intersect_key( Store::read( $target['type'], $target['id'] ), $fields );
		$written  = Store::write( $target['type'], $target['id'], $fields );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		return array(
			'target'   => $target,
			'fields'   => array_keys( $fields ),
			'previous' => $previous,
		);
	}

	/**
	 * Updates SEO fields.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_seo( $input ) {
		$change = self::apply_change( $input );
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		$target = $change['target'];

		Activity_Log::set_object( $target['type'], $target['id'] );
		Activity_Log::set_undo(
			array(
				'action' => 'seo_restore',
				'items'  => array(
					array(
						'type'   => $target['type'],
						'id'     => $target['id'],
						'fields' => $change['previous'],
					),
				),
			)
		);

		return array_merge(
			array(
				'type'    => $target['type'],
				'id'      => $target['id'],
				'updated' => $change['fields'],
			),
			Store::read( $target['type'], $target['id'] )
		);
	}

	/**
	 * Updates SEO fields of many objects.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function bulk_update( $input ) {
		$undo    = array();
		$updated = array();
		$errors  = array();

		foreach ( array_slice( (array) $input['items'], 0, self::MAX_BULK_ITEMS ) as $index => $item ) {
			$change = self::apply_change( (array) $item );
			if ( is_wp_error( $change ) ) {
				$errors[] = array(
					'index'   => $index,
					'id'      => (int) ( $item['post_id'] ?? $item['term_id'] ?? 0 ),
					'message' => $change->get_error_message(),
				);
				continue;
			}
			$undo[]    = array(
				'type'   => $change['target']['type'],
				'id'     => $change['target']['id'],
				'fields' => $change['previous'],
			);
			$updated[] = array(
				'type'    => $change['target']['type'],
				'id'      => $change['target']['id'],
				'name'    => $change['target']['label'],
				'updated' => $change['fields'],
			);
		}

		if ( $undo ) {
			Activity_Log::set_undo(
				array(
					'action' => 'seo_restore',
					'items'  => $undo,
				)
			);
		}
		return array(
			'updated' => $updated,
			'failed'  => $errors,
		);
	}

	/**
	 * Gets site-wide SEO settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = Head::settings();
		$native   = 'native' === Store::provider();
		return array(
			'provider'                 => Store::provider_name(),
			'settings'                 => $settings,
			'search_engine_visibility' => (bool) get_option( 'blog_public' ),
			'applies'                  => $native
				? 'All settings apply.'
				: sprintf( 'With %s active, only verification, log_404 and search_engine_visibility apply; titles, social and organization settings are managed in %s.', Store::provider_name(), Store::provider_name() ),
		);
	}

	/**
	 * Updates site-wide SEO settings.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_settings( $input ) {
		$before   = get_option( Head::SETTINGS_OPTION, null );
		$settings = Head::settings();
		$changed  = array();

		foreach ( array( 'home_title', 'home_description', 'title_separator', 'twitter_username', 'organization_name' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = sanitize_text_field( $input[ $key ] );
				$changed[]        = $key;
			}
		}
		foreach ( array( 'default_social_image', 'organization_logo' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = esc_url_raw( $input[ $key ] );
				$changed[]        = $key;
			}
		}
		foreach ( array( 'noindex_author_archives', 'noindex_date_archives', 'log_404' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = (bool) $input[ $key ];
				$changed[]        = $key;
			}
		}
		if ( isset( $input['organization_type'] ) ) {
			$settings['organization_type'] = 'Person' === $input['organization_type'] ? 'Person' : 'Organization';
			$changed[]                     = 'organization_type';
		}
		if ( isset( $input['social_profiles'] ) ) {
			$settings['social_profiles'] = array_values( array_filter( array_map( 'esc_url_raw', (array) $input['social_profiles'] ) ) );
			$changed[]                   = 'social_profiles';
		}
		if ( isset( $input['verification'] ) ) {
			foreach ( (array) $input['verification'] as $engine => $code ) {
				if ( isset( Head::VERIFICATION[ $engine ] ) ) {
					// Accept a pasted meta tag too: keep only the content value.
					if ( preg_match( '/content=["\']([^"\']+)["\']/i', (string) $code, $m ) ) {
						$code = $m[1];
					}
					$settings['verification'][ $engine ] = sanitize_text_field( $code );
				}
			}
			$changed[] = 'verification';
		}

		$visibility_before = get_option( 'blog_public' );
		if ( ! empty( $input['search_engine_visibility'] ) && ! $visibility_before ) {
			update_option( 'blog_public', '1' );
			$changed[] = 'search_engine_visibility';
		}

		if ( ! $changed ) {
			return new WP_Error( 'viagent_production_nothing_to_update', __( 'Pass at least one setting to change.', 'viagent-production' ) );
		}
		update_option( Head::SETTINGS_OPTION, $settings, false );

		Activity_Log::set_undo(
			array(
				'action'      => 'seo_restore_settings',
				'settings'    => $before,
				'blog_public' => in_array( 'search_engine_visibility', $changed, true ) ? $visibility_before : null,
			)
		);
		return array_merge( array( 'updated' => $changed ), self::get_settings() );
	}

	/**
	 * Gets robots.txt.
	 *
	 * @return array
	 */
	public static function get_robots() {
		$public = (bool) get_option( 'blog_public' );
		$custom = (string) get_option( Head::ROBOTS_OPTION, '' );
		$file   = file_exists( ABSPATH . 'robots.txt' );
		$output = '';
		if ( $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file in the site root.
			$output = (string) file_get_contents( ABSPATH . 'robots.txt' );
		} else {
			ob_start();
			do_robots();
			$output = (string) ob_get_clean();
		}
		return array(
			'url'     => home_url( '/robots.txt' ),
			'source'  => $file ? 'physical file (WordPress settings are ignored)' : ( '' !== trim( $custom ) && $public ? 'custom (Viagent Production)' : 'generated by WordPress' . ( 'native' !== Store::provider() ? ' and ' . Store::provider_name() : '' ) ),
			'content' => $output,
			'sitemap' => Head::sitemap_url(),
		);
	}

	/**
	 * Updates robots.txt.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_robots( $input ) {
		$content = str_replace( "\r\n", "\n", wp_strip_all_tags( (string) $input['content'] ) );
		$before  = (string) get_option( Head::ROBOTS_OPTION, '' );
		update_option( Head::ROBOTS_OPTION, $content, false );
		Activity_Log::set_undo(
			array(
				'action'  => 'seo_restore_robots',
				'content' => $before,
			)
		);
		$result = self::get_robots();
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			$result['warning'] = 'A physical robots.txt file exists in the site root, so this custom content is not served. Delete the file to use it.';
		}
		if ( preg_match( '#^\s*Disallow:\s*/\s*$#mi', $content ) ) {
			$result['warning'] = 'This robots.txt blocks crawlers from the whole site.';
		}
		return $result;
	}

	/**
	 * Creates a redirect.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_redirect( $input ) {
		$result = Redirects::create( $input );
		if ( ! is_wp_error( $result ) ) {
			Activity_Log::set_undo(
				array(
					'action' => 'seo_delete_redirect',
					'id'     => $result['id'],
				)
			);
		}
		return $result;
	}

	/**
	 * Deletes a redirect.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_redirect( $input ) {
		$row = Redirects::get( (int) $input['id'] );
		if ( ! $row ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No redirect found with that ID.', 'viagent-production' ) );
		}
		Redirects::delete( $row->id );
		Activity_Log::set_undo(
			array(
				'action' => 'seo_restore_redirect',
				'row'    => (array) $row,
			)
		);
		return array(
			'deleted'  => true,
			'redirect' => Redirects::item( $row ),
		);
	}

	/**
	 * IndexNow key, created on first use.
	 *
	 * @return string
	 */
	public static function indexnow_key() {
		$key = (string) get_option( self::INDEXNOW_KEY, '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( self::INDEXNOW_KEY, $key, false );
		}
		return $key;
	}

	/**
	 * Serves the IndexNow key file at /{key}.txt.
	 */
	public static function serve_indexnow_key() {
		$key = (string) get_option( self::INDEXNOW_KEY, '' );
		if ( '' === $key || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		// sanitize_text_field() would strip %XX sequences from the URI; the path is only compared with the key below, never output.
		$path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and UTF-8 checked on the next line; compared only.
		$path = wp_check_invalid_utf8( rawurldecode( $path ) );
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( $home . '/' . $key . '.txt' === $path ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $key );
			exit;
		}
	}

	/**
	 * Submits URLs to IndexNow.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function request_indexing( $input ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array();
		foreach ( (array) ( $input['post_ids'] ?? array() ) as $id ) {
			$post = get_post( (int) $id );
			if ( $post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
				$urls[] = get_permalink( $post );
			}
		}
		foreach ( (array) ( $input['urls'] ?? array() ) as $url ) {
			$url = esc_url_raw( 0 === strpos( $url, '/' ) ? home_url( $url ) : $url );
			if ( $url && wp_parse_url( $url, PHP_URL_HOST ) === $host ) {
				$urls[] = $url;
			}
		}
		$urls = array_values( array_unique( $urls ) );
		if ( ! $urls ) {
			return new WP_Error( 'viagent_production_missing_argument', __( 'Pass published post_ids or urls on this site.', 'viagent-production' ) );
		}
		if ( preg_match( '/(^localhost$|\.local$|\.test$|\.localhost$|^127\.|^10\.|^192\.168\.)/', (string) $host ) ) {
			return new WP_Error( 'viagent_production_local_site', __( 'This site runs on a local address that search engines cannot reach, so indexing cannot be requested. Do this on the live site.', 'viagent-production' ) );
		}

		$key      = self::indexnow_key();
		$response = wp_remote_post(
			self::INDEXNOW_API,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => $urls,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$messages = array(
			200 => 'Submitted. Search engines will crawl the URLs soon.',
			202 => 'Accepted. The key is being verified; the URLs will be crawled once it is confirmed.',
			400 => 'Bad request.',
			403 => 'The key could not be verified. Make sure ' . home_url( '/' . $key . '.txt' ) . ' is publicly reachable.',
			422 => 'Some URLs do not belong to this site or the key does not match.',
			429 => 'Too many requests; try again later.',
		);
		$result   = array(
			'submitted' => $urls,
			'status'    => $code,
			'message'   => $messages[ $code ] ?? 'Unexpected response from IndexNow.',
		);
		if ( $code >= 400 ) {
			return new WP_Error( 'viagent_production_indexnow_failed', $result['message'] );
		}
		Activity_Log::set_object( 'post', (int) ( $input['post_ids'][0] ?? 0 ) );
		return $result;
	}

	/**
	 * Undo handlers for SEO changes.
	 *
	 * @param mixed $result Result from earlier handlers.
	 * @param array $undo   Undo instructions.
	 * @return mixed
	 */
	public static function apply_undo( $result, $undo ) {
		if ( null !== $result ) {
			return $result;
		}
		switch ( $undo['action'] ?? '' ) {
			case 'seo_restore':
				foreach ( (array) $undo['items'] as $item ) {
					$key = 'term' === $item['type'] ? 'term_id' : 'post_id';
					if ( ! self::can_edit_object( array( $key => $item['id'] ) ) ) {
						return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change one of these items.', 'viagent-production' ) );
					}
					$written = Store::write( $item['type'], (int) $item['id'], (array) $item['fields'] );
					if ( is_wp_error( $written ) ) {
						return $written;
					}
				}
				return true;

			case 'seo_restore_settings':
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change settings.', 'viagent-production' ) );
				}
				null === $undo['settings'] ? delete_option( Head::SETTINGS_OPTION ) : update_option( Head::SETTINGS_OPTION, $undo['settings'], false );
				if ( null !== ( $undo['blog_public'] ?? null ) ) {
					update_option( 'blog_public', $undo['blog_public'] );
				}
				return true;

			case 'seo_restore_robots':
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change settings.', 'viagent-production' ) );
				}
				update_option( Head::ROBOTS_OPTION, (string) $undo['content'], false );
				return true;

			case 'seo_delete_redirect':
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change redirects.', 'viagent-production' ) );
				}
				Redirects::delete( (int) $undo['id'] );
				return true;

			case 'seo_restore_redirect':
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change redirects.', 'viagent-production' ) );
				}
				return Redirects::restore( (array) $undo['row'] );
		}
		return null;
	}

	/**
	 * Undo descriptions for the Activity screen.
	 *
	 * @param string|null $label Label.
	 * @param array       $undo  Undo instructions.
	 * @return string|null
	 */
	public static function undo_label( $label, $undo ) {
		switch ( $undo['action'] ?? '' ) {
			case 'seo_restore':
				return count( (array) $undo['items'] ) > 1
					/* translators: %d: number of items */
					? sprintf( __( 'The SEO settings of %d items will go back to how they were.', 'viagent-production' ), count( $undo['items'] ) )
					: __( 'The SEO title, description and other SEO settings will go back to how they were.', 'viagent-production' );
			case 'seo_restore_settings':
				return __( 'The site-wide SEO settings will go back to how they were.', 'viagent-production' );
			case 'seo_restore_robots':
				return __( 'robots.txt will go back to how it was.', 'viagent-production' );
			case 'seo_delete_redirect':
				return __( 'The redirect will be removed.', 'viagent-production' );
			case 'seo_restore_redirect':
				return __( 'The redirect will be added back.', 'viagent-production' );
		}
		return $label;
	}

	/**
	 * Tool summaries for the Tools screen.
	 *
	 * @param array $summaries Summaries.
	 * @return array
	 */
	public static function summaries( $summaries ) {
		return array_merge(
			$summaries,
			array(
				'get_seo_overview'       => __( 'See the SEO setup and site-wide SEO problems.', 'viagent-production' ),
				'get_seo'                => __( 'Read the search title and description of a page.', 'viagent-production' ),
				'update_seo'             => __( 'Write search titles, descriptions, keywords and social text.', 'viagent-production' ),
				'bulk_update_seo'        => __( 'Fix SEO for many pages at once.', 'viagent-production' ),
				'analyze_seo'            => __( 'Run a full SEO audit of a page.', 'viagent-production' ),
				'find_seo_issues'        => __( 'Find pages with missing or weak SEO across the site.', 'viagent-production' ),
				'suggest_internal_links' => __( 'Find internal linking opportunities.', 'viagent-production' ),
				'check_links'            => __( 'Find broken links in a page.', 'viagent-production' ),
				'get_seo_settings'       => __( 'See site-wide SEO settings.', 'viagent-production' ),
				'update_seo_settings'    => __( 'Change homepage SEO, verification codes and structured data details.', 'viagent-production' ),
				'get_robots_txt'         => __( 'See robots.txt.', 'viagent-production' ),
				'update_robots_txt'      => __( 'Edit robots.txt.', 'viagent-production' ),
				'list_redirects'         => __( 'See redirects.', 'viagent-production' ),
				'create_redirect'        => __( 'Redirect old URLs to new ones.', 'viagent-production' ),
				'delete_redirect'        => __( 'Remove redirects.', 'viagent-production' ),
				'list_404_errors'        => __( 'See broken URLs visitors are hitting.', 'viagent-production' ),
				'clear_404_errors'       => __( 'Clear the 404 log.', 'viagent-production' ),
				'request_indexing'       => __( 'Ask Bing and other search engines to crawl pages now.', 'viagent-production' ),
			)
		);
	}
}
