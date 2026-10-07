<?php
/**
 * Content formats: lets AI agents send block markup, HTML or Markdown. HTML
 * and Markdown are converted to core blocks so the result stays editable in
 * the block editor, and block markup is checked for common mistakes.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Content format conversion and validation.
 */
class Content_Format {

	const FORMATS = array( 'auto', 'blocks', 'html', 'markdown' );

	/**
	 * Inline elements that belong inside a paragraph.
	 */
	const INLINE = array( 'a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'kbd', 'mark', 'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u' );

	/**
	 * Containers whose children are converted as if they were top level.
	 */
	const CONTAINERS = array( 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'body' );

	/**
	 * Guesses the format of a piece of content.
	 *
	 * @param string $content Content.
	 * @return string blocks, html or markdown.
	 */
	public static function detect( $content ) {
		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			return 'blocks';
		}
		if ( preg_match( '#<(p|h[1-6]|ul|ol|li|div|table|img|blockquote|figure|section|br|strong|em|a|pre|hr|iframe)\b#i', $content ) ) {
			return 'html';
		}
		return 'markdown';
	}

	/**
	 * Prepares content for saving.
	 *
	 * @param string $content      Content.
	 * @param string $format       auto, blocks, html or markdown.
	 * @param bool   $block_editor Whether the post type uses the block editor.
	 * @return string
	 */
	public static function prepare( $content, $format = 'auto', $block_editor = true ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return $content;
		}
		$format = 'auto' === $format || ! in_array( $format, self::FORMATS, true ) ? self::detect( $content ) : $format;
		if ( 'blocks' === $format ) {
			return $content;
		}
		$html = 'markdown' === $format ? self::markdown_to_html( $content ) : $content;
		return $block_editor ? self::html_to_blocks( $html ) : $html;
	}

	/**
	 * Serializes one block.
	 *
	 * @param string $name  Block name (core blocks without the "core/" prefix).
	 * @param array  $attrs Attributes.
	 * @param string $html  Inner HTML.
	 * @return string
	 */
	private static function block( $name, array $attrs, $html ) {
		$attrs = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
		return "<!-- wp:{$name}{$attrs} -->\n{$html}\n<!-- /wp:{$name} -->";
	}

	/**
	 * Inner HTML of a DOM node.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function inner( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			$html .= $node->ownerDocument->saveHTML( $child ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		}
		return trim( $html );
	}

	/**
	 * Outer HTML of a DOM node.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function outer( \DOMNode $node ) {
		return trim( $node->ownerDocument->saveHTML( $node ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
	}

	/**
	 * Converts HTML to core block markup.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_blocks( $html ) {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="viagent-production-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		$root = $doc->getElementById( 'viagent-production-root' );
		return $root ? implode( "\n\n", self::convert_children( $root ) ) : $html;
	}

	/**
	 * Converts the children of a node to a list of blocks.
	 *
	 * @param \DOMNode $container Node.
	 * @return string[]
	 */
	private static function convert_children( \DOMNode $container ) {
		$blocks = array();
		$inline = '';
		$flush  = static function () use ( &$inline, &$blocks ) {
			if ( '' !== trim( wp_strip_all_tags( $inline, true ) ) || false !== stripos( $inline, '<img' ) ) {
				$blocks[] = self::block( 'paragraph', array(), '<p>' . trim( $inline ) . '</p>' );
			}
			$inline = '';
		};

		foreach ( $container->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			if ( XML_TEXT_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				$inline .= esc_html( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				continue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				continue;
			}
			$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			if ( in_array( $tag, self::INLINE, true ) ) {
				$inline .= self::outer( $node );
				continue;
			}
			$flush();
			if ( in_array( $tag, self::CONTAINERS, true ) ) {
				$blocks = array_merge( $blocks, self::convert_children( $node ) );
				continue;
			}
			$block = self::convert_element( $node, $tag );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}
		$flush();
		return $blocks;
	}

	/**
	 * Converts one block-level element.
	 *
	 * @param \DOMElement $node Element.
	 * @param string      $tag  Lowercase tag name.
	 * @return string
	 */
	private static function convert_element( \DOMElement $node, $tag ) {
		switch ( $tag ) {
			case 'p':
				$inner = self::inner( $node );
				return '' === trim( wp_strip_all_tags( $inner ) ) && false === stripos( $inner, '<img' ) ? '' : self::block( 'paragraph', array(), '<p>' . $inner . '</p>' );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return self::block( 'heading', 2 === $level ? array() : array( 'level' => $level ), "<{$tag} class=\"wp-block-heading\">" . self::inner( $node ) . "</{$tag}>" );

			case 'ul':
			case 'ol':
				return self::convert_list( $node, $tag );

			case 'blockquote':
				$inner = self::convert_children( $node );
				return self::block( 'quote', array(), '<blockquote class="wp-block-quote">' . implode( "\n\n", $inner ) . '</blockquote>' );

			case 'pre':
				return self::block( 'code', array(), '<pre class="wp-block-code"><code>' . esc_html( $node->textContent ) . '</code></pre>' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.

			case 'hr':
				return self::block( 'separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );

			case 'img':
				return self::image( $node, '' );

			case 'figure':
				$img = $node->getElementsByTagName( 'img' )->item( 0 );
				if ( $img && ! $node->getElementsByTagName( 'table' )->length ) {
					$caption = $node->getElementsByTagName( 'figcaption' )->item( 0 );
					return self::image( $img, $caption ? self::inner( $caption ) : '' );
				}
				return self::block( 'html', array(), self::outer( $node ) );
		}
		// Tables, embeds, forms and anything else stay exactly as written.
		return self::block( 'html', array(), self::outer( $node ) );
	}

	/**
	 * Converts a list (with nested lists) to list blocks.
	 *
	 * @param \DOMElement $node List element.
	 * @param string      $tag  ul or ol.
	 * @return string
	 */
	private static function convert_list( \DOMElement $node, $tag ) {
		$items = array();
		foreach ( $node->childNodes as $li ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				continue;
			}
			$text   = '';
			$nested = '';
			foreach ( $li->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				$child_tag = XML_ELEMENT_NODE === $child->nodeType ? strtolower( $child->nodeName ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				if ( 'ul' === $child_tag || 'ol' === $child_tag ) {
					$nested .= self::convert_list( $child, $child_tag );
				} elseif ( 'p' === $child_tag ) {
					$text .= self::inner( $child );
				} else {
					$text .= XML_TEXT_NODE === $child->nodeType ? esc_html( $child->textContent ) : self::outer( $child ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				}
			}
			$items[] = self::block( 'list-item', array(), '<li>' . trim( $text ) . $nested . '</li>' );
		}
		$attrs = 'ol' === $tag ? array( 'ordered' => true ) : array();
		return self::block( 'list', $attrs, "<{$tag} class=\"wp-block-list\">" . implode( "\n\n", $items ) . "</{$tag}>" );
	}

	/**
	 * Image block from an img element.
	 *
	 * @param \DOMElement $img     Image element.
	 * @param string      $caption Caption HTML.
	 * @return string
	 */
	private static function image( \DOMElement $img, $caption ) {
		$src   = $img->getAttribute( 'src' );
		$alt   = $img->getAttribute( 'alt' );
		$id    = $src ? (int) attachment_url_to_postid( $src ) : 0;
		$attrs = $id ? array( 'id' => $id ) : array();
		$class = $id ? ' class="wp-image-' . $id . '"' : '';
		$html  = '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . $class . '/>';
		if ( '' !== trim( $caption ) ) {
			$html .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}
		return self::block( 'image', $attrs, $html . '</figure>' );
	}

	/**
	 * Converts Markdown (CommonMark/GitHub style essentials) to HTML.
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	public static function markdown_to_html( $markdown ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $markdown );
		$html  = array();
		$count = count( $lines );
		$i     = 0;

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( '' === trim( $line ) ) {
				++$i;
				continue;
			}

			// Fenced code.
			if ( preg_match( '/^\s*(```|~~~)\s*([\w+-]*)\s*$/', $line, $m ) ) {
				$code = array();
				++$i;
				while ( $i < $count && ! preg_match( '/^\s*' . preg_quote( $m[1], '/' ) . '\s*$/', $lines[ $i ] ) ) {
					$code[] = $lines[ $i ];
					++$i;
				}
				++$i;
				$html[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
				continue;
			}

			// Headings.
			if ( preg_match( '/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m ) ) {
				$level  = strlen( $m[1] );
				$html[] = "<h{$level}>" . self::inline_markdown( $m[2] ) . "</h{$level}>";
				++$i;
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^\s*([-*_])(\s*\1){2,}\s*$/', $line ) ) {
				$html[] = '<hr>';
				++$i;
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^\s*>/', $line ) ) {
				$quote = array();
				while ( $i < $count && preg_match( '/^\s*>\s?(.*)$/', $lines[ $i ], $m ) ) {
					$quote[] = $m[1];
					++$i;
				}
				$html[] = '<blockquote>' . self::markdown_to_html( implode( "\n", $quote ) ) . '</blockquote>';
				continue;
			}

			// Table.
			if ( false !== strpos( $line, '|' ) && $i + 1 < $count && preg_match( '/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[ $i + 1 ] ) ) {
				$cells = static function ( $row ) {
					return array_map( 'trim', explode( '|', trim( trim( $row ), '|' ) ) );
				};
				$table = '<table><thead><tr>';
				foreach ( $cells( $line ) as $cell ) {
					$table .= '<th>' . self::inline_markdown( $cell ) . '</th>';
				}
				$table .= '</tr></thead><tbody>';
				$i     += 2;
				while ( $i < $count && false !== strpos( $lines[ $i ], '|' ) && '' !== trim( $lines[ $i ] ) ) {
					$table .= '<tr>';
					foreach ( $cells( $lines[ $i ] ) as $cell ) {
						$table .= '<td>' . self::inline_markdown( $cell ) . '</td>';
					}
					$table .= '</tr>';
					++$i;
				}
				$html[] = $table . '</tbody></table>';
				continue;
			}

			// Lists.
			if ( preg_match( '/^(\s*)([-*+]|\d+[.)])\s+/', $line ) ) {
				$block = array();
				while ( $i < $count && ( preg_match( '/^(\s*)([-*+]|\d+[.)])\s+/', $lines[ $i ] ) || ( '' !== trim( $lines[ $i ] ) && preg_match( '/^\s{2,}\S/', $lines[ $i ] ) ) ) ) {
					$block[] = $lines[ $i ];
					++$i;
				}
				$html[] = self::markdown_list( $block );
				continue;
			}

			// Paragraph: consecutive lines until a blank line or another block starts.
			$para = array();
			while ( $i < $count && '' !== trim( $lines[ $i ] ) && ! preg_match( '/^(#{1,6}\s|\s*>|\s*([-*+]|\d+[.)])\s+|\s*(```|~~~))/', $lines[ $i ] ) ) {
				$para[] = trim( $lines[ $i ] );
				++$i;
			}
			if ( ! $para ) {
				$para[] = trim( $lines[ $i ] );
				++$i;
			}
			$text = implode( ' ', $para );
			// An image on its own line becomes a figure, so it converts to an image block.
			if ( preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)$/', $text, $m ) ) {
				$html[] = '<figure><img src="' . esc_url( $m[2] ) . '" alt="' . esc_attr( $m[1] ) . '"></figure>';
			} else {
				$html[] = '<p>' . self::inline_markdown( $text ) . '</p>';
			}
		}

		return implode( "\n", $html );
	}

	/**
	 * Converts a Markdown list (with nesting by indentation) to HTML.
	 *
	 * @param string[] $lines List lines.
	 * @return string
	 */
	private static function markdown_list( array $lines ) {
		preg_match( '/^(\s*)([-*+]|\d+[.)])\s+/', $lines[0], $m );
		$indent  = strlen( str_replace( "\t", '    ', $m[1] ) );
		$ordered = ctype_digit( rtrim( $m[2], '.)' ) );
		$items   = array();
		$current = null;
		$child   = array();

		$close = static function () use ( &$items, &$current, &$child ) {
			if ( null !== $current ) {
				$items[] = '<li>' . self::inline_markdown( $current ) . ( $child ? self::markdown_list( $child ) : '' ) . '</li>';
			}
			$current = null;
			$child   = array();
		};

		foreach ( $lines as $line ) {
			$line_indent = strlen( str_replace( "\t", '    ', preg_replace( '/^(\s*).*$/s', '$1', $line ) ) );
			if ( $line_indent <= $indent && preg_match( '/^\s*([-*+]|\d+[.)])\s+(.*)$/', $line, $item ) ) {
				$close();
				$current = $item[2];
			} elseif ( preg_match( '/^\s*([-*+]|\d+[.)])\s+/', $line ) ) {
				$child[] = $line;
			} else {
				$current .= ' ' . trim( $line );
			}
		}
		$close();

		$tag = $ordered ? 'ol' : 'ul';
		return "<{$tag}>" . implode( '', $items ) . "</{$tag}>";
	}

	/**
	 * Converts inline Markdown: code, images, links, bold, italic, strikethrough.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function inline_markdown( $text ) {
		$codes = array();
		$text  = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$codes ) {
				$codes[] = '<code>' . esc_html( $m[1] ) . '</code>';
				return "\x1A" . ( count( $codes ) - 1 ) . "\x1A";
			},
			$text
		);
		$text  = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
			static function ( $m ) {
				return '<img src="' . esc_url( $m[2] ) . '" alt="' . esc_attr( $m[1] ) . '">';
			},
			$text
		);
		$text  = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '">' . $m[1] . '</a>';
			},
			$text
		);
		$text  = preg_replace( '/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/', '<strong>$2</strong>', $text );
		$text  = preg_replace( '/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $text );
		$text  = preg_replace( '/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/', '<em>$1</em>', $text );
		$text  = preg_replace( '/~~(?=\S)(.+?)(?<=\S)~~/', '<s>$1</s>', $text );
		return preg_replace_callback(
			"/\x1A(\d+)\x1A/",
			static function ( $m ) use ( $codes ) {
				return $codes[ (int) $m[1] ];
			},
			$text
		);
	}

	/**
	 * Checks block markup for problems that break the block editor.
	 *
	 * @param string $content Block markup.
	 * @return array[] Issues with severity and message.
	 */
	public static function validate( $content ) {
		$issues   = array();
		$registry = \WP_Block_Type_Registry::get_instance();
		$stack    = array();

		preg_match_all( '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+(\{.*?\}))?\s+(\/)?-->/s', (string) $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			$name = false === strpos( $m[2], '/' ) ? 'core/' . $m[2] : $m[2];
			if ( ! empty( $m[1] ) ) {
				$open = array_pop( $stack );
				if ( $open !== $name ) {
					$issues[] = array(
						'severity' => 'error',
						'message'  => null === $open
							? sprintf( 'Closing comment <!-- /wp:%s --> has no matching opening comment.', $m[2] )
							: sprintf( 'Block %s is closed by <!-- /wp:%s -->; block comments must be properly nested.', $open, $m[2] ),
					);
					if ( null !== $open && in_array( $name, $stack, true ) ) {
						array_pop( $stack );
					}
				}
				continue;
			}
			if ( ! empty( $m[3] ) && null === json_decode( $m[3] ) ) {
				$issues[] = array(
					'severity' => 'error',
					'message'  => sprintf( 'Block %s has invalid JSON attributes: %s', $name, $m[3] ),
				);
			}
			if ( ! $registry->is_registered( $name ) ) {
				$issues[] = array(
					'severity' => 'warning',
					'message'  => sprintf( 'Block type %s is not registered on this site (see list_block_types); it will show as unsupported.', $name ),
				);
			}
			if ( 'core/block' === $name && ! empty( $m[3] ) ) {
				$attrs = json_decode( $m[3], true );
				$ref   = get_post( (int) ( $attrs['ref'] ?? 0 ) );
				if ( ! $ref || 'wp_block' !== $ref->post_type ) {
					$issues[] = array(
						'severity' => 'error',
						'message'  => sprintf( 'Synced pattern reference %d does not exist.', (int) ( $attrs['ref'] ?? 0 ) ),
					);
				}
			}
			if ( empty( $m[4] ) ) {
				$stack[] = $name;
			}
		}
		foreach ( $stack as $open ) {
			$issues[] = array(
				'severity' => 'error',
				'message'  => sprintf( 'Block %s is opened but never closed.', $open ),
			);
		}

		if ( $matches ) {
			foreach ( parse_blocks( $content ) as $block ) {
				if ( null === $block['blockName'] && '' !== trim( wp_strip_all_tags( $block['innerHTML'] ) ) ) {
					$issues[] = array(
						'severity' => 'warning',
						'message'  => 'Some content is outside of blocks and will show as a Classic block: "' . wp_trim_words( wp_strip_all_tags( $block['innerHTML'] ), 12 ) . '"',
					);
				}
			}
		}
		return $issues;
	}
}
