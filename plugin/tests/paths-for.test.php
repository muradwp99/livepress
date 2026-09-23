<?php
/**
 * Which frontend paths a saved collection item purges.
 *
 * A photo had no branch here at all, so a photo save purged nothing and
 * reached the site only when the ISR window ran out. It appears on its
 * collection's route and on every page that picks from the collection, and
 * both come off the schema — this checks they are derived, not listed.
 *
 * Lifted with a regex like collection-args.test.php, for the same reason:
 * requiring livepress.php would exit on its ABSPATH guard before the first
 * check, silently, looking like a pass. The schema is a stub, not the real
 * generated files, so this runs from the plugin directory alone.
 */
$src = file_get_contents( __DIR__ . '/../livepress.php' );
preg_match( '/function livepress_paths_for\( WP_Post \$post \): array \{.*?\n\}/s', $src, $m );
if ( ! $m ) { echo "  FAIL: could not lift livepress_paths_for\n"; exit( 1 ); }

class WP_Post {
	public $post_type;
	public $post_name;
	public function __construct( $type, $name ) { $this->post_type = $type; $this->post_name = $name; }
}

function livepress_collections() { return array( 'photo', 'project' ); }
function livepress_schema() {
	$field = fn( $key, $kind, $extra = array() ) => array( 'key' => $key, 'kind' => $kind ) + $extra;
	return array(
		'gallery'            => array( 'frontendPath' => '/gallery', 'sections' => array(
			array( 'key' => 'featured', 'fields' => array(
				$field( 'featured_tiles', 'repeater' ),
				$field( 'gal_featured_photos', 'pick', array( 'collection' => 'photo', 'max' => 8 ) ),
			) ),
		) ),
		'home'               => array( 'frontendPath' => '/', 'sections' => array(
			array( 'key' => 'hero', 'fields' => array( $field( 'hero_title', 'text' ) ) ),
		) ),
		/* Picks from another collection: must not be purged by a photo save. */
		'videos'             => array( 'frontendPath' => '/gallery/videos', 'sections' => array(
			array( 'key' => 'wall', 'fields' => array( $field( 'vid_pick', 'pick', array( 'collection' => 'video', 'max' => 4 ) ) ) ),
		) ),
		'collection:photo'   => array( 'collection' => 'photo', 'frontendPath' => '/gallery/photos', 'sections' => array(
			array( 'key' => 'photo', 'fields' => array( $field( 'photo_src', 'image' ) ) ),
		) ),
		'collection:project' => array( 'collection' => 'project', 'frontendPath' => '/projects/{slug}', 'sections' => array(
			array( 'key' => 'project', 'fields' => array( $field( 'project_title', 'text' ) ) ),
		) ),
	);
}

eval( $m[0] );

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { echo "  FAIL: $label\n"; $fail = 1; }
}
function paths( $type, $name ) {
	$p = array_values( array_unique( livepress_paths_for( new WP_Post( $type, $name ) ) ) );
	sort( $p );
	return $p;
}

/* A photo: its own listing, and the page whose pick field points at photos. */
check( 'photo purges /gallery/photos and /gallery', array( '/gallery', '/gallery/photos' ) === paths( 'photo', 'x' ) );

/* An item with a page of its own gets that page, slug filled in, and no
   page picks from projects here. */
check( 'project purges its own page', array( '/projects/villa' ) === paths( 'project', 'villa' ) );

/* Site pages are unchanged. */
check( 'sitepage still purges itself and the sitemap', array( '/', '/sitemap.xml' ) === paths( 'sitepage', 'home' ) );

if ( $fail ) { exit( 1 ); }
echo "paths for: all assertions passed (collection route plus every page that picks from it)\n";
