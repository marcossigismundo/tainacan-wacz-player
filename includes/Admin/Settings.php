<?php
/**
 * Settings page, integrated into the Tainacan admin menu via \Tainacan\Pages.
 *
 * Implements the contract described at
 * https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages
 * — a submenu under the Tainacan root menu, rendered inside the native
 * Tainacan shell (sidebar, breadcrumbs, typography).
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer\Admin;

use Tainacan\WaczPlayer\Plugin;
use Tainacan\WaczPlayer\Htaccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WACZ Player settings page.
 *
 * Never instantiated with `new`; use Settings::get_instance() (the Tainacan
 * Singleton_Instance trait calls the parent \Tainacan\Pages constructor, which
 * registers the admin_menu hooks).
 */
class Settings extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	/**
	 * Settings group / option page name.
	 */
	const GROUP = 'twp_settings';

	/**
	 * Page suffix returned by add_submenu_page (used for load-<suffix>).
	 *
	 * @var string
	 */
	private $page_suffix = '';

	/**
	 * Unique submenu slug, required by \Tainacan\Pages.
	 *
	 * @return string
	 */
	protected function get_page_slug(): string {
		return 'tainacan-wacz-player';
	}

	/**
	 * Hook setup. Adds our own hooks around what \Tainacan\Pages already does.
	 *
	 * @return void
	 */
	public function init() {
		parent::init();
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// Viewing the page only needs `read`, but saving must stay restricted.
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Capability required to SAVE the settings (not merely to view the page).
	 *
	 * @return string
	 */
	public function settings_capability() {
		return 'manage_options';
	}

	/**
	 * Registers the submenu under the Tainacan root menu.
	 *
	 * Position 80 pushes the item to the end of the sidebar, alongside the
	 * native Roles/Settings entries. Capability `read` matches Tainacan's own
	 * lightweight pages; saving is gated to manage_options (see above).
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		$icon_svg = method_exists( $this, 'get_svg_icon' ) ? $this->get_svg_icon( 'media' ) : '';

		$this->page_suffix = add_submenu_page(
			$this->tainacan_root_menu_slug,
			__( 'WACZ Player', 'tainacan-wacz-player' ),
			'<span class="icon" aria-hidden="true">' . $icon_svg . '</span>'
				. '<span class="menu-text">' . esc_html__( 'WACZ Player', 'tainacan-wacz-player' ) . '</span>',
			'read',
			$this->get_page_slug(),
			array( $this, 'render_page' ),
			80
		);

		if ( $this->page_suffix ) {
			add_action( 'load-' . $this->page_suffix, array( $this, 'load_page' ) );
		}
	}

	/**
	 * Registers the four plugin settings within a single option group.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			Plugin::OPT_AUTOINJECT,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => 1,
			)
		);
		register_setting(
			self::GROUP,
			Plugin::OPT_HEIGHT,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_height' ),
				'default'           => Plugin::DEFAULT_HEIGHT,
			)
		);
		register_setting(
			self::GROUP,
			Plugin::OPT_ENTRY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_entry_url' ),
				'default'           => '/',
			)
		);
		register_setting(
			self::GROUP,
			Plugin::OPT_WARC,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => 1,
			)
		);
		register_setting(
			self::GROUP,
			Plugin::OPT_SOURCE_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_source_mode' ),
				'default'           => 'stream',
			)
		);
		register_setting(
			self::GROUP,
			Plugin::OPT_AUTO_OPEN,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => 0,
			)
		);
	}

	/**
	 * Sanitizes the file-delivery mode to a known value.
	 *
	 * @param mixed $value Raw value.
	 * @return string 'static' or 'stream'.
	 */
	public function sanitize_source_mode( $value ) {
		return ( 'stream' === $value ) ? 'stream' : 'static';
	}

	/**
	 * Sanitizes a checkbox value to 1 or 0.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_bool( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Sanitizes the player height (clamped to a sensible range).
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_height( $value ) {
		$value = absint( $value );
		if ( $value < 200 ) {
			$value = Plugin::DEFAULT_HEIGHT;
		}
		if ( $value > 5000 ) {
			$value = 5000;
		}
		return $value;
	}

	/**
	 * Sanitizes the default entry URL (allows a bare path such as "/").
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_entry_url( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '/';
		}
		// Preserve a leading-slash path; otherwise treat it as a full URL.
		if ( '/' === $value[0] ) {
			return '/' . ltrim( sanitize_text_field( $value ), '/' );
		}
		$clean = esc_url_raw( $value );
		return '' === $clean ? '/' : $clean;
	}

	/**
	 * Enqueues this page's CSS (called by the parent load_page()).
	 *
	 * @return void
	 */
	public function admin_enqueue_css() {
		wp_register_style( 'twp-admin', false, array(), TWACZ_VERSION );
		wp_enqueue_style( 'twp-admin' );
		$css = '.twp-settings .form-table th{width:280px;} .twp-settings code{background:#f3f3f3;padding:1px 5px;border-radius:3px;}';
		wp_add_inline_style( 'twp-admin', $css );
	}

	/**
	 * Renders the page body inside the Tainacan shell.
	 *
	 * @return void
	 */
	public function render_page_content() {
		$options = Plugin::get_options();
		?>
		<div class="wrap tainacan-page-container-content twp-settings">
			<div class="tainacan-fixed-subheader">
				<h1 class="tainacan-page-title"><?php esc_html_e( 'Tainacan WACZ Player', 'tainacan-wacz-player' ); ?></h1>
				<p class="tainacan-page-description">
					<?php esc_html_e( 'Displays .wacz / .warc web archives attached to items, right below the Attachments box, using a locally bundled ReplayWeb.page viewer.', 'tainacan-wacz-player' ); ?>
				</p>
			</div>

			<?php $this->maybe_render_repair_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Public auto-injection', 'tainacan-wacz-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Plugin::OPT_AUTOINJECT ); ?>" value="1" <?php checked( $options['autoinject'] ); ?> />
								<?php esc_html_e( 'Automatically display the player below the Attachments box on every item page.', 'tainacan-wacz-player' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this off to place the player manually with the "WACZ Player (inline)" block.', 'tainacan-wacz-player' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="twp_player_height"><?php esc_html_e( 'Default player height (px)', 'tainacan-wacz-player' ); ?></label></th>
						<td>
							<input type="number" min="200" max="5000" step="10" id="twp_player_height" name="<?php echo esc_attr( Plugin::OPT_HEIGHT ); ?>" value="<?php echo esc_attr( (string) $options['height'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Entry page', 'tainacan-wacz-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Plugin::OPT_AUTO_OPEN ); ?>" value="1" <?php checked( $options['auto_open'] ); ?> />
								<?php esc_html_e( 'Try to open the captured page automatically', 'tainacan-wacz-player' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off (default), the viewer shows the archive\'s navigable page list, which always works. When on, it opens the detected entry page directly — convenient, but some archives may then report "Archived Page Not Found".', 'tainacan-wacz-player' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="twp_default_entry_url"><?php esc_html_e( 'Forced entry URL (optional)', 'tainacan-wacz-player' ); ?></label></th>
						<td>
							<input type="text" id="twp_default_entry_url" name="<?php echo esc_attr( Plugin::OPT_ENTRY ); ?>" value="<?php echo esc_attr( $options['default_entry_url'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Advanced: open this exact URL instead of the page list. Leave as / to keep the default behavior.', 'tainacan-wacz-player' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Raw .warc files', 'tainacan-wacz-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Plugin::OPT_WARC ); ?>" value="1" <?php checked( $options['accept_warc'] ); ?> />
								<?php esc_html_e( 'Also display raw .warc attachments (same ReplayWeb.page viewer).', 'tainacan-wacz-player' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'File delivery', 'tainacan-wacz-player' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( Plugin::OPT_SOURCE_MODE ); ?>" value="stream" <?php checked( 'stream', $options['source_mode'] ); ?> />
									<?php esc_html_e( 'PHP streaming (recommended) — works even when the server blocks direct access to the .wacz files', 'tainacan-wacz-player' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( Plugin::OPT_SOURCE_MODE ); ?>" value="static" <?php checked( 'static', $options['source_mode'] ); ?> />
									<?php esc_html_e( 'Direct static file — more efficient, but only if your server serves the uploads directory directly', 'tainacan-wacz-player' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Many hosts (or security plugins / WAFs) deny direct access to the .wacz extension or the DIP objects directory. PHP streaming bypasses that by serving the file through WordPress.', 'tainacan-wacz-player' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_file_access_section(); ?>

			<hr />
			<h2><?php esc_html_e( 'Why not convert .wacz to .warc?', 'tainacan-wacz-player' ); ?></h2>
			<p>
				<?php esc_html_e( 'A .wacz already contains the WARC records internally, plus a CDXJ index and metadata that make replay fast. ReplayWeb.page consumes .wacz natively, so converting back to .warc would only add I/O and lose the index. This plugin therefore plays the .wacz as-is.', 'tainacan-wacz-player' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the result notice after a repair attempt.
	 *
	 * @return void
	 */
	private function maybe_render_repair_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result flag; no state change.
		if ( ! isset( $_GET['twacz_repaired'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result flag; no state change.
		$ok = '1' === sanitize_text_field( wp_unslash( $_GET['twacz_repaired'] ) );
		if ( $ok ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'File access repaired. Reload an item page — and clear the browser cache / unregister the service worker — to test.', 'tainacan-wacz-player' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not write the .htaccess automatically. Your host may require manual changes — see the plugin README.', 'tainacan-wacz-player' ) . '</p></div>';
		}
	}

	/**
	 * Renders the "web-archive file access" diagnostic and repair button.
	 *
	 * @return void
	 */
	private function render_file_access_section() {
		$status = ( new Htaccess() )->status();
		?>
		<hr />
		<h2><?php esc_html_e( 'Web-archive file access', 'tainacan-wacz-player' ); ?></h2>
		<?php if ( ! $status['dir_exists'] ) : ?>
			<p class="description"><?php esc_html_e( 'The DIP objects directory was not found on this site — nothing to repair here.', 'tainacan-wacz-player' ); ?></p>
		<?php else : ?>
			<p>
				<?php if ( $status['permissive'] ) : ?>
					<strong style="color:#1a7f37;">&#10003; <?php esc_html_e( 'Direct file access looks enabled.', 'tainacan-wacz-player' ); ?></strong>
				<?php else : ?>
					<strong style="color:#b32d2e;">&#10007; <?php esc_html_e( 'A blocking rule is denying direct access to the archive files.', 'tainacan-wacz-player' ); ?></strong>
				<?php endif; ?>
			</p>
			<p class="description"><code><?php echo esc_html( $status['dir'] ); ?></code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'twacz_repair' ); ?>
				<input type="hidden" name="action" value="twacz_repair" />
				<?php submit_button( __( 'Repair file access', 'tainacan-wacz-player' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description">
				<?php esc_html_e( 'Writes a permissive .htaccess into that directory (directory listing stays off), restoring direct, Range-enabled access to the .wacz files. Works when the server honours .htaccess overrides.', 'tainacan-wacz-player' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}
}
