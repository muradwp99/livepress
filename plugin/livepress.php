<?php
/**
 * Plugin Name: LivePress
 * Plugin URI:  https://github.com/muradwp99/livepress
 * Description: Realtime visual editing for headless WordPress. One "Site Pages" list; every page opens a fullscreen editor — fields left, live preview of your real frontend right — streaming every keystroke into the rendered site before saving.
 * Version:     1.5.2
 * Author:      Murad
 * License:     MIT
 * Text Domain: livepress
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_PAGE = 'livepress-editor';

/**
 * Translations.
 *
 * Every string here was hardcoded English, which was fine while the plugin ran
 * on one studio's site and is not now it is published: without a text domain
 * it cannot be translated at all, however willing somebody is.
 *
 * `load_plugin_textdomain` rather than leaning on WordPress's automatic
 * loading, because that only covers plugins hosted on wordpress.org and this
 * one installs from a zip. On `init` because WordPress 6.7 started warning
 * when a domain loads earlier, and the warning is right — nothing here needs
 * a translated string before then.
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'livepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/* Content health and Field history screens. */
require_once __DIR__ . '/admin-screens.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/field-orphans.php';
require_once __DIR__ . '/migration.php';
require_once __DIR__ . '/broken-links.php';
require_once __DIR__ . '/enquiries.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/page-text.php';
require_once __DIR__ . '/asset-check.php';
require_once __DIR__ . '/schedule.php';

/**
 * Global option keys the PUBLIC, unauthenticated read route will serve.
 *
 * Split from `livepress_option_keys()` because one list was gating both, and
 * the two questions are not the same question. That list says what an editor
 * may change; this one says what the whole internet may read. While they were
 * the same array, adding a key so somebody could edit it also published it —
 * silently, with no reason for anyone adding a key to think about it.
 *
 * `design` is here because it has to be: the headless frontend fetches brand
 * tokens on every revalidation with no credential to offer, and they are
 * colours that are visible on the site anyway. Nothing else should join it
 * without a deliberate reason — filter `livepress_public_option_keys`, not
 * this default, and remember that "public" here means public.
 */
function livepress_public_option_keys(): array {
	return apply_filters( 'livepress_public_option_keys', array( 'design' ) );
}

/**
 * Global option keys an authenticated editor may WRITE (stored as
 * `livepress_{key}`). Filter `livepress_option_keys` to extend — and note
 * that extending it no longer publishes the key; see above.
 */
function livepress_option_keys(): array {
	/* `nav` and `footer` were here too. Their panels drove options no part of
	   the frontend ever read, and the nav the site renders comes from the
	   site-chrome document instead — so advertising them on a public REST
	   route only invited somebody to write values nothing would honour. */
	return apply_filters( 'livepress_option_keys', array( 'design' ) );
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
	/*
	 * Memoised, and that is not only about speed.
	 *
	 * `livepress-schema.php` globs `schema-*.php` and `require`s each one — so
	 * every call re-read twenty-four files from disk, and this is called from
	 * register_meta, the tracked-key list, content health and more.
	 *
	 * The sharper problem is that a plain `require` runs a file again. The
	 * generated schema files only return an array, so re-running them is
	 * harmless; any file in that directory that declares a function is not,
	 * and the second call takes the whole site down with "cannot redeclare".
	 * A screen called `schema-health.php` did exactly that. It is now named
	 * `field-orphans.php`, and this static means one require regardless.
	 */
	static $schemas = null;
	if ( null === $schemas ) {
		$schemas = require __DIR__ . '/livepress-schema.php';
	}
	return $schemas;
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
			'name'          => __( 'Site Pages', 'livepress' ),
			'singular_name' => __( 'Site Page', 'livepress' ),
			'all_items'     => __( 'All Site Pages', 'livepress' ),
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
		/* Deliberately open: the headless frontend has no credential to send.
		   What keeps that safe is the allowlist below, which is why it is the
		   PUBLIC list and not the writable one. */
		'permission_callback' => '__return_true',
		'callback'            => function ( $request ) {
			$key = sanitize_key( $request['key'] );
			if ( ! in_array( $key, livepress_public_option_keys(), true ) ) {
				return new WP_Error( 'not_found', __( 'Unknown global', 'livepress' ), array( 'status' => 404 ) );
			}
			/*
			 * LiteSpeed was caching this route — measured `x-litespeed-cache:
			 * hit` with an Age, still serving deleted values. That makes a
			 * saved brand colour appear to do nothing: the frontend refetches
			 * on its ISR window and gets the previous answer anyway.
			 *
			 * The payload is a few dozen bytes read once per revalidation, so
			 * caching it buys nothing and costs correctness.
			 */
			do_action( 'litespeed_control_set_nocache', 'livepress globals must be fresh' );
			nocache_headers();

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
				return new WP_Error( 'not_found', __( 'Unknown global', 'livepress' ), array( 'status' => 404 ) );
			}
			$data = $request->get_json_params();
			if ( null === $data ) {
				return new WP_Error( 'bad_request', __( 'Body must be JSON', 'livepress' ), array( 'status' => 400 ) );
			}
			update_option( 'livepress_' . $key, $data );

			/*
			 * A global is global: design tokens are custom properties on
			 * :root, so a changed accent repaints every page of the site. A
			 * document save purges the handful of paths it appears on; this
			 * has to purge the lot, or the new colour shows up one page at a
			 * time as each ISR window happens to expire.
			 */
			do_action( 'litespeed_purge_all' );
			livepress_revalidate( livepress_all_frontend_paths() );

			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );
} );

