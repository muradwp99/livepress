<?php
/**
 * Collections: many posts of one type, registered from their own schema.
 *
 * LivePress could already edit a collection — the editor routes any post type
 * in `livepress_collections()` into the fullscreen screen, resolves a
 * `collection:{post_type}` schema and previews `frontendPath`. What it could
 * not do was know a collection existed. The filter returned an empty array,
 * so the capability sat switched off.
 *
 * Now a `collection:*` schema is the whole declaration: the post type, its
 * labels, its taxonomy and its fields come off one file, the same way a page
 * does. There is no second place to keep in step — which is the failure this
 * plugin hit when schemas were generated and never deployed.
 *
 * @package LivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turn a collection schema into arguments for register_post_type.
 *
 * Separated from the registration so it can be asserted without WordPress.
 *
 * @param array $schema One `collection:*` entry.
 * @return array{post_type:string,args:array,taxonomy:?array}
 */
function livepress_collection_args( array $schema ): array {
	$type     = (string) $schema['collection'];
	$singular = (string) ( $schema['singular'] ?? $type );
	$plural   = (string) ( $schema['title'] ?? $singular );

	$args = array(
		'labels'        => array(
			'name'          => $plural,
			'singular_name' => $singular,
			'all_items'     => $plural,
			'add_new_item'  => 'Add ' . $singular,
			'edit_item'     => 'Edit ' . $singular,
		),
		/*
		 * Not public. These are rendered by the headless frontend; a public
		 * post type would mint a WordPress URL for every item, which is a
		 * second copy of the site for search engines to find — the problem
		 * headless.php exists to close.
		 */
		'public'        => false,
		'show_ui'       => true,
		'show_in_menu'  => true,
		'show_in_rest'  => true,
		'menu_icon'     => (string) ( $schema['menuIcon'] ?? 'dashicons-admin-post' ),
		'menu_position' => 5,
		/*
		 * page-attributes exposes menu_order, which the `order` field writes
		 * to. custom-fields exposes meta over REST. Without either, saves
		 * return 200 and store nothing.
		 */
		'supports'      => array( 'title', 'custom-fields', 'page-attributes' ),
	);

	$taxonomy = null;
	/* The `?? null` guards nothing: empty() alone raises no notice here, for
	   a missing `taxonomy`, a non-array one or a boolean one alike — checked
	   and recorded when this was reviewed. It stays only because it is
	   harmless; do not read it as evidence of a hazard. */
	if ( ! empty( $schema['taxonomy']['key'] ?? null ) ) {
		$taxonomy = array(
			'key'  => (string) $schema['taxonomy']['key'],
			'args' => array(
				'labels'            => array(
					'name'          => (string) $schema['taxonomy']['label'],
					'singular_name' => (string) $schema['taxonomy']['label'],
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
			),
		);
	}

	return array( 'post_type' => $type, 'args' => $args, 'taxonomy' => $taxonomy );
}

/** Every collection the loaded schemas declare. */
function livepress_declared_collections(): array {
	$out = array();
	foreach ( livepress_schema() as $key => $schema ) {
		/* is_array() before anything reads into $schema: register_post_type()
		   below takes it through livepress_collection_args( array $schema ),
		   which is strictly typed. A non-array value there is an uncaught
		   TypeError on init — every admin request fataled, not just one
		   collection skipped. Malformed is dropped, never fataled. */
		if ( 0 === strpos( (string) $key, 'collection:' ) && is_array( $schema ) && ! empty( $schema['collection'] ) ) {
			$out[] = $schema;
		}
	}
	return $out;
}

add_action(
	'init',
	function () {
		foreach ( livepress_declared_collections() as $schema ) {
			$built = livepress_collection_args( $schema );
			register_post_type( $built['post_type'], $built['args'] );
			if ( $built['taxonomy'] ) {
				register_taxonomy( $built['taxonomy']['key'], $built['post_type'], $built['taxonomy']['args'] );
			}
		}
	},
	5
);

/* Priority 5: before the meta registration at 20, which walks every schema
   including these, so a collection's fields are registered as meta too. */

add_filter(
	'livepress_collections',
	function ( array $types ): array {
		foreach ( livepress_declared_collections() as $schema ) {
			$types[] = (string) $schema['collection'];
		}
		return array_values( array_unique( $types ) );
	}
);
