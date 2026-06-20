<?php
/**
 * Same-origin, Range-capable streamer for web-archive attachments.
 *
 * Some servers deny direct HTTP access to the uploads sub-directory where the
 * DIP Importer stores its objects (e.g. a leftover blocking .htaccess), which
 * makes the browser get a 403 when ReplayWeb.page tries to fetch the .wacz.
 * This endpoint reads the file straight from disk with PHP — bypassing the
 * web-server access rule entirely — and streams it from the same origin with
 * full HTTP Range support, which ReplayWeb.page needs for random access.
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams .wacz / .warc attachments via PHP, with Range support and strict
 * validation (only web-archive files whose parent item is publicly viewable).
 */
class FileServer {

	/**
	 * Query var that triggers the streamer.
	 */
	const QUERY_VAR = 'twacz_file';

	/**
	 * Bytes per chunk while streaming.
	 */
	const CHUNK = 65536;

	/**
	 * Registers the request handler.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'maybe_serve' ) );
	}

	/**
	 * Builds the same-origin URL that streams a given attachment.
	 *
	 * ReplayWeb.page detects the archive format by sniffing the content (it
	 * loads extension-less presigned URLs too), so the query-var URL needs no
	 * file extension. The original name is appended only as a hint / for nicer
	 * download file names.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $filename      Original file name (optional, hint only).
	 * @return string
	 */
	public static function url( $attachment_id, $filename = '' ) {
		$args = array( self::QUERY_VAR => (int) $attachment_id );
		if ( '' !== $filename ) {
			$args['twacz_name'] = rawurlencode( $filename );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Serves the requested attachment when our query var is present, then exits.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only file endpoint; no state mutation. Access is gated by attachment type and parent-post visibility below.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only file endpoint; no state mutation.
		$attachment_id = absint( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		if ( $attachment_id <= 0 ) {
			return;
		}
		$this->serve( $attachment_id );
	}

	/**
	 * Validates and streams the attachment, or sends an error status and exits.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function serve( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! ( $post instanceof \WP_Post ) || 'attachment' !== $post->post_type ) {
			$this->abort( 404 );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
			$this->abort( 404 );
		}

		// Only ever serve web archives — never arbitrary files.
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'wacz' !== $ext && 'warc' !== $ext ) {
			$this->abort( 404 );
		}

		// Confine to the uploads directory (defence against path traversal).
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		$real    = realpath( $file );
		if ( false === $base || false === $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			$this->abort( 404 );
		}

		if ( ! $this->can_read( $post ) ) {
			$this->abort( 403 );
		}

		$type = ( 'warc' === $ext ) ? 'application/warc' : 'application/wacz';
		$this->stream( $real, $type, wp_basename( $file ) );
	}

	/**
	 * Whether the current visitor may read this attachment.
	 *
	 * Public when the parent item is publicly viewable; otherwise only users
	 * who can read that specific parent post. Orphan attachments require the
	 * upload capability.
	 *
	 * @param \WP_Post $post Attachment post.
	 * @return bool
	 */
	private function can_read( \WP_Post $post ) {
		$parent_id = (int) $post->post_parent;
		if ( $parent_id > 0 ) {
			if ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( $parent_id ) ) {
				return true;
			}
			// A published parent item is public-facing; its archive is too. This
			// also covers the service worker's credential-less fetch, which would
			// otherwise be treated as anonymous and fail the read_post check.
			if ( 'publish' === get_post_status( $parent_id ) ) {
				return true;
			}
			return current_user_can( 'read_post', $parent_id );
		}
		return current_user_can( 'upload_files' );
	}

	/**
	 * Streams a file with HTTP Range support, then exits.
	 *
	 * @param string $file     Absolute, validated file path.
	 * @param string $type     MIME type to advertise.
	 * @param string $filename Download file name.
	 * @return void
	 */
	private function stream( $file, $type, $filename ) {
		$size  = (int) filesize( $file );
		$start = 0;
		$end   = $size - 1;
		$code  = 200;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Range is a standard cache/streaming header on a read-only endpoint; no state mutation.
		$range_header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range_header && preg_match( '/bytes=(\d*)-(\d*)/', $range_header, $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				if ( '' !== $m[2] ) {
					$end = (int) $m[2];
				}
			} elseif ( '' !== $m[2] ) {
				// Suffix range: the final N bytes.
				$start = max( 0, $size - (int) $m[2] );
			}

			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$code = 206;
		}

		$length = $end - $start + 1;

		// Keep the download file name free of characters that could break the
		// header quoting (CRLF is already rejected by PHP's header()).
		$safe_name = str_replace( array( '"', '\\', "\r", "\n" ), '', $filename );

		// Open before emitting success headers so a failure can still 500 cleanly.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Byte-range binary streaming; WP_Filesystem has no seek/partial-read API.
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			$this->abort( 500 );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// A .wacz is immutable, so let the browser cache ranges (private, since
		// access may be permission-gated) instead of re-fetching them — fewer
		// requests means less chance of tripping a request-rate WAF.
		$etag = '"' . md5( $file . '|' . $size . '|' . (string) filemtime( $file ) ) . '"';
		status_header( $code );
		header( 'Content-Type: ' . $type );
		// Never let a browser MIME-sniff the archive bytes into executable HTML.
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'ETag: ' . $etag );
		header( 'Content-Length: ' . $length );
		if ( 206 === $code ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Disposition: inline; filename="' . $safe_name . '"' );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the byte-range stream handle opened above.
			fclose( $handle );
			exit;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped -- Byte-range binary streaming for an HTTP Range response; the payload is a raw archive, not HTML.
		if ( $start > 0 ) {
			fseek( $handle, $start );
		}
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$buffer = fread( $handle, (int) min( self::CHUNK, $remaining ) );
			if ( false === $buffer ) {
				break;
			}
			echo $buffer;
			$remaining -= strlen( $buffer );
			flush();
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Sends an HTTP status and exits.
	 *
	 * @param int $code HTTP status code.
	 * @return void
	 */
	private function abort( $code ) {
		status_header( (int) $code );
		exit;
	}
}