/* ------------------------------------------------------------------ */
/* Admin menu: Site Pages · Menus · Design                              */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page( 'LivePress', 'LivePress', 'edit_pages', 'livepress', function () {}, 'dashicons-visibility', 3 );
	add_submenu_page( 'livepress', __( 'Site Pages', 'livepress' ), __( 'Site Pages', 'livepress' ), 'edit_pages', 'edit.php?post_type=sitepage' );
	add_submenu_page( 'livepress', __( 'Menus', 'livepress' ), __( 'Menus', 'livepress' ), 'edit_theme_options', 'livepress-menus', 'livepress_render_menus' );
	add_submenu_page( 'livepress', __( 'Design', 'livepress' ), __( 'Design', 'livepress' ), 'edit_theme_options', 'livepress-design', 'livepress_render_design' );
	remove_submenu_page( 'livepress', 'livepress' );
	add_submenu_page( '', 'LivePress', 'LivePress', 'edit_pages', LIVEPRESS_PAGE, 'livepress_render_editor' );
} );

/** Editing a Site Page or a live-enabled collection doc opens LivePress. */
add_action( 'admin_init', function () {
	global $pagenow;
	if ( 'post.php' !== $pagenow || empty( $_GET['post'] ) || 'edit' !== ( $_GET['action'] ?? '' ) ) {
		return;
	}
	/* The way back to the classic screen, which is the only place Rank Math
	   renders its analysis. Without this a Site Page can never be opened
	   anywhere else, so the marketer cannot see a score however good the
	   content is. */
	if ( 'off' === ( $_GET['livepress'] ?? '' ) ) {
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
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'No LivePress-enabled document selected.', 'livepress' )
		);
		return;
	}
	$schemas       = livepress_schema();
	$is_collection = 'sitepage' !== $post->post_type;
	$schema        = $is_collection
		? ( $schemas[ 'collection:' . $post->post_type ] ?? null )
		: ( $schemas[ $post->post_name ] ?? null );
	if ( ! $schema ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			/* translators: %s is the page slug with no schema. */
			esc_html( sprintf( __( 'No LivePress schema for "%s".', 'livepress' ), $post->post_name ) )
		);
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

	/*
	 * Who else is in here.
	 *
	 * Conflicts were only ever caught at save: checkConflict() compares the
	 * document's modified time against the one this editor opened with, which
	 * is a real safeguard and the reason nobody has lost work. But it tells
	 * you after the twenty minutes, not before them, and being warned that
	 * somebody is already editing is worth far more at the moment you arrive.
	 *
	 * WordPress has had this the whole time; LivePress simply never used it,
	 * because it redirects away from the classic editor that sets the lock.
	 * So this is core's own lock, refreshed over core's own heartbeat handler
	 * — nothing reimplemented.
	 *
	 * The lock is NOT taken when somebody else holds it. Claiming it would
	 * evict them from a screen they are working on to warn them about the
	 * person who evicted them, which is worse than saying nothing.
	 */
	$lock_holder = function_exists( 'wp_check_post_lock' ) ? wp_check_post_lock( $post->ID ) : false;
	$lock        = '';
	$locked_by   = null;
	if ( $lock_holder ) {
		$who       = get_userdata( $lock_holder );
		$locked_by = $who ? $who->display_name : __( 'Somebody else', 'livepress' );
	} elseif ( function_exists( 'wp_set_post_lock' ) ) {
		$claimed = wp_set_post_lock( $post->ID );
		if ( is_array( $claimed ) ) {
			$lock = implode( ':', $claimed );
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
		/* The document's state when this editor opened. The editor sends it back
		   on save so a second person's work is not silently overwritten. */
		'modified' => $post->post_modified_gmt,
		'seoUrl'   => admin_url( 'post.php?post=' . $post->ID . '&action=edit&livepress=off' ),
		/* A change already parked on this document, so the editor can say so
		   rather than letting somebody schedule a second one over the top —
		   there is one record per document, and the later one would win by
		   accident rather than by intent. */
		'pending'  => livepress_pending_for( $post->ID ),
		/* Set when somebody else already had it open; the editor says so and
		   otherwise stays out of the way. */
		'lockedBy' => $locked_by,
		/* "time:user_id", the shape core's heartbeat handler expects back. */
		'lock'     => $lock,
		'schema'   => $schema,
		'values'   => $values,
	) );
}

