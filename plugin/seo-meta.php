<?php
/**
 * The SEO fields, claimed for `sitepage` specifically.
 *
 * livepress.php registers every schema field as post meta for ALL post types
 * at once. Rank Math registers the same three `rank_math_*` keys the same way,
 * with `auth_callback => __return_false` — its way of saying "write these
 * through my own UI, not the REST API". That is one shared slot per key, last
 * registration wins, and Rank Math runs after this plugin.
 *
 * So the editor's "Page metadata" section could not save at all. WordPress
 * answered `403 rest_cannot_update` — "Sorry, you are not allowed to edit the
 * rank_math_title custom field" — and the Save button said "Save failed".
 *
 * It hid well, in a way worth remembering. WordPress skips the permission
 * check entirely when a submitted value is identical to the stored one, so
 * saving a page nobody had actually edited returned a cheerful 200. The
 * failure appeared only once somebody typed something, which is also the only
 * time anybody would notice, and it made the first attempt to reproduce it
 * look like the feature worked.
 *
 * Registering the same keys against the `sitepage` subtype puts them in a
 * different bucket. REST merges subtype over generic — see
 * `WP_REST_Post_Meta_Fields::get_registered_fields()` — so this wins for the
 * pages this plugin owns, and Rank Math's posture on ordinary posts and pages
 * is left exactly as it was. Nothing is patched, overridden or unhooked; the
 * narrower registration simply takes precedence where it applies.
 *
 * The callback is a real capability check rather than `__return_true`: whoever
 * may edit the page may edit its SEO, and nobody else.
 *
 * @package LivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Priority 99 so this lands after Rank Math has registered its own.
 *
 * Strictly it need not: a subtype registration and a generic one live in
 * different buckets, so ordering does not decide this one. It runs late anyway
 * because the cost is nothing and the failure it would mask is invisible.
 */
add_action(
	'init',
	function () {
		foreach ( livepress_schema() as $schema ) {
			foreach ( $schema['sections'] as $section ) {
				foreach ( $section['fields'] as $field ) {
					if ( 0 !== strpos( $field['key'], 'rank_math_' ) ) {
						continue;
					}

					register_post_meta(
						'sitepage',
						$field['key'],
						array(
							'single'        => true,
							'type'          => 'string',
							'show_in_rest'  => true,
							'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
								return current_user_can( 'edit_post', $object_id );
							},
						)
					);
				}
			}
		}
	},
	99
);
