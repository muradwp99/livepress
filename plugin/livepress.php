<?php
/**
 * Plugin Name: LivePress
 * Plugin URI:  https://github.com/muradwp99/livepress
 * Description: Realtime visual editing for headless WordPress. One "Site Pages" list; every page opens a fullscreen editor — fields left, live preview of your real frontend right — streaming every keystroke into the rendered site before saving.
 * Version:     1.0.0
 * Author:      Murad
 * License:     MIT
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_PAGE = 'livepress-editor';

/**
 * Global option keys the editor can read/write (stored as `livepress_{key}`).
 * Filter `livepress_option_keys` to extend.
 */
function livepress_option_keys(): array {
	return apply_filters( 'livepress_option_keys', array( 'design', 'nav', 'footer' ) );
}

/**
 * Base URL of the headless frontend the editor previews.
 * Set the `livepress_frontend` option or filter `livepress_frontend_url`.
 */
function livepress_frontend(): string {
	$url = get_option( 'livepress_frontend', 'http://localhost:3000' );
	return apply_filters( 'livepress_frontend_url', $url );
}

/** Page schemas: key => { title, frontendPath, sections[] }. See livepress-schema.php. */
function livepress_schema(): array {
	return require __DIR__ . '/livepress-schema.php';
}

/**
 * Collections (custom post types) whose edit screens open in the LivePress
 * editor. Each needs a `collection:{post_type}` schema entry.
 * Filter `livepress_collections` to extend.
 */
function livepress_collections(): array {
	return apply_filters( 'livepress_collections', array() );
}

/* ------------------------------------------------------------------ */
/* Site Pages CPT — ONE list for every editable page                    */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
	register_post_type( 'sitepage', array(
		'labels'        => array(
			'name'          => 'Site Pages',
			'singular_name' => 'Site Page',
			'all_items'     => 'All Site Pages',
		),
		'public'        => false,
		'show_ui'       => true,
		'show_in_menu'  => true,
		'show_in_rest'  => true,
		'menu_icon'     => 'dashicons-welcome-widgets-menus',
		'menu_position' => 4,
		'supports'      => array( 'title', 'custom-fields' ),
	) );
} );

/** Every schema field is REST-visible post meta — no field plugin needed. */
add_action( 'init', function () {
	foreach ( livepress_schema() as $schema ) {
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				register_meta( 'post', $field['key'], array(
					'single'       => true,
					'type'         => 'string',
					'show_in_rest' => true,
				) );
			}
		}
	}
	register_meta( 'post', 'section_order', array( 'single' => true, 'type' => 'string', 'show_in_rest' => true ) );
}, 20 );

/* ------------------------------------------------------------------ */
/* REST: globals read + authenticated writes (self-contained)           */
/* ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'livepress/v1', '/globals/(?P<key>[a-z_]+)', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $request ) {
			$key = sanitize_key( $request['key'] );
			if ( ! in_array( $key, livepress_option_keys(), true ) ) {
				return new WP_Error( 'not_found', 'Unknown global', array( 'status' => 404 ) );
			}
			return rest_ensure_response( get_option( 'livepress_' . $key, null ) );
		},
	) );

	register_rest_route( 'livepress/v1', '/option/(?P<key>[a-z_]+)', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'callback'            => function ( $request ) {
			$key = sanitize_key( $request['key'] );
			if ( ! in_array( $key, livepress_option_keys(), true ) ) {
				return new WP_Error( 'not_found', 'Unknown global', array( 'status' => 404 ) );
			}
			$data = $request->get_json_params();
			if ( null === $data ) {
				return new WP_Error( 'bad_request', 'Body must be JSON', array( 'status' => 400 ) );
			}
			update_option( 'livepress_' . $key, $data );
			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );
} );

/* ------------------------------------------------------------------ */
/* Admin menu: Site Pages · Menus · Design                              */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page( 'LivePress', 'LivePress', 'edit_pages', 'livepress', function () {}, 'dashicons-visibility', 3 );
	add_submenu_page( 'livepress', 'Site Pages', 'Site Pages', 'edit_pages', 'edit.php?post_type=sitepage' );
	add_submenu_page( 'livepress', 'Menus', 'Menus', 'edit_theme_options', 'livepress-menus', 'livepress_render_menus' );
	add_submenu_page( 'livepress', 'Design', 'Design', 'edit_theme_options', 'livepress-design', 'livepress_render_design' );
	remove_submenu_page( 'livepress', 'livepress' );
	add_submenu_page( '', 'LivePress', 'LivePress', 'edit_pages', LIVEPRESS_PAGE, 'livepress_render_editor' );
} );