/**
 * The global tokens the Design screen may change.
 *
 * **Source of truth is `lib/livepress/design.ts` in the frontend repo.** The
 * fallback hexes here must match its `DESIGN_TOKENS` exactly, because "Reset"
 * writes them — a drifted value would quietly set a colour that is not the
 * brand's. `scripts/check-design-tokens.ts` reads this array and fails if the
 * two disagree, which is cheaper than generating the file.
 *
 * The set is small on purpose: it is the tokens the app actually leans on,
 * counted rather than guessed (gold-500 is used 455 times, gold-400 221,
 * gold-300 100, ink-950 302). `gold-700` is used three times and is not worth
 * a control.
 */
function livepress_design_tokens(): array {
	return array(
		array(
			'key'      => 'gold500',
			'label'    => 'Accent',
			'hint'     => __( 'The brand gold. Buttons, links, rules, and every highlight on the site.', 'livepress' ),
			'fallback' => '#c3a363',
		),
		array(
			'key'      => 'gold400',
			'label'    => 'Accent — light',
			'hint'     => __( 'Hover states and the lighter half of gradients on the accent.', 'livepress' ),
			'fallback' => '#d9be84',
		),
		array(
			'key'      => 'gold300',
			'label'    => 'Accent — lightest',
			'hint'     => __( 'Accent text on a dark panel, where the full gold is too heavy.', 'livepress' ),
			'fallback' => '#e6d3a8',
		),
		array(
			'key'      => 'ink950',
			'label'    => __( 'Page background', 'livepress' ),
			'hint'     => __( 'The near-black the whole site sits on.', 'livepress' ),
			'fallback' => '#05060a',
		),
	);
}

/** Shared shell for the standalone Design fullscreen editor. */
function livepress_render_globals_editor( $mode, $title ) {
	$globals = array();
	foreach ( livepress_option_keys() as $key ) {
		$value = get_option( 'livepress_' . $key, array() );
		$globals[ $key ] = ( 'nav' === $key ) ? array_values( (array) $value ) : (object) ( $value ?: array() );
	}
	livepress_enqueue_editor( array(
		'mode'         => $mode,
		'postId'       => 0,
		'title'        => $title,
		'frontend'     => livepress_frontend(),
		'path'         => '/',
		'backUrl'      => admin_url(),
		'schema'       => array( 'sections' => array() ),
		'values'       => array(),
		'globals'      => $globals,
		'designTokens' => livepress_design_tokens(),
	) );
}

/**
 * Menus is the site-chrome page's Navigation section.
 *
 * This screen used to be its own editor over a `livepress_nav` option, and
 * nothing on the frontend ever read that option: reordering items, renaming
 * them and toggling the eye all reported success and changed nothing. The nav
 * the site actually renders comes from the `nav_items` repeater on the
 * site-chrome document — `app/layout.tsx` builds the header from it — and
 * that one is strictly richer, carrying parent, dropdown sub-line, dropdown
 * heading and footer link so the mega menu works.
 *
 * So the menu item stays, because that is where people look for it, and it
 * now opens the editor that works, focused on that section. Redirecting from
 * `admin_init` rather than the render callback because by render time the
 * headers are already out.
 */
