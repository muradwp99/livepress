<?php
/**
 * The post-type args a collection schema produces.
 *
 * Checked without WordPress: the function builds an array and hands it to
 * register_post_type, so the array is the thing worth asserting. Four of
 * these are load-bearing and fail silently when wrong.
 *
 * The function is lifted out with a regex rather than required, the way
 * option-visibility.test.php does it. `collections.php` opens with
 * `defined( 'ABSPATH' ) || exit;` and calls add_action at load, so requiring
 * it here would exit before the first assertion — silently, and looking like
 * a pass.
 *
 * check() rather than assert(): assert() is compiled out when
 * zend.assertions is off, which is the default in a production PHP build. A
 * test that evaporates depending on an ini setting is worse than no test.
 */
$src = file_get_contents( __DIR__ . '/../collections.php' );
preg_match( '/function livepress_collection_args\( array \$schema \): array \{.*?\n\}/s', $src, $m );
if ( ! $m ) { echo "  FAIL: could not lift livepress_collection_args\n"; exit( 1 ); }
eval( $m[0] );

/*
 * Also lift livepress_declared_collections() — the gate that must keep a
 * malformed schema from ever reaching the strictly-typed function above.
 * It calls livepress_schema(), which this file has no other reason to
 * define, so it is stubbed rather than lifted: the real one globs the
 * schema directory and requires WordPress to do it.
 *
 * The stub is an object, not a string: `! empty( $schema['collection'] )`
 * on a scalar is false with no error, guard or no guard, so a string here
 * would pass this assertion either way and prove nothing. On a plain object
 * (no ArrayAccess), `$schema['collection']` is an uncaught Error — "Cannot
 * use object of type stdClass as array" — which is exactly what is_array()
 * exists to keep this function from reaching.
 */
function livepress_schema() {
	return array( 'collection:broken' => new stdClass() );
}
preg_match( '/function livepress_declared_collections\(\): array \{.*?\n\}/s', $src, $d );
if ( ! $d ) { echo "  FAIL: could not lift livepress_declared_collections\n"; exit( 1 ); }
eval( $d[0] );

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { echo "  FAIL: $label\n"; $fail = 1; }
}

$schema = array(
	'collection'   => 'photo',
	'title'        => 'Photos',
	'singular'     => 'Photo',
	'menuIcon'     => 'dashicons-format-image',
	'frontendPath' => '/gallery/photos',
	'taxonomy'     => array( 'key' => 'photo_cat', 'label' => 'Category' ),
);

$out = livepress_collection_args( $schema );

check( 'post type is the collection name', 'photo' === $out['post_type'] );

/* show_in_rest, or the frontend cannot read the collection at all and the
   editor cannot save it. */
check( 'show_in_rest', true === $out['args']['show_in_rest'] );

/* page-attributes, or menu_order is not exposed and the `order` field writes
   into nothing — with a 200 either way. */
check( 'page-attributes for menu_order', in_array( 'page-attributes', $out['args']['supports'], true ) );

/* custom-fields, or meta does not appear over REST. */
check( 'custom-fields for meta', in_array( 'custom-fields', $out['args']['supports'], true ) );

/* public => false: these are rendered by the Next.js frontend. A public post
   type would give every photo a WordPress URL of its own, which is a second
   copy of the site for Google to find — the exact problem headless.php was
   written to fix. */
check( 'not public', false === $out['args']['public'] );

check( 'taxonomy key', 'photo_cat' === $out['taxonomy']['key'] );
check( 'taxonomy in rest', true === $out['taxonomy']['args']['show_in_rest'] );

/* No taxonomy declared means none registered, not an empty one. */
$bare = livepress_collection_args( array( 'collection' => 'video', 'singular' => 'Video', 'title' => 'Videos', 'menuIcon' => 'dashicons-video-alt3' ) );
check( 'no taxonomy when none declared', null === $bare['taxonomy'] );

/* A malformed (object) schema at a `collection:*` key must be dropped here —
   without is_array(), evaluating `! empty( $schema['collection'] )` on it is
   itself an uncaught Error, before livepress_collection_args() is ever
   reached. */
check( 'malformed (object) schema is skipped, not fataled', array() === livepress_declared_collections() );

if ( $fail ) { exit( 1 ); }
echo "collection args: all assertions passed (rest, page-attributes, custom-fields, non-public)\n";
