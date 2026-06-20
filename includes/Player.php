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
	 * Item IDs already rendered this request (guards against double injection
	 * when more than one injection hook fires for the same item).
	 *
	 * @var array<int,bool>
	 */
	private $rendered = array();

	/**
	 * Constructor.
	 *
	 * @param Assets $assets Assets manager.
	 */
	public function __construct( Assets $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Registers the front-end injection hooks.
	 *
	 * Two complementary hooks cover the two ways Tainacan renders a single item;
	 * a per-request guard ($this->rendered) prevents a double injection if both
	 * ever fire for the same item:
	 *
	 *  1. tainacan-interface-single-item-after-attachments — fired by the
	 *     official Tainacan theme (tainacan-interface / tainacan-theme), which
	 *     renders the item with its own template (tainacan/single-items.php).
	 *     It fires right below the Attachments section. This is the case for the
	 *     Memorial site. The action carries no args; the item is in The Loop.
	 *
	 *  2. tainacan_single_item_content — applied by Tainacan core's default
	 *     single-item content builder (used when the theme does NOT provide its
	 *     own template, via the the_content override). $content already includes
	 *     the Attachments section, so the player is appended after it.
	 *
	 * Block themes (FSE) use the wacz-player/inline Gutenberg block instead.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'tainacan-interface-single-item-after-attachments', array( $this, 'render_after_attachments' ) );
		add_filter( 'tainacan_single_item_content', array( $this, 'filter_single_item_content' ), 20, 2 );
	}

	/**
	 * Action callback for the tainacan-interface theme: echoes the player right
	 * below the Attachments section. Runs inside The Loop, so the current post
	 * is the item.
	 *
	 * @return void
	 */
	public function render_after_attachments() {
		$options = Plugin::get_options();
		if ( ! $options['autoinject'] ) {
			return;
		}
		$item_id = (int) get_the_ID();
		if ( $item_id <= 0 || isset( $this->rendered[ $item_id ] ) ) {
			return;
		}
		$html = $this->render_for_item( $item_id );
		if ( '' === $html ) {
			return;
		}
		$this->rendered[ $item_id ] = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped at every output point inside Player::render_section().
	}

	/**
	 * Filter callback for Tainacan's default single-item content. Appends the
	 * player after $content, which already includes the Attachments section.
	 *
	 * @param string $content The single-item HTML built by Tainacan core.
	 * @param mixed  $item    The Tainacan item entity (\Tainacan\Entities\Item).
	 * @return string
	 */
	public function filter_single_item_content( $content, $item = null ) {
		$options = Plugin::get_options();
		if ( ! $options['autoinject'] ) {
			return $content;
		}
		$item_id = ( is_object( $item ) && method_exists( $item, 'get_id' ) ) ? (int) $item->get_id() : (int) get_the_ID();
		if ( $item_id <= 0 || isset( $this->rendered[ $item_id ] ) ) {
			return $content;
		}
		$html = $this->render_for_item( $item_id );
		if ( '' === $html ) {
			return $content;
		}
		$this->rendered[ $item_id ] = true;
		return $content . $html;
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
		$direct = wp_get_attachment_url( $att->ID );
		if ( ! is_string( $direct ) || '' === $direct ) {
			return '';
		}

		// Default: the static uploads URL, served directly by the web server with
		// native Range support (efficient, and least likely to trip a request-rate
		// WAF). 'stream' routes through our same-origin PHP endpoint instead, for
		// hosts that still deny direct access to the DIP objects directory.
		if ( 'stream' === $options['source_mode'] ) {
			$source = FileServer::url( $att->ID );
		} else {
			$source = $direct;
		}

		$label = $this->attachment_label( $att );

		// By default, show ReplayWeb.page's own page list (built from pages.jsonl):
		// it is always navigable and never reports "Archived Page Not Found".
		// The opt-in "auto open" passes the detected entry page (url + ts) so it
		// opens directly — convenient, but it can fail on archives whose internal
		// index is inconsistent. An explicit admin entry-URL override wins too.
		$entry_url = '';
		$entry_ts  = '';
		if ( $options['auto_open'] ) {
			$entry = $this->detect_entry( $att->ID );
			if ( '' !== $entry['ts'] ) {
				$entry_url = $entry['url'];
				$entry_ts  = $entry['ts'];
			}
		}
		if ( '' === $entry_url ) {
			$default = (string) $options['default_entry_url'];
			if ( '' !== $default && '/' !== $default ) {
				$entry_url = $default;
			}
		}
		$replay_base = TWACZ_PLUGIN_URL . 'assets/vendor/replaywebpage/replay/';
		$height      = (int) $options['height'];

		// Only emit url/ts when known; an empty pair would re-trigger the
		// "Archived Page Not Found" case instead of the page list. Each value is
		// escaped here, where the attribute string is built.
		$entry_attrs = '';
		if ( '' !== $entry_url ) {
			$entry_attrs .= ' url="' . esc_url( $entry_url ) . '"';
		}
		if ( '' !== $entry_ts ) {
			$entry_attrs .= ' ts="' . esc_attr( $entry_ts ) . '"';
		}

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
				source="<?php echo esc_url( $source ); ?>"<?php echo $entry_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url/ts are escaped with esc_url()/esc_attr() where $entry_attrs is built above. ?>
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
	 * Detects the snapshot entry page (url + timestamp) from the .wacz's
	 * pages.jsonl.
	 *
	 * Reads pages.jsonl straight from the local attachment file with ZipArchive
	 * (only that one small member is inflated, so a 100 MB+ archive is not fully
	 * unzipped). pages.jsonl is ReplayWeb.page's own page-list format, so a
	 * (url, ts) pair taken from it is guaranteed to be loadable — passing the url
	 * WITHOUT the ts is what triggers "Archived Page Not Found". The result is
	 * cached as JSON in post meta because a .wacz is immutable. No HTTP and no
	 * file_get_contents() are involved — the file is on disk.
	 *
	 * @param int $att_id Attachment ID.
	 * @return array{url:string,ts:string}
	 */
	private function detect_entry( $att_id ) {
		$cached = get_post_meta( $att_id, self::ENTRY_META, true );
		if ( is_string( $cached ) && '' !== $cached ) {
			$data = json_decode( $cached, true );
			if ( is_array( $data ) ) {
				return array(
					'url' => ( isset( $data['url'] ) && is_string( $data['url'] ) ) ? $data['url'] : '',
					'ts'  => ( isset( $data['ts'] ) && is_string( $data['ts'] ) ) ? $data['ts'] : '',
				);
			}
		}

		$entry = array(
			'url' => '',
			'ts'  => '',
		);

		$path = get_attached_file( $att_id );

		if ( is_string( $path ) && '' !== $path && file_exists( $path ) && class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $path ) ) {
				foreach ( array( 'pages/pages.jsonl', 'pages.jsonl' ) as $member ) {
					$contents = $zip->getFromName( $member );
					if ( is_string( $contents ) && '' !== $contents ) {
						$page = $this->first_page_from_jsonl( $contents );
						if ( '' !== $page['url'] ) {
							$entry = $page;
							break;
						}
					}
				}
				$zip->close();
			}
		}

		update_post_meta( $att_id, self::ENTRY_META, wp_json_encode( $entry ) );
		return $entry;
	}

	/**
	 * Returns the first page (url + normalized ts) from a JSONL document.
	 *
	 * Each line is validated as a JSON object with a non-empty string `url`
	 * before use (schema check, per project standards). The first JSONL line is
	 * often a format header with no `url`, which is skipped.
	 *
	 * @param string $contents Raw pages.jsonl contents.
	 * @return array{url:string,ts:string}
	 */
	private function first_page_from_jsonl( $contents ) {
		$result = array(
			'url' => '',
			'ts'  => '',
		);

		$lines = preg_split( '/\r\n|\r|\n/', $contents );
		if ( ! is_array( $lines ) ) {
			return $result;
		}
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$data = json_decode( $line, true );
			if ( is_array( $data ) && isset( $data['url'] ) && is_string( $data['url'] ) && '' !== $data['url'] ) {
				$result['url'] = $data['url'];
				if ( isset( $data['ts'] ) && is_string( $data['ts'] ) && '' !== $data['ts'] ) {
					$result['ts'] = $this->normalize_ts( $data['ts'] );
				}
				return $result;
			}
		}
		return $result;
	}

	/**
	 * Normalizes a pages.jsonl timestamp to the 14-digit YYYYMMDDHHMMSS form
	 * ReplayWeb.page expects in its `ts` attribute.
	 *
	 * The pages.jsonl format usually stores an ISO-8601 UTC timestamp (e.g.
	 * 2021-08-09T12:01:44Z), whose digits already form the 14-digit value.
	 * Anything else is parsed and reformatted in UTC.
	 *
	 * @param string $ts Raw timestamp.
	 * @return string 14-digit timestamp, or '' if it cannot be parsed.
	 */
	private function normalize_ts( $ts ) {
		$digits = preg_replace( '/\D/', '', $ts );
		if ( is_string( $digits ) && 14 === strlen( $digits ) ) {
			return $digits;
		}
		$time = strtotime( $ts );
		if ( false === $time ) {
			return '';
		}
		return gmdate( 'YmdHis', $time );
	}
}
