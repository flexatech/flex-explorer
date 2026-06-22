<?php
/**
 * Plugin Name:       Flex Explorer
 * Description:       A lightweight, read-only file browser for WordPress. Browse folders and view files inside wp-content from the admin.
 * Version:           0.2.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Flexa Tech
 * Author URI:        https://flexa.vn
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flex-explorer
 * Domain Path:       /languages
 *
 * @package FlexExplorer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only file browser confined to wp-content.
 *
 * Everything lives in one small final class to keep the surface area tiny:
 * an admin page, the ajax handlers (list, read, search, download, zip), and a
 * single resolver that is the only gate between a request and the filesystem.
 */
final class Flex_Explorer_Lite {

	const VERSION   = '0.2.3';
	const SLUG      = 'flex-explorer';
	const NONCE     = 'flex_explorer';
	const CAPABILITY = 'manage_options';

	/** Max bytes a file may be to stream its text content. */
	const MAX_TEXT_BYTES = 2097152; // 2 MB.

	/** Max bytes an image may be to inline as a data URI preview. */
	const MAX_IMAGE_BYTES = 5242880; // 5 MB.

	/** Max matches a single search returns. */
	const MAX_SEARCH_RESULTS = 200;

	/** Max filesystem entries a single search will visit before stopping. */
	const MAX_SEARCH_NODES = 20000;

	/** Max files a single zip may contain. */
	const MAX_ZIP_FILES = 5000;

	/** Max uncompressed bytes a single zip may gather. */
	const MAX_ZIP_BYTES = 52428800; // 50 MB.

	/**
	 * Basenames that must never be read, even when listable.
	 *
	 * @var string[]
	 */
	const BLOCKED = array( 'wp-config.php', '.htaccess', '.htpasswd' );

	/**
	 * Wire up the hooks. Called once on load.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_flex_explorer_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_flex_explorer_read', array( __CLASS__, 'ajax_read' ) );
		add_action( 'wp_ajax_flex_explorer_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_flex_explorer_download', array( __CLASS__, 'ajax_download' ) );
		add_action( 'wp_ajax_flex_explorer_zip', array( __CLASS__, 'ajax_zip' ) );
	}

	/**
	 * Whether the current user may use the browser at all.
	 *
	 * Beyond the raw capability this enforces two things a bare
	 * current_user_can( 'manage_options' ) misses:
	 *
	 *  - On multisite, manage_options is held by every per-site admin, but
	 *    the wp-content tree is shared across the whole network, so access
	 *    is narrowed to super admins.
	 *  - When a site sets DISALLOW_FILE_EDIT to hide file contents from
	 *    admins, this read-only viewer honours that lockdown instead of
	 *    quietly re-exposing the source.
	 */
	private static function can_access(): bool {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		if ( is_multisite() && ! is_super_admin() ) {
			return false;
		}

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}

