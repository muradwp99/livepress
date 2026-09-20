<?php
/**
 * Give Rank Math something to read on a Site Page.
 *
 * Articles score 77 to 86 here because Rank Math can see their 76KB of
 * post_content. Site Pages score nothing at all, and would score nothing
 * however good they were: their copy lives in post meta and `post_content` is
 * literally zero bytes, so every SEO test runs against an empty document.
 * Turning on the analysis panel without fixing that would hand the marketer a
 * confident number computed from nothing.
 *
 * So the page's own words are written into `post_content` whenever it is
 * saved. Nothing renders that field — the frontend fetches `id`, `slug`,
 * `meta` and `r3d_seo`, and the editor reads meta — so it is free to hold the
 * plain-text version of the page, which is exactly what an SEO analyser wants
 * and what WordPress search wants too.
 *
 * The text is built by walking the schema, not by fetching the rendered page:
 * no dependency on the frontend being up, and no navigation, buttons or
 * cookie bars in the analysed copy.
 */

defined( 'ABSPATH' ) || exit;

/** Keys that hold an address or an asset rather than something to read. */
function livepress_is_prose_key( string $key ): bool {
	return ! preg_match( '/(^|_)(img|image|src|href|url|icon|id|slug|poster|alt|video|mp4)($|_)/i', $key );
}

/** The page's copy, in reading order, as plain text. */
function livepress_page_text( WP_Post $post ): string {
	$schemas = livepress_schema();
	$schema  = $schemas[ $post->post_name ] ?? null;
	if ( ! $schema ) {
		return '';
	}

	$parts = array();

	foreach ( $schema['sections'] as $section ) {
		foreach ( $section['fields'] as $field ) {
			if ( ! livepress_is_prose_key( $field['key'] ) ) {
				continue;
			}
			$value = get_post_meta( $post->ID, $field['key'], true );
			if ( '' === $value || null === $value ) {
				$value = $field['default'] ?? '';
			}
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			if ( 'repeater' === ( $field['kind'] ?? '' ) ) {
				$rows = json_decode( $value, true );
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $row as $k => $v ) {
						if ( livepress_is_prose_key( (string) $k ) && is_string( $v ) && '' !== trim( $v ) ) {
							$parts[] = trim( $v );
						}
					}
				}
				continue;
			}

			$parts[] = trim( $value );
		}
	}

	if ( ! $parts ) {
		return '';
	}

	/* Paragraphs, so Rank Math's word count and readability tests see prose
	   rather than one run-on line. */
	return implode( "\n\n", array_unique( $parts ) );
}

/**
 * Write that text into the document.
 *
 * `wp_update_post` fires `save_post`, which is what called this — so a static
 * guard stops the second pass. Without it the first save recurses until PHP
 * gives up, and the symptom is a save that simply never returns.
 */
function livepress_sync_page_text( int $post_id ): bool {
	static $busy = false;
	if ( $busy ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post || 'sitepage' !== $post->post_type ) {
		return false;
	}

	$text = livepress_page_text( $post );
	if ( '' === $text || $text === $post->post_content ) {
		return false;
	}

	$busy = true;
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $text,
		)
	);
	$busy = false;

	return true;
}

/* Runs at shutdown so it sees the finished state: the editor writes every
   field as its own meta update, and syncing on each one would rebuild the
   whole page text dozens of times per save. */
add_action(
	'updated_post_meta',
	function ( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );
		if ( 0 === strpos( (string) $meta_key, '_' ) ) {
			return; // history and other bookkeeping
		}
		$post = get_post( (int) $post_id );
		if ( ! $post || 'sitepage' !== $post->post_type ) {
			return;
		}

		static $queued = array();
		if ( isset( $queued[ $post_id ] ) ) {
			return;
		}
		$queued[ $post_id ] = true;

		add_action(
			'shutdown',
			function () use ( $post_id ) {
				livepress_sync_page_text( (int) $post_id );
			},
			95
		);
	},
	10,
	3
);

/** Backfill every page, for the first run and after a schema change. */
add_action(
	'admin_post_livepress_sync_text',
	function () {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'livepress_sync_text' );

		$done = 0;
		foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
			if ( livepress_sync_page_text( $p->ID ) ) {
				$done++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'livepress-health', 'synced' => $done ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
);
