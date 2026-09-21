<?php
/**
 * Public reads and authenticated writes are governed by different lists.
 *
 * They used to share one. The failure that guards against is quiet: somebody
 * adds a key so an editor can change it, and in the same line publishes it to
 * anyone who can reach /wp-json/livepress/v1/globals/{key}. Nothing about
 * adding a key to a list called "option keys" suggests that is what you are
 * doing, which is exactly why it needed separating.
 */

/* Minimal WP shims — this runs under plain php, no WordPress. */
$GLOBALS['filters'] = array();
function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['filters'][ $hook ] )
		? call_user_func( $GLOBALS['filters'][ $hook ], $value )
		: $value;
}
function add_filter( $hook, $cb ) { $GLOBALS['filters'][ $hook ] = $cb; }

/* Lift both functions out of the plugin without booting it. */
$src = file_get_contents( __DIR__ . '/../livepress.php' );
preg_match( '/function livepress_public_option_keys\(\): array \{.*?\n\}/s', $src, $a );
preg_match( '/\nfunction livepress_option_keys\(\): array \{.*?\n\}/s', $src, $b );
eval( $a[0] . "\n" . $b[0] );

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { echo "  FAIL: $label\n"; $fail = 1; }
}

/* Defaults: design is both readable and writable, and that is intended. */
check( 'design is publicly readable', in_array( 'design', livepress_public_option_keys(), true ) );
check( 'design is writable', in_array( 'design', livepress_option_keys(), true ) );

/* The point of the split: a new WRITABLE key is not thereby published. */
add_filter( 'livepress_option_keys', function ( $keys ) {
	$keys[] = 'internal_pricing';
	return $keys;
} );
check( 'new key is writable', in_array( 'internal_pricing', livepress_option_keys(), true ) );
check(
	'new writable key is NOT publicly readable',
	! in_array( 'internal_pricing', livepress_public_option_keys(), true )
);

/* And publishing one is a separate, deliberate act. */
add_filter( 'livepress_public_option_keys', function ( $keys ) {
	$keys[] = 'internal_pricing';
	return $keys;
} );
check( 'publishing is possible when meant', in_array( 'internal_pricing', livepress_public_option_keys(), true ) );

echo $fail ? "option visibility: FAILED\n" : "option visibility: all assertions passed (writable and public lists are independent)\n";
exit( $fail );
