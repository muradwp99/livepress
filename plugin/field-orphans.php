<?php
/**
 * Content the schema has lost the address of.
 *
 * A schema change is a data migration. Rename `hero_title` to `hero_heading`
 * and WordPress has no meta under the new key, so the page falls back to its
 * built-in copy, the editor shows that copy, and the words somebody wrote sit
 * in the database under a key nothing reads. No error, and the page looks
 * fine — which is the whole problem.
 *
 * `scripts/livepress-gen.ts` now catches this at author time by diffing each
 * run against a lockfile. This screen is the other half: what is already
 * stranded, and how to get it back.
 *
 * It found 46 rows on this install the first time it ran — `meta_title` and
 * `meta_description` on all 23 managed pages, orphaned when SEO moved to Rank
 * Math's own keys. That migration happened to copy the values across first,
 * so nothing was lost; it was luck rather than design, and nobody knew the
 * rows were there.
 *
 * Named `field-orphans.php`, not `schema-health.php`: `livepress_schema()`
 * requires every `schema-*.php` in this directory, so a file matching that
 * glob is executed as if it were a generated page schema — and executed
 * again on the next call, which fatals the moment it declares a function.
 *
 * Rank Math's own meta is excluded. Those keys are real content owned by
 * another plugin, not orphans — listing them would bury the genuine finds
 * under sixty rows of false positives.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_SCHEMA_PAGE = 'livepress-schema';

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Schema', 'livepress' ), __( 'Schema', 'livepress' ), 'manage_options', LIVEPRESS_SCHEMA_PAGE, 'livepress_render_schema_health' );
	},
	24
);

/** Meta keys that belong to somebody else and are not ours to call orphaned. */
function livepress_foreign_meta_key( string $key ): bool {
	return 0 === strpos( $key, '_' )
		|| 0 === strpos( $key, 'rank_math' )
		|| in_array( $key, array( 'footnotes', 'inline_featured_image' ), true );
}

/** Every key a page's schema declares, plus the ones the editor writes itself. */
function livepress_declared_keys( string $slug ): ?array {
	$schemas = livepress_schema();
	if ( ! isset( $schemas[ $slug ] ) ) {
		return null;
	}
	$keys = array( 'section_order' => true );
	foreach ( $schemas[ $slug ]['sections'] as $section ) {
		foreach ( $section['fields'] as $field ) {
			$keys[ $field['key'] ] = $field['label'] ?? $field['key'];
		}
	}
	return $keys;
}

/**
 * Stored values no schema claims.
 *
 * Grouped by key rather than by page: an orphan is almost always the result of
 * one rename, so it appears on every page at once, and twenty-three rows
 * saying the same thing is a worse answer than one row saying "on 23 pages".
 */
function livepress_orphaned_meta(): array {
	$by_key = array();

	foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
		$declared = livepress_declared_keys( $post->post_name );
		if ( null === $declared ) {
			continue; // no schema for this document at all; not a field-level orphan
		}
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( isset( $declared[ $key ] ) || livepress_foreign_meta_key( $key ) ) {
				continue;
			}
			$value = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue; // an empty orphan is not content anybody lost
			}
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = array( 'key' => $key, 'rows' => array(), 'bytes' => 0 );
			}
			$by_key[ $key ]['rows'][] = array(
				'id'      => $post->ID,
				'slug'    => $post->post_name,
				'value'   => $value,
			);
			$by_key[ $key ]['bytes'] += strlen( $value );
		}
	}

	ksort( $by_key );
	return $by_key;
}

/**
 * Move every stored value from one key to another.
 *
 * Refuses to overwrite: a target that already holds something is skipped and
 * reported, because the case this exists for is recovering content, and
 * writing over the live value to do it would be self-defeating.
 */
function livepress_remap_meta( string $from, string $to ): array {
	$moved   = 0;
	$skipped = array();

	foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
		$old = get_post_meta( $post->ID, $from, true );
		if ( ! is_string( $old ) || '' === trim( $old ) ) {
			continue;
		}
		$declared = livepress_declared_keys( $post->post_name );
		if ( null === $declared || ! isset( $declared[ $to ] ) ) {
			continue; // that page's schema does not have the target field
		}
		$current = get_post_meta( $post->ID, $to, true );
		if ( is_string( $current ) && '' !== trim( $current ) && $current !== $old ) {
			$skipped[] = $post->post_name;
			continue;
		}
		/* wp_slash: update_post_meta unslashes, and a repeater's JSON writes
		   newlines as backslash-n. Recovering stranded content only to strip
		   its line breaks on the way in would defeat the point of the screen. */
		update_post_meta( $post->ID, $to, wp_slash( $old ) );
		delete_post_meta( $post->ID, $from );
		$moved++;
	}

	return array( 'moved' => $moved, 'skipped' => $skipped );
}

add_action(
	'admin_post_livepress_schema_action',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to do that.', 'livepress' ), 403 );
		}
		check_admin_referer( 'livepress_schema_action' );

		$key  = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		$args = array( 'page' => LIVEPRESS_SCHEMA_PAGE );

		if ( '' === $key || livepress_foreign_meta_key( $key ) ) {
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'remap' === $what && '' !== $to ) {
			$result          = livepress_remap_meta( $key, $to );
			$args['moved']   = $result['moved'];
			$args['skipped'] = count( $result['skipped'] );
			$args['to']      = $to;
		} elseif ( 'delete' === $what ) {
			$gone = 0;
			foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
				if ( '' !== (string) get_post_meta( $post->ID, $key, true ) ) {
					delete_post_meta( $post->ID, $key );
					$gone++;
				}
			}
			$args['deleted'] = $gone;
			$args['key']     = $key;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
);