add_action( 'admin_init', function () {
	if ( 'livepress-menus' !== ( $_GET['page'] ?? '' ) ) {
		return;
	}
	$chrome = get_page_by_path( 'site-chrome', OBJECT, 'sitepage' );
	if ( ! $chrome ) {
		return; // fall through to the notice in the render callback
	}
	wp_safe_redirect( admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $chrome->ID . '&focus=nav' ) );
	exit;
} );

function livepress_render_menus() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'The site-chrome document is missing, so there is no navigation to edit. Re-seed it and this screen will open it.', 'livepress' )
	);
}
function livepress_render_design() {
	livepress_render_globals_editor( 'design', 'Design' );
}

/**
 * Version assets by their own modification time.
 *
 * These were enqueued as '1.0.0', hardcoded, while the host serves them with
 * `Cache-Control: max-age=31557600` — a year. So every change to the editor
 * shipped to a URL every browser already held a year-long copy of, and the
 * only person who saw the new code was whoever hard-reloaded. The plugin
 * looked broken and was fine.
 *
 * filemtime means the URL changes whenever the file does, which is the only
 * version number that cannot drift from what is actually on disk.
 */
function livepress_asset_version( string $relative ): string {
	$path = __DIR__ . '/' . ltrim( $relative, '/' );
	$time = file_exists( $path ) ? filemtime( $path ) : 0;
	return $time ? (string) $time : '1.0.0';
}

/** Enqueue the editor app with its boot payload. */
function livepress_enqueue_editor( array $boot ) {
	wp_enqueue_media();
	wp_enqueue_script( 'wp-api-fetch' );
	wp_enqueue_script( 'heartbeat' );
	wp_enqueue_script( 'livepress-editor', plugins_url( 'assets/editor.js', __FILE__ ), array( 'wp-api-fetch', 'wp-i18n' ), livepress_asset_version( 'assets/editor.js' ), true );
	/* Without this the editor's own strings stay untranslatable even after the
	   PHP side is done — and the editor is where nearly all the words are. */
	wp_set_script_translations( 'livepress-editor', 'livepress', plugin_dir_path( __FILE__ ) . 'languages' );
	wp_enqueue_style( 'livepress-editor', plugins_url( 'assets/editor.css', __FILE__ ), array(), livepress_asset_version( 'assets/editor.css' ) );
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

/* ------------------------------------------------------------------ */
/* Instant publish: tell the frontend to rebuild the pages that changed */
/* ------------------------------------------------------------------ */

/**
 * Shared secret for the frontend's revalidate endpoint.
 *
 * Stored as an option rather than a constant so it can be rotated from here
 * without a deploy. Empty means the feature is off, and off is silent: an
 * editor should not see an error about a cache they do not know exists.
 */
function livepress_revalidate_secret(): string {
	return (string) get_option( 'livepress_revalidate_secret', '' );
}

/**
 * Every fixed page path the frontend serves.
 *
 * For a change that is genuinely site-wide — a design token lands on `:root`,
 * so it repaints everything — rather than the handful of paths one document
 * touches. Collection paths carry a `{slug}` placeholder and are skipped:
 * there is no one URL to name, and the revalidate endpoint caps the list at
 * forty anyway.
 */
function livepress_all_frontend_paths(): array {
	$paths = array( '/' );
	foreach ( livepress_schema() as $schema ) {
		$path = (string) ( $schema['frontendPath'] ?? '' );
		if ( '' !== $path && false === strpos( $path, '{' ) ) {
			$paths[] = $path;
		}
	}
	return array_values( array_unique( $paths ) );
}

/**
 * Which frontend paths a saved document affects.
 *
 * A post is not only its own page. It appears on the journal index, in its
 * category and author archives, and in the sitemap — so changing a title and
 * purging only `/blog/<slug>` leaves the old wording on every list that links
 * to it, which looks exactly like the save failing.
 */
function livepress_paths_for( WP_Post $post ): array {
	$paths = array();

	if ( 'post' === $post->post_type ) {
		$paths[] = '/blog/' . $post->post_name;
		$paths[] = '/blog';
		$paths[] = '/sitemap.xml';
		foreach ( wp_get_post_categories( $post->ID ) as $cat_id ) {
			$term = get_term( $cat_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$paths[] = '/blog/category/' . $term->slug;
			}
		}
		$author = get_userdata( (int) $post->post_author );
		if ( $author ) {
			$paths[] = '/blog/author/' . $author->user_nicename;
		}
		return $paths;
	}

	if ( 'sitepage' === $post->post_type ) {
		$schema = livepress_schema();
		$path   = $schema[ $post->post_name ]['frontendPath'] ?? '';
		if ( '' !== $path ) {
			$paths[] = str_replace( '{slug}', $post->post_name, $path );
		}
		/* The nav and footer are on every page, so a change to them is the one
		   case where purging a single path would be wrong. */
		if ( 'site-chrome' === $post->post_name ) {
			$paths = array( '/' );
			foreach ( $schema as $slug => $page ) {
				if ( 'site-chrome' === $slug ) {
					continue;
				}
				$paths[] = str_replace( '{slug}', $slug, $page['frontendPath'] );
			}
		}
		$paths[] = '/sitemap.xml';
	}

	return $paths;
}

/**
 * Ask the frontend to rebuild those paths now.
 *
 * Non-blocking, and deliberately so: the editor should not wait on a network
 * call to another host before its save returns, and a frontend that is down
 * must not be able to make saving fail. The cost of a missed call is the old
 * five-minute wait, which is what used to happen every time anyway.
 */
function livepress_revalidate( array $paths ): void {
	$secret = livepress_revalidate_secret();
	$front  = untrailingslashit( livepress_frontend() );
	$paths  = array_values( array_unique( array_filter( $paths ) ) );

	if ( '' === $secret || '' === $front || ! $paths ) {
		return;
	}

	wp_remote_post(
		$front . '/api/revalidate',
		array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'body'      => wp_json_encode(
				array(
					'secret' => $secret,
					'paths'  => $paths,
				)
			),
		)
	);
}