		return true;
	}

	/**
	 * Sandbox root: the canonical wp-content path. Everything is resolved
	 * against this and may never escape it.
	 */
	private static function root(): string {
		// Resolve the content directory through wp_upload_dir() rather than a
		// raw constant: the uploads base lives inside wp-content, so its parent
		// is the content root and tracks any custom uploads/content location.
		$uploads = wp_upload_dir();
		$base    = dirname( $uploads['basedir'] );

		$root = realpath( $base );

		return false === $root ? $base : $root;
	}

	/**
	 * Register the admin page as a submenu under Tools, where a read-only
	 * utility belongs, rather than competing for a top-level slot.
	 */
	public static function register_menu(): void {
		if ( ! self::can_access() ) {
			return;
		}

		add_management_page(
			__( 'Flex Explorer', 'flex-explorer' ),
			__( 'Flex Explorer', 'flex-explorer' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Load CSS/JS only on our own admin screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		$url = plugin_dir_url( __FILE__ );

		wp_enqueue_style(
			'flex-explorer',
			$url . 'assets/css/admin.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'flex-explorer',
			$url . 'assets/js/admin.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'flex-explorer',
			'flexExplorer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'root'    => 'wp-content',
				'i18n'    => array(
					'loading'      => __( 'Loading…', 'flex-explorer' ),
					'empty'        => __( 'This folder is empty.', 'flex-explorer' ),
					'error'        => __( 'Could not load that path.', 'flex-explorer' ),
					'name'         => __( 'Name', 'flex-explorer' ),
					'size'         => __( 'Size', 'flex-explorer' ),
					'modified'     => __( 'Modified', 'flex-explorer' ),
					'selectFile'   => __( 'Select a file to view it.', 'flex-explorer' ),
					'notViewable'  => __( 'This file type cannot be previewed here.', 'flex-explorer' ),
					'tooLarge'     => __( 'This file is too large to preview.', 'flex-explorer' ),
					'folder'       => __( 'Folder', 'flex-explorer' ),
					'searchHint'   => __( 'Searching in the current folder and below.', 'flex-explorer' ),
					'noMatches'    => __( 'No files match that search.', 'flex-explorer' ),
					'capped'       => __( 'Showing the first matches only. Narrow your search.', 'flex-explorer' ),
					'download'     => __( 'Download', 'flex-explorer' ),
				),
			)
		);
	}

	/**
	 * Render the (intentionally bare) mount point. jQuery fills it in.
	 */
	public static function render_page(): void {
		if ( ! self::can_access() ) {
			return;
		}
		?>
		<div class="wrap flex-explorer">
			<h1><?php echo esc_html__( 'Flex Explorer', 'flex-explorer' ); ?></h1>
			<p class="flex-explorer__intro">
				<?php echo esc_html__( 'A read-only browser for the wp-content directory.', 'flex-explorer' ); ?>
			</p>

			<div class="flex-explorer__toolbar">
				<div class="flex-explorer__search">
					<input type="search" class="flex-explorer__search-input" placeholder="<?php echo esc_attr__( 'Search files…', 'flex-explorer' ); ?>" aria-label="<?php echo esc_attr__( 'Search files', 'flex-explorer' ); ?>" />
					<button type="button" class="button flex-explorer__search-clear" hidden><?php echo esc_html__( 'Clear', 'flex-explorer' ); ?></button>
				</div>
				<button type="button" class="button flex-explorer__zip"><?php echo esc_html__( 'Download folder as ZIP', 'flex-explorer' ); ?></button>
			</div>

			<nav class="flex-explorer__crumbs" aria-label="<?php echo esc_attr__( 'Breadcrumb', 'flex-explorer' ); ?>"></nav>

			<div class="flex-explorer__panes">
				<div class="flex-explorer__list" aria-live="polite"></div>
				<div class="flex-explorer__viewer">
					<p class="flex-explorer__placeholder">
						<?php echo esc_html__( 'Select a file to view it.', 'flex-explorer' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Shared request guard for both ajax handlers: nonce + capability.
	 * Dies via wp_send_json_error on failure.
	 */
	private static function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'flex-explorer' ) ), 403 );
		}

		if ( ! self::can_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'flex-explorer' ) ), 403 );
		}
	}

	/**
	 * Resolve a sandbox-relative path to a validated absolute path, or null.
	 *
	 * This is the only place a request-supplied path becomes a filesystem
	 * path. It rejects traversal, symlink escapes, and anything that does not
	 * resolve to a real entry inside the wp-content root.
	 *
	 * @param string $relative Forward-slash, sandbox-relative path. '' = root.
	 * @return string|null Absolute path, or null if it is not allowed.
	 */
	private static function resolve( string $relative ): ?string {
		$root = self::root();

		$relative = str_replace( '\\', '/', $relative );
		$relative = ltrim( $relative, '/' );

		// Reject any traversal segment outright before touching the disk.
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				return null;
			}
		}

		$candidate = '' === $relative ? $root : $root . '/' . $relative;
		$absolute  = realpath( $candidate );

		if ( false === $absolute ) {
			return null;
		}

		// Must be the root itself or strictly inside it.
		if ( $absolute !== $root && 0 !== strpos( $absolute, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $absolute;
	}

	/**
	 * Turn an absolute path back into its sandbox-relative form for the client.
	 */
	private static function to_relative( string $absolute ): string {
		$root = self::root();

		if ( $absolute === $root ) {
			return '';
		}

		return ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $root ) ) ), '/' );
	}

	/**
	 * AJAX: list the entries of a directory.
	 */
	public static function ajax_list(): void {
		self::guard();

		$requested = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() via check_ajax_referer().
		$absolute  = self::resolve( $requested );

		if ( null === $absolute || ! is_dir( $absolute ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not load that path.', 'flex-explorer' ) ), 404 );
		}

		$entries = array();

		foreach ( scandir( $absolute ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$child = $absolute . DIRECTORY_SEPARATOR . $name;

			// Skip symlinks so a link can't surface a target outside the root.
			if ( is_link( $child ) ) {
				continue;
			}

			$is_dir = is_dir( $child );

			$entries[] = array(
				'name'     => $name,
				'path'     => self::to_relative( $child ),
				'type'     => $is_dir ? 'dir' : 'file',
				'size'     => $is_dir ? 0 : (int) filesize( $child ),
				'modified' => (int) filemtime( $child ),
			);
		}

		// Folders first, then alphabetical within each group.
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		wp_send_json_success(
			array(
				'path'    => self::to_relative( $absolute ),
				'entries' => $entries,
			)
		);
	}

	/**
	 * AJAX: read a single file for viewing (text inline, images as data URI).
	 */
	public static function ajax_read(): void {
		self::guard();

		$requested = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() via check_ajax_referer().
		$absolute  = self::resolve( $requested );

		if ( null === $absolute || ! is_file( $absolute ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not load that file.', 'flex-explorer' ) ), 404 );
		}

		$basename = basename( $absolute );

		if ( in_array( strtolower( $basename ), self::BLOCKED, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This file cannot be viewed.', 'flex-explorer' ) ), 403 );
		}

		$size = (int) filesize( $absolute );
		$ext  = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

		$base = array(
			'name'     => $basename,
			'path'     => self::to_relative( $absolute ),
			'ext'      => $ext,
			'size'     => $size,
			'modified' => (int) filemtime( $absolute ),
		);

		// Image preview (small files only) via a data URI.
		$image_types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'bmp'  => 'image/bmp',
			'ico'  => 'image/x-icon',
		);

		if ( isset( $image_types[ $ext ] ) ) {
			if ( $size > self::MAX_IMAGE_BYTES ) {
				wp_send_json_success( array_merge( $base, array( 'kind' => 'toolarge' ) ) );
			}

			$bytes = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local sandboxed path.

			wp_send_json_success(
				array_merge(
					$base,
					array(
						'kind'    => 'image',
						'dataUri' => 'data:' . $image_types[ $ext ] . ';base64,' . base64_encode( $bytes ),
					)
				)
			);
		}

		if ( $size > self::MAX_TEXT_BYTES ) {
			wp_send_json_success( array_merge( $base, array( 'kind' => 'toolarge' ) ) );
		}

		$bytes = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local sandboxed path.

		// A NUL byte is a reliable, cheap "this is binary" signal.
		if ( false !== strpos( $bytes, "\0" ) ) {
			wp_send_json_success( array_merge( $base, array( 'kind' => 'binary' ) ) );
		}

		wp_send_json_success(
			array_merge(
				$base,
				array(
					'kind'    => 'text',
					'content' => $bytes,
				)
			)
		);
	}

	/**
	 * Lower-case a string, honouring multibyte characters when mbstring is
	 * available so non-ASCII filenames (e.g. accented Vietnamese names) fold
	 * correctly for case-insensitive search.
	 */
	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * AJAX: search filenames under a base directory, recursively.
	 *
	 * Matching is a case-insensitive substring test on the basename. The walk
	 * is bounded by MAX_SEARCH_NODES (entries visited) and MAX_SEARCH_RESULTS
	 * (matches returned) so a deep tree can never tie up the request, and it
	 * never follows symlinks out of the sandbox.
	 */
	public static function ajax_search(): void {
		self::guard();

		$base  = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() via check_ajax_referer().
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() via check_ajax_referer().
		$query = trim( $query );

		$absolute = self::resolve( $base );

		if ( null === $absolute || ! is_dir( $absolute ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not load that path.', 'flex-explorer' ) ), 404 );
		}

		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'Type something to search for.', 'flex-explorer' ) ), 400 );
		}

		$needle  = self::lower( $query );
		$results = array();
		$visited = 0;
		$capped  = false;

		// Iterative depth-first walk; a manual stack avoids recursion limits.
		$stack = array( $absolute );

		while ( $stack ) {
			$dir     = array_pop( $stack );
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable dir just yields no children.

			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				if ( ++$visited > self::MAX_SEARCH_NODES ) {
					$capped = true;
					break 2;
				}

				$child = $dir . DIRECTORY_SEPARATOR . $name;

				if ( is_link( $child ) ) {
					continue;
				}

				$is_dir = is_dir( $child );

				if ( false !== strpos( self::lower( $name ), $needle ) ) {
					$results[] = array(
						'name'     => $name,
						'path'     => self::to_relative( $child ),
						'type'     => $is_dir ? 'dir' : 'file',
						'size'     => $is_dir ? 0 : (int) filesize( $child ),
						'modified' => (int) filemtime( $child ),
					);

					if ( count( $results ) >= self::MAX_SEARCH_RESULTS ) {
						$capped = true;
						break 2;
					}
				}

				if ( $is_dir ) {
					$stack[] = $child;
				}
			}
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		wp_send_json_success(
			array(
				'path'    => self::to_relative( $absolute ),
				'query'   => $query,
				'capped'  => $capped,
				'entries' => $results,
			)
		);
	}

	/**
	 * AJAX: stream a single file to the browser as a download.
	 *
	 * Triggered by a GET navigation, so the nonce travels in the query string;
	 * guard() validates it through $_REQUEST. The response is forced to
	 * application/octet-stream + attachment so the browser saves the bytes
	 * rather than ever rendering them (no inline HTML/SVG execution).
	 */
	public static function ajax_download(): void {
		self::guard();

		$requested = isset( $_REQUEST['path'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in guard() via check_ajax_referer().
		$absolute  = self::resolve( $requested );

		if ( null === $absolute || ! is_file( $absolute ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not load that file.', 'flex-explorer' ) ), 404 );
		}

		$basename = basename( $absolute );

		if ( in_array( strtolower( $basename ), self::BLOCKED, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This file cannot be downloaded.', 'flex-explorer' ) ), 403 );
		}

		self::stream( $absolute, 'application/octet-stream', sanitize_file_name( $basename ) );
	}

	/**
	 * AJAX: zip a directory and stream the archive as a download.
	 *
	 * The walk stays inside the sandbox, skips symlinks and BLOCKED basenames,
	 * and stops if the archive would exceed MAX_ZIP_FILES or MAX_ZIP_BYTES so a
	 * large tree can't exhaust disk or memory. The temp archive is removed
	 * before the request ends.
	 */
	public static function ajax_zip(): void {
		self::guard();

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Zip support is not available on this server.', 'flex-explorer' ) ), 501 );
		}

		$requested = isset( $_REQUEST['path'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in guard() via check_ajax_referer().
		$absolute  = self::resolve( $requested );

		if ( null === $absolute || ! is_dir( $absolute ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not load that folder.', 'flex-explorer' ) ), 404 );
		}

		$label   = '' === self::to_relative( $absolute ) ? 'wp-content' : basename( $absolute );
		$tmp     = wp_tempnam( 'flex-explorer-' . $label . '.zip' );
		$archive = new ZipArchive();

		if ( true !== $archive->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			wp_send_json_error( array( 'message' => __( 'Could not create the archive.', 'flex-explorer' ) ), 500 );
		}

		$prefix = $absolute . DIRECTORY_SEPARATOR;
		$count  = 0;
		$bytes  = 0;
		$stack  = array( $absolute );

		while ( $stack ) {
			$dir     = array_pop( $stack );
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable dir just yields no children.

			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$child = $dir . DIRECTORY_SEPARATOR . $name;

				if ( is_link( $child ) ) {
					continue;
				}

				if ( is_dir( $child ) ) {
					$stack[] = $child;
					continue;
				}

				if ( in_array( strtolower( $name ), self::BLOCKED, true ) ) {
					continue;
				}

				$size = (int) filesize( $child );

				if ( $count + 1 > self::MAX_ZIP_FILES || $bytes + $size > self::MAX_ZIP_BYTES ) {
					$archive->close();
					wp_delete_file( $tmp );
					wp_send_json_error(
						array( 'message' => __( 'This folder is too large to zip.', 'flex-explorer' ) ),
						413
					);
				}

				// Entry path inside the zip is relative to the chosen folder.
				$local = str_replace( '\\', '/', substr( $child, strlen( $prefix ) ) );
				$archive->addFile( $child, $label . '/' . $local );

				++$count;
				$bytes += $size;
			}
		}

		$archive->close();

		if ( 0 === $count ) {
			wp_delete_file( $tmp );
			wp_send_json_error( array( 'message' => __( 'There are no files to zip here.', 'flex-explorer' ) ), 404 );
		}

		self::stream( $tmp, 'application/zip', sanitize_file_name( $label . '.zip' ), true );
	}

	/**
	 * Stream a local file to the browser as a download, then exit.
	 *
	 * Before sending a byte it discards any pending output buffers and turns
	 * off zlib compression. Both matter for binary payloads: a stray notice or
	 * BOM left in a buffer would prepend bytes and corrupt the file (a ZIP in
	 * particular), and on-the-fly gzip would make the bytes sent disagree with
	 * the Content-Length we advertise, truncating the download.
	 *
	 * @param string $path     Absolute, already-validated file path.
	 * @param string $mime     Content-Type to send.
	 * @param string $filename Sanitized download filename.
	 * @param bool   $cleanup  Whether to delete $path after sending (temp files).
	 */
	private static function stream( string $path, string $mime, string $filename, bool $cleanup = false ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Disabling zlib output compression is scoped to this single download
		// handler (which exits immediately after) and the prior value is
		// restored below, so the change never leaks into the wider request.
		$zlib    = ini_get( 'zlib.output_compression' );
		$toggled = false;
		if ( '' !== (string) $zlib && '0' !== (string) $zlib ) {
			ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- avoids a Content-Length mismatch on the streamed body.
			$toggled = true;
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a sandboxed local file.

		if ( $toggled ) {
			ini_set( 'zlib.output_compression', (string) $zlib ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- restore the value changed for this single download.
		}

		if ( $cleanup ) {
			wp_delete_file( $path );
		}

		exit;
	}
}

Flex_Explorer_Lite::init();