/** Editing a Site Page or a live-enabled collection doc opens LivePress. */
add_action( 'admin_init', function () {
	global $pagenow;
	if ( 'post.php' !== $pagenow || empty( $_GET['post'] ) || 'edit' !== ( $_GET['action'] ?? '' ) ) {
		return;
	}
	$post = get_post( (int) $_GET['post'] );
	if ( $post && ( 'sitepage' === $post->post_type || in_array( $post->post_type, livepress_collections(), true ) ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $post->ID ) );
		exit;
	}
} );

/* ------------------------------------------------------------------ */
/* The fullscreen editor                                                */
/* ------------------------------------------------------------------ */

function livepress_render_editor() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	$allowed = $post && ( 'sitepage' === $post->post_type || in_array( $post->post_type, livepress_collections(), true ) );
	if ( ! $allowed ) {
		echo '<div class="notice notice-error"><p>No LivePress-enabled document selected.</p></div>';
		return;
	}
	$schemas       = livepress_schema();
	$is_collection = 'sitepage' !== $post->post_type;
	$schema        = $is_collection
		? ( $schemas[ 'collection:' . $post->post_type ] ?? null )
		: ( $schemas[ $post->post_name ] ?? null );
	if ( ! $schema ) {
		echo '<div class="notice notice-error"><p>No LivePress schema for "' . esc_html( $post->post_name ) . '".</p></div>';
		return;
	}
	$frontend_path = str_replace( '{slug}', $post->post_name, $schema['frontendPath'] );

	// Current values for every field in the schema.
	$values = array(
		'section_order' => (string) get_post_meta( $post->ID, 'section_order', true ),
	);
	foreach ( $schema['sections'] as $section ) {
		foreach ( $section['fields'] as $field ) {
			$raw = get_post_meta( $post->ID, $field['key'], true );
			if ( 'repeater' === $field['kind'] ) {
				$decoded                  = json_decode( is_string( $raw ) ? $raw : '[]', true );
				$values[ $field['key'] ] = is_array( $decoded ) ? $decoded : array();
			} else {
				$values[ $field['key'] ] = is_string( $raw ) ? $raw : '';
			}
		}
	}

	livepress_enqueue_editor( array(
		'mode'     => $is_collection ? 'collection' : 'page',
		'postId'   => $post->ID,
		'restBase' => $post->post_type,
		'title'    => $post->post_title,
		'slug'     => $post->post_name,
		'frontend' => livepress_frontend(),
		'path'     => $frontend_path,
		'backUrl'  => admin_url( 'edit.php?post_type=' . $post->post_type ),
		'schema'   => $schema,
		'values'   => $values,
	) );
}

/** Shared shell for the standalone Menus / Design fullscreen editors. */
function livepress_render_globals_editor( $mode, $title ) {
	$globals = array();
	foreach ( livepress_option_keys() as $key ) {
		$value = get_option( 'livepress_' . $key, array() );
		$globals[ $key ] = ( 'nav' === $key ) ? array_values( (array) $value ) : (object) ( $value ?: array() );
	}
	livepress_enqueue_editor( array(
		'mode'     => $mode,
		'postId'   => 0,
		'title'    => $title,
		'frontend' => livepress_frontend(),
		'path'     => '/',
		'backUrl'  => admin_url(),
		'schema'   => array( 'sections' => array() ),
		'values'   => array(),
		'globals'  => $globals,
	) );
}
function livepress_render_menus() {
	livepress_render_globals_editor( 'menus', 'Site Menus' );
}
function livepress_render_design() {
	livepress_render_globals_editor( 'design', 'Design' );
}

/** Enqueue the editor app with its boot payload. */
function livepress_enqueue_editor( array $boot ) {
	wp_enqueue_media();
	wp_enqueue_script( 'wp-api-fetch' );
	wp_enqueue_script( 'livepress-editor', plugins_url( 'assets/editor.js', __FILE__ ), array( 'wp-api-fetch' ), '1.0.0', true );
	wp_enqueue_style( 'livepress-editor', plugins_url( 'assets/editor.css', __FILE__ ), array(), '1.0.0' );
	wp_add_inline_script( 'livepress-editor', 'window.LIVEPRESS = ' . wp_json_encode( $boot ) . ';', 'before' );
	echo '<div id="livepress-root"></div>';
}

/** Fullscreen: hide every piece of WP admin chrome on editor pages. */
add_action( 'admin_head', function () {
	if ( ! in_array( $_GET['page'] ?? '', array( LIVEPRESS_PAGE, 'livepress-menus', 'livepress-design' ), true ) ) {
		return;
	}
	echo '<style>
		#adminmenumain, #adminmenuback, #adminmenuwrap, #wpadminbar, #wpfooter, #screen-meta-links { display: none !important; }
		#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
		html.wp-toolbar { padding-top: 0 !important; }
		#wpbody-content .notice { display: none; }
	</style>';
} );