/**
 * Queue a document for revalidation, flushed once at the end of the request.
 *
 * The editor saves every field as its own meta update, so a page with thirty
 * fields fired thirty HTTP calls for one save. Queuing collapses that to one
 * call per document no matter how many fields moved.
 */
function livepress_queue_revalidate( int $post_id ): void {
	static $hooked = false;
	$GLOBALS['livepress_revalidate_queue'][ $post_id ] = true;

	if ( ! $hooked ) {
		$hooked = true;
		add_action(
			'shutdown',
			function () {
				$paths = array();
				foreach ( array_keys( $GLOBALS['livepress_revalidate_queue'] ?? array() ) as $id ) {
					$post = get_post( (int) $id );
					if ( $post ) {
						$paths = array_merge( $paths, livepress_paths_for( $post ) );
					}
				}
				livepress_revalidate( $paths );
			},
			99
		);
	}
}

/** Fire on any save of content the frontend renders. */
add_action(
	'save_post',
	function ( $post_id, $post, $update ) {
		unset( $update );
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'sitepage' ), true ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status && 'private' !== $post->post_status ) {
			return;
		}
		livepress_queue_revalidate( (int) $post_id );
	},
	10,
	3
);

/** The editor writes meta over REST without touching the post row, so catch that too. */
add_action(
	'updated_post_meta',
	function ( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id, $meta_key );
		$post = get_post( (int) $post_id );
		if ( $post && in_array( $post->post_type, array( 'post', 'sitepage' ), true ) ) {
			livepress_queue_revalidate( (int) $post_id );
		}
	},
	10,
	3
);

/* ------------------------------------------------------------------ */
/* Field history: the undo WordPress does not give post meta            */
/* ------------------------------------------------------------------ */

/**
 * WordPress revisions cover post_content and post_title. They do not cover
 * post meta, and every LivePress field is post meta — so until this existed,
 * overwriting a good headline with a bad one lost the good one permanently.
 * There was no undo, no revision, and nothing in the editor to suggest that.
 *
 * Stored on the document as a hidden meta key so it travels with the page,
 * survives plugin deactivation, and goes wherever a database export goes.
 */
const LIVEPRESS_HISTORY_KEY = '_livepress_history';
const LIVEPRESS_HISTORY_MAX = 60;

/** Longest value worth keeping. A repeater can hold a lot; a diff is not the goal. */
const LIVEPRESS_HISTORY_VALUE_MAX = 20000;

/** Which keys are worth a history entry: schema fields, plus Rank Math's own. */
function livepress_tracked_keys(): array {
	static $keys = null;
	if ( null !== $keys ) {
		return $keys;
	}
	$keys = array( 'section_order' => true );
	foreach ( livepress_schema() as $page ) {
		foreach ( $page['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$keys[ $field['key'] ] = true;
			}
		}
	}
	foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_robots', 'rank_math_canonical_url' ) as $k ) {
		$keys[ $k ] = true;
	}
	return $keys;
}

