<?php
/**
 * Web-archive player: detection of .wacz/.warc attachments and rendering of
 * the ReplayWeb.page custom element on the public item page.
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects web-archive attachments on a Tainacan item and renders one
 * <replay-web-page> player section per file, escaped at every output point.
 */
class Player {

	/**
	 * Post-meta key caching the detected entry URL per attachment.
	 */
	const ENTRY_META = '_twp_entry_url';

	/**
	 * The assets manager (used to enqueue the vendored bundle on demand).
	 *
	 * @var Assets
	 */
	private $assets;

	/**
	 * Constructor.
	 *
	 * @param Assets $assets Assets manager.
	 */
	public function __construct( Assets $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Registers the front-end hook.
	 *
	 * Priority 20 runs after Tainacan's own the_content override (priority 10),
	 * so $content already contains the rendered Attachments section and we
	 * simply append our player below it.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'the_content', array( $this, 'append_player' ), 20 );
	}

	/**
	 * Appends the player markup after the item content on single item pages.
	 *
	 * @param string $content The post content rendered so far.
	 * @return string
	 */
	public function append_player( $content ) {
		if ( is_admin() || is_feed() || is_embed() ) {
			return $content;
		}
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_tainacan_item( $post ) ) {
			return $content;
		}

		$options = Plugin::get_options();
		if ( ! $options['autoinject'] ) {
			return $content;
		}

		$player = $this->render_for_item( $post->ID );
		if ( '' === $player ) {
			return $content;
		}

		return $content . $player;
	}

	/**
	 * Renders all web-archive player sections for a given item.
	 *
	 * Reused by the front-end hook, the Gutenberg block and the optional
	 * admin-edit hook. Enqueues the vendored bundle only when it actually
	 * outputs a player.
	 *
	 * @param int      $item_id        Tainacan item (post) ID.
	 * @param int|null $height_override Optional per-instance height in pixels.
	 * @return string HTML markup, already escaped at each output point.
	 */
	public function render_for_item( $item_id, $height_override = null ) {
		$item_id = (int) $item_id;
		if ( $item_id <= 0 ) {
			return '';
		}

		$attachments = $this->get_web_archive_attachments( $item_id );
		if ( empty( $attachments ) ) {
			return '';
		}

		$options = Plugin::get_options();
		if ( null !== $height_override && (int) $height_override >= 200 ) {
			$options['height'] = (int) $height_override;
		}

		// Keep only true web-archive attachments and de-duplicate by ID.
		$candidates = array();
		$seen       = array();
		foreach ( $attachments as $att ) {
			if ( ! ( $att instanceof \WP_Post ) || isset( $seen[ $att->ID ] ) ) {
				continue;
			}
			$type = $this->classify_attachment( $att, $options['accept_warc'] );
			if ( false === $type ) {
				continue;
			}
			$seen[ $att->ID ] = true;
			$candidates[]     = array(
				'post' => $att,
				'type' => $type,
			);
		}

		if ( empty( $candidates ) ) {
			return '';
		}

		$total = count( $candidates );
		$html  = '';
		$index = 0;
		foreach ( $candidates as $candidate ) {
			$section = $this->render_section( $candidate['post'], $candidate['type'], $index, $total, $options );
			if ( '' !== $section ) {
				$html .= $section;
				++$index;
			}
		}

		if ( '' !== $html ) {
			$this->assets->enqueue_player_assets();
		}

		return $html;
	}

	/**
	 * Whether the post is a Tainacan collection item.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_tainacan_item( \WP_Post $post ) {
		if ( ! class_exists( '\Tainacan\Theme_Helper' ) ) {
			return false;
		}
		return \Tainacan\Theme_Helper::get_instance()->is_post_an_item( $post );
	}

	/**
	 * Collects candidate attachments for an item: the regular attachments plus
	 * the main document when it is itself an attachment.
	 *
	 * Uses the canonical Tainacan theme tag / entity API rather than $wpdb.
	 *
	 * @param int $item_id Tainacan item (post) ID.
	 * @return \WP_Post[]
	 */
	private function get_web_archive_attachments( $item_id ) {
		$post = get_post( $item_id );
		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_tainacan_item( $post ) ) {
			return array();
		}

		$list = array();

		if ( function_exists( 'tainacan_get_the_attachments' ) ) {
			$attachments = tainacan_get_the_attachments( null, $item_id );
			if ( is_array( $attachments ) ) {
				$list = $attachments;
			}
		}

		// Include the main document too when it is an attachment (the theme tag
		// above excludes it). Filtering by type happens later, so a non-archive
		// document is simply ignored.
		if ( function_exists( 'tainacan_get_item' ) ) {
			$item = tainacan_get_item( $item_id );
			if ( $item && method_exists( $item, 'get_document_type' ) && 'attachment' === $item->get_document_type() ) {
				$doc_id   = (int) $item->get_document();
				$doc_post = $doc_id > 0 ? get_post( $doc_id ) : null;
				if ( $doc_post instanceof \WP_Post ) {
					array_unshift( $list, $doc_post );
				}
			}
		}

		return $list;
	}

	/**
	 * Classifies an attachment as a web archive.
	 *
	 * Detection is extension-first because servers disagree on the MIME type of
	 * a .wacz (often application/zip or application/octet-stream); the custom
	 * application/x-wacz and application/warc types are accepted as a fallback.
	 *
	 * @param \WP_Post $att         Attachment post.
	 * @param bool     $accept_warc Whether raw .warc is accepted.
	 * @return string|false 'wacz', 'warc' or false.
	 */
	private function classify_attachment( \WP_Post $att, $accept_warc ) {
		$path = get_attached_file( $att->ID );
		$url  = wp_get_attachment_url( $att->ID );
		$name = '';
		if ( is_string( $path ) && '' !== $path ) {
			$name = wp_basename( $path );
		} elseif ( is_string( $url ) && '' !== $url ) {
			$name = wp_basename( wp_parse_url( $url, PHP_URL_PATH ) );
		}

		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime = (string) get_post_mime_type( $att->ID );

		if ( 'wacz' === $ext || 'application/x-wacz' === $mime ) {
			return 'wacz';
		}
		if ( $accept_warc && ( 'warc' === $ext || 'application/warc' === $mime ) ) {
			return 'warc';
		}
		return false;
	}

	/**
	 * Renders a single player section for one attachment.
	 *
	 * @param \WP_Post $att     Attachment post.
	 * @param string   $type    'wacz' or 'warc'.
	 * @param int      $index   Zero-based index among rendered players.
	 * @param int      $total   Total number of players being rendered.
	 * @param array    $options Resolved plugin options.
	 * @return string
	 */
	private function render_section( \WP_Post $att, $type, $index, $total, $options ) {
		$source = wp_get_attachment_url( $att->ID );
		if ( ! is_string( $source ) || '' === $source ) {
			return '';
		}

		$label       = $this->attachment_label( $att );
		$entry_url   = $this->detect_entry_url( $att->ID, $options['default_entry_url'] );
		$replay_base = TWACZ_PLUGIN_URL . 'assets/vendor/replaywebpage/replay/';
		$height      = (int) $options['height'];

		if ( $total > 1 && '' !== $label ) {
			/* translators: %s: web-archive file name. */
			$heading = sprintf( __( 'Visualizar arquivo web: %s', 'tainacan-wacz-player' ), $label );
		} else {
			$heading = __( 'Visualizar arquivo web', 'tainacan-wacz-player' );
		}

		ob_start();
		?>
		<section class="twp-player-section" data-twp-type="<?php echo esc_attr( $type ); ?>" data-twp-index="<?php echo esc_attr( (string) $index ); ?>">
			<h2 class="twp-player-title"><?php echo esc_html( $heading ); ?></h2>

			<div class="twp-player-fallback" hidden>
				<p class="twp-player-fallback-message"></p>
				<p>
					<a class="twp-player-download button" href="<?php echo esc_url( $source ); ?>" download>
						<?php esc_html_e( 'Download web archive', 'tainacan-wacz-player' ); ?>
					</a>
				</p>
			</div>

			<replay-web-page
				class="twp-replay"
				source="<?php echo esc_url( $source ); ?>"
				url="<?php echo esc_url( $entry_url ); ?>"
				replayBase="<?php echo esc_url( $replay_base ); ?>"
				style="height: <?php echo esc_attr( (string) $height ); ?>px;">
			</replay-web-page>

			<noscript>
				<p class="twp-player-noscript">
					<?php esc_html_e( 'This web-archive viewer requires JavaScript.', 'tainacan-wacz-player' ); ?>
					<a href="<?php echo esc_url( $source ); ?>" download><?php esc_html_e( 'Download web archive', 'tainacan-wacz-player' ); ?></a>
				</p>
			</noscript>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Human-friendly label for an attachment (its title, or file basename).
	 *
	 * @param \WP_Post $att Attachment post.
	 * @return string
	 */
	private function attachment_label( \WP_Post $att ) {
		$title = get_the_title( $att->ID );
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			return $title;
		}
		$path = get_attached_file( $att->ID );
		if ( is_string( $path ) && '' !== $path ) {
			return wp_basename( $path );
		}
		return '';
	}

	/**
	 * Detects the snapshot entry-point URL from the .wacz's pages.jsonl.
	 *
	 * Reads the single pages.jsonl entry straight from the local attachment
	 * file with ZipArchive (the central directory is parsed and only that one
	 * member is inflated, so a 100 MB+ archive is not fully unzipped). The
	 * result is cached in post meta because a .wacz is immutable. No HTTP and
	 * no file_get_contents() are involved — the file is on disk.
	 *
	 * @param int    $att_id  Attachment ID.
	 * @param string $fallback Fallback entry URL.
	 * @return string
	 */
	private function detect_entry_url( $att_id, $fallback ) {
		$cached = get_post_meta( $att_id, self::ENTRY_META, true );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$entry = $fallback;
		$path  = get_attached_file( $att_id );

		if ( is_string( $path ) && '' !== $path && file_exists( $path ) && class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $path ) ) {
				foreach ( array( 'pages/pages.jsonl', 'pages.jsonl' ) as $member ) {
					$contents = $zip->getFromName( $member );
					if ( is_string( $contents ) && '' !== $contents ) {
						$found = $this->first_url_from_jsonl( $contents );
						if ( '' !== $found ) {
							$entry = $found;
							break;
						}
					}
				}
				$zip->close();
			}
		}

		update_post_meta( $att_id, self::ENTRY_META, $entry );
		return $entry;
	}

	/**
	 * Returns the first `url` value found in a JSONL document.
	 *
	 * Each line is validated as a JSON object with a non-empty string `url`
	 * before use (schema check, per project standards). The first JSONL line is
	 * often a format header with no `url`, which is skipped.
	 *
	 * @param string $contents Raw pages.jsonl contents.
	 * @return string Entry URL, or '' when none is found.
	 */
	private function first_url_from_jsonl( $contents ) {
		$lines = preg_split( '/\r\n|\r|\n/', $contents );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$data = json_decode( $line, true );
			if ( is_array( $data ) && isset( $data['url'] ) && is_string( $data['url'] ) && '' !== $data['url'] ) {
				return $data['url'];
			}
		}
		return '';
	}
}