/** Fields any page declares, for the remap target list. */
function livepress_all_declared_fields(): array {
	$out = array();
	foreach ( livepress_schema() as $page ) {
		foreach ( $page['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$out[ $field['key'] ] = $field['label'] ?? $field['key'];
			}
		}
	}
	ksort( $out );
	return $out;
}

function livepress_render_schema_health() {
	$orphans = livepress_orphaned_meta();
	$fields  = livepress_all_declared_fields();

	livepress_screen_open(
		__( 'Schema', 'livepress' ),
		__( 'Stored content that no field claims. This happens when a field is renamed or removed: the value stays in the database under the old key, the page quietly falls back to its built-in copy, and nothing reports an error. The generator now catches this before a deploy; this screen finds what is already here.', 'livepress' )
	);

	foreach ( array(
		'moved'   => array( 'ok', 'Moved %d value(s) to <code>%s</code>.' ),
		'deleted' => array( 'ok', __( 'Deleted %d stored value(s).', 'livepress' ) ),
	) as $param => $meta ) {
		if ( ! isset( $_GET[ $param ] ) ) {
			continue;
		}
		printf(
			'<div class="lp-notice lp-notice--ok"><p>' . $meta[1] . '</p></div>',
			(int) $_GET[ $param ],
			esc_html( sanitize_key( wp_unslash( $_GET['to'] ?? $_GET['key'] ?? '' ) ) )
		);
	}
	if ( ! empty( $_GET['skipped'] ) ) {
		printf(
			'<div class="lp-notice"><p>%s</p></div>',
			/* translators: %d is how many pages were untouched. */
			esc_html( sprintf( __( '%d page(s) were left alone because the target field already holds something different. Nothing was overwritten — open those pages and merge by hand.', 'livepress' ), (int) $_GET['skipped'] ) )
		);
	}

	$total_rows  = array_sum( array_map( static fn( $o ) => count( $o['rows'] ), $orphans ) );
	$total_bytes = array_sum( array_map( static fn( $o ) => $o['bytes'], $orphans ) );

	livepress_figures(
		array(
			array( 'value' => count( $fields ), 'label' => __( 'fields declared', 'livepress' ) ),
			array( 'value' => count( $orphans ), 'label' => __( 'orphaned keys', 'livepress' ), 'tone' => $orphans ? 'warn' : 'quiet' ),
			array( 'value' => $total_rows, 'label' => __( 'stored values stranded', 'livepress' ), 'tone' => $total_rows ? 'warn' : 'quiet' ),
			array( 'value' => size_format( $total_bytes ), 'label' => __( 'of content', 'livepress' ), 'tone' => 'quiet' ),
		)
	);

	if ( ! $orphans ) {
		livepress_empty_state(
			__( 'Every stored value has a field', 'livepress' ),
			__( 'Nothing in the database is stranded under a key the schema no longer declares.', 'livepress' )
		);
		livepress_screen_close();
		return;
	}

	livepress_table_open(
		array(
			array( __( 'Orphaned key', 'livepress' ), 'lp-shrink' ),
			array( __( 'Found on', 'livepress' ), 'lp-shrink' ),
			array( __( 'What it holds', 'livepress' ), '' ),
			array( '', 'lp-shrink' ),
		)
	);

	foreach ( $orphans as $orphan ) {
		$rows    = $orphan['rows'];
		$sample  = $rows[0]['value'];
		$pages   = count( $rows );
		$nonce   = wp_nonce_field( 'livepress_schema_action', '_wpnonce', true, false );

		$options = '<option value="">' . esc_html__( 'Move to…', 'livepress' ) . '</option>';
		foreach ( $fields as $key => $label ) {
			$options .= sprintf( '<option value="%s">%s (%s)</option>', esc_attr( $key ), esc_html( $label ), esc_html( $key ) );
		}

		printf(
			'<tr>'
				. '<td class="lp-shrink"><span class="lp-title">%s</span><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink lp-muted">%s</td>'
				. '<td><code class="lp-code">%s</code></td>'
				. '<td class="lp-shrink">'
					. '<form method="post" action="%s" class="lp-inline-form">%s'
						. '<input type="hidden" name="action" value="livepress_schema_action">'
						. '<input type="hidden" name="key" value="%s">'
						. '<select name="to" class="lp-input lp-input--sm" required>%s</select>'
						. '<button class="lp-btn lp-btn--sm" type="submit" name="what" value="remap">' . esc_html__( 'Remap', 'livepress' ) . '</button>'
						. '<button class="lp-btn lp-btn--sm lp-btn--danger" type="submit" name="what" value="delete" '
							. 'onclick="return confirm(&#039;' . esc_attr( esc_js( sprintf( /* translators: %d is how many pages hold the value. */ __( 'Delete this stored content on %d page(s)? This cannot be undone.', 'livepress' ), $pages ) ) ) . '&#039;)">' . esc_html__( 'Delete', 'livepress' ) . '</button>'
					. '</form>'
				. '</td>'
			. '</tr>',
			esc_html( $orphan['key'] ),
			esc_html( size_format( $orphan['bytes'] ) ),
			/* translators: %d is how many pages hold this orphaned value. */
			esc_html( sprintf( _n( '%d page', '%d pages', $pages, 'livepress' ), $pages ) ),
			esc_html( mb_substr( $sample, 0, 220 ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			$nonce,
			esc_attr( $orphan['key'] ),
			$options
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