/**
 * Record the value a field had before it was overwritten.
 *
 * Hooked to `update_post_metadata`, the short-circuit FILTER that runs before
 * the write — the only point at which the old value still exists.
 *
 * Not `update_post_meta`. That name exists too, as an action, and it passes
 * `$meta_id` where the filter passes `$check`. Hooking it looked right and did
 * nothing: the first argument was an integer, never null, so the guard below
 * returned on every single call and no history was ever recorded. It only
 * surfaced by changing a field and looking for the entry.
 */
add_filter(
	'update_post_metadata',
	function ( $check, $object_id, $meta_key, $meta_value ) {
		if ( null !== $check ) {
			return $check; // somebody else is short-circuiting the write
		}
		$keys = livepress_tracked_keys();
		if ( ! isset( $keys[ $meta_key ] ) ) {
			return $check;
		}
		$post = get_post( (int) $object_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'sitepage' ), true ) ) {
			return $check;
		}

		$old = get_post_meta( (int) $object_id, $meta_key, true );
		$old = is_string( $old ) ? $old : wp_json_encode( $old );
		$new = is_string( $meta_value ) ? $meta_value : wp_json_encode( $meta_value );

		/* Unchanged saves are the common case — the editor writes every field
		   whether it moved or not. Logging those would bury the real edits. */
		if ( (string) $old === (string) $new ) {
			return $check;
		}
		if ( '' === (string) $old ) {
			return $check; // nothing was lost, so there is nothing to restore
		}
		if ( strlen( (string) $old ) > LIVEPRESS_HISTORY_VALUE_MAX ) {
			return $check;
		}

		$history   = get_post_meta( (int) $object_id, LIVEPRESS_HISTORY_KEY, true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'key'  => (string) $meta_key,
			'old'  => (string) $old,
			'time' => time(),
			'user' => get_current_user_id(),
		);
		if ( count( $history ) > LIVEPRESS_HISTORY_MAX ) {
			$history = array_slice( $history, -LIVEPRESS_HISTORY_MAX );
		}
		/* Slashed for the same reason as the restore below: the entries hold
		   previous values verbatim, and an unslashed write would strip the
		   backslashes out of every JSON repeater in the record — so the
		   history would faithfully store a corrupted version of what it is
		   meant to give back. */
		update_post_meta( (int) $object_id, LIVEPRESS_HISTORY_KEY, wp_slash( $history ) );

		return $check;
	},
	10,
	4
);

/** History is bookkeeping, not content — it must never reach the frontend. */
add_filter(
	'is_protected_meta',
	function ( $protected, $meta_key ) {
		return LIVEPRESS_HISTORY_KEY === $meta_key ? true : $protected;
	},
	10,
	2
);

/** Put one field back to the value it held before a given change. */
add_action(
	'admin_post_livepress_restore',
	function () {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$index   = isset( $_GET['i'] ) ? (int) $_GET['i'] : -1;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( __( 'You do not have permission to restore this field.', 'livepress' ), 403 );
		}
		check_admin_referer( 'livepress_restore_' . $post_id . '_' . $index );

		$history = get_post_meta( $post_id, LIVEPRESS_HISTORY_KEY, true );
		$history = is_array( $history ) ? $history : array();
		if ( ! isset( $history[ $index ] ) ) {
			wp_die( __( 'That history entry no longer exists.', 'livepress' ), 404 );
		}

		$entry = $history[ $index ];
		/*
		 * wp_slash, and the reason is the bug that made this list necessary.
		 *
		 * `update_post_meta()` runs `wp_unslash()` on whatever it is given.
		 * A repeater is stored as JSON, and JSON writes a newline as the two
		 * characters backslash-n — so an unslashed write strips the backslash
		 * and leaves a literal `n` in the content. Sixty cells across seven
		 * pages were seeded that way and read "See your projectnbefore you
		 * build it" on the live home page until they were repaired.
		 *
		 * Restore would have done exactly the same thing to any repeater it
		 * put back: the button whose whole purpose is recovering a value would
		 * have corrupted it on the way in.
		 */
		update_post_meta( $post_id, $entry['key'], wp_slash( $entry['old'] ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'livepress-history',
					'post'     => $post_id,
					'restored' => rawurlencode( $entry['key'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
);
