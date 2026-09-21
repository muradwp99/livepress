<?php
/**
 * The 404s a sitemap cannot tell you about.
 *
 * The Cutover check answers "does every URL the old sitemap advertises still
 * work". That is most of the risk and not all of it: a sitemap is what a site
 * *published*, and Google indexes plenty it never listed — a URL somebody
 * linked to years ago, a page that fell out of the sitemap before the crawl,
 * an address printed on a business card. Those cannot be enumerated in
 * advance. They can only be observed when somebody asks for one.
 *
 * So the frontend reports its own misses and they are collected here, ranked
 * by how often each is asked for, with the referrer that sent them. A path
 * with forty hits and a referrer is a broken link somebody else has published;
 * a path with one hit and none is usually a typo. The first is worth a
 * redirect, the second is not, and the counts are what tell them apart.
 *
 * Creating the redirect uses Rank Math's own `DB::add()` rather than writing
 * to its table directly. Its redirections already drive this site — the bridge
 * publishes them and next.config.mjs bakes them into the build — so a
 * redirect made here travels the same road as one made by hand, and there is
 * no second list to keep in step.
 *
 * ── On accepting writes from anonymous visitors ──
 *
 * This endpoint has to be public: the people hitting 404s are not logged in.
 * That makes it the one place in this plugin where a stranger can cause a
 * write, so it is bounded rather than trusted — a strict path shape, a
 * per-address rate limit, a hard cap on distinct paths, and referrers reduced
 * to a host. Worst case is a full table of junk paths, which is visible and
 * clearable; it cannot grow without limit and it cannot store markup.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_404_PAGE  = 'livepress-broken';
const LIVEPRESS_404_LOG   = 'livepress_404_log';
const LIVEPRESS_404_MAX   = 200;
const LIVEPRESS_404_BURST = 20;

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Broken links', 'livepress' ), __( 'Broken links', 'livepress' ), 'manage_options', LIVEPRESS_404_PAGE, 'livepress_render_broken' );
	},
	26
);

/** A site path, or '' if it is not one. Deliberately narrow. */
function livepress_clean_404_path( $raw ): string {
	if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 200 ) {
		return '';
	}
	$raw = trim( $raw );
	/* Must be a rooted path. No scheme, no host, no protocol-relative form —
	   the frontend sends `location.pathname`, and anything else is either a
	   mistake or somebody probing. */
	if ( 0 !== strpos( $raw, '/' ) || 0 === strpos( $raw, '//' ) ) {
		return '';
	}
	if ( ! preg_match( '#^/[A-Za-z0-9/_\-.%~]*$#', $raw ) ) {
		return '';
	}
	return untrailingslashit( $raw ) ?: '/';
}

/** Just the host of a referrer, and only if it parses. */
function livepress_clean_referrer( $raw ): string {
	if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 300 ) {
		return '';
	}
	$host = wp_parse_url( $raw, PHP_URL_HOST );
	return is_string( $host ) && strlen( $host ) <= 100 && preg_match( '#^[A-Za-z0-9.\-]+$#', $host )
		? strtolower( $host )
		: '';
}

/** Record one miss. Returns false when it was rejected or rate limited. */
function livepress_record_404( string $path, string $referrer_host ): bool {
	$log = get_option( LIVEPRESS_404_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	if ( isset( $log[ $path ] ) ) {
		$log[ $path ]['hits'] = (int) $log[ $path ]['hits'] + 1;
		$log[ $path ]['last'] = time();
	} else {
		/* Full: keep counting what is already known rather than evicting, so a
		   flood of junk cannot push out the real finding that prompted it. */
		if ( count( $log ) >= LIVEPRESS_404_MAX ) {
			return false;
		}
		$log[ $path ] = array( 'hits' => 1, 'first' => time(), 'last' => time(), 'refs' => array() );
	}

	if ( '' !== $referrer_host && ! in_array( $referrer_host, $log[ $path ]['refs'], true ) && count( $log[ $path ]['refs'] ) < 3 ) {
		$log[ $path ]['refs'][] = $referrer_host;
	}

	update_option( LIVEPRESS_404_LOG, $log, false );
	return true;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'livepress/v1',
			'/404',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // visitors are not logged in
				'callback'            => function ( $request ) {
					$body = $request->get_json_params();
					$path = livepress_clean_404_path( $body['path'] ?? '' );
					if ( '' === $path ) {
						return rest_ensure_response( array( 'ok' => false ) );
					}

					/* One bucket per address per hour. A crawler walking a
					   thousand dead URLs is a real pattern and it should not be
					   able to fill the table in one pass. */
					$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
					$key = 'lp404_' . md5( $ip );
					$n   = (int) get_transient( $key );
					if ( $n >= LIVEPRESS_404_BURST ) {
						return rest_ensure_response( array( 'ok' => false, 'throttled' => true ) );
					}
					set_transient( $key, $n + 1, HOUR_IN_SECONDS );

					livepress_record_404( $path, livepress_clean_referrer( $body['referrer'] ?? '' ) );
					return rest_ensure_response( array( 'ok' => true ) );
				},
			)
		);
	}
);

/** Hand a redirect to Rank Math, the way its own screen would. */
function livepress_create_redirect( string $from, string $to ): bool {
	if ( ! class_exists( 'RankMath\Redirections\DB' ) ) {
		return false;
	}
	$pattern = ltrim( $from, '/' );
	$id      = \RankMath\Redirections\DB::add(
		array(
			'sources'     => array(
				array( 'pattern' => $pattern, 'comparison' => 'exact', 'ignore' => '' ),
			),
			'url_to'      => $to,
			'header_code' => '301',
			'status'      => 'active',
		)
	);
	/* No cache purge. Rank Math's redirection cache maps a URL to the
	   redirection that matched it, and a path that has been 404ing has no
	   entry to invalidate — `Cache::purge()` takes ids and exists for editing
	   a redirection that is already cached, not for adding a new one. Calling
	   it with no argument threw ArgumentCountError *after* the row was
	   written, which reported failure for a redirect that had been created. */
	return (bool) $id;
}

add_action(
	'admin_post_livepress_broken',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to do that.', 'livepress' ), 403 );
		}
		check_admin_referer( 'livepress_broken' );

		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		$path = livepress_clean_404_path( wp_unslash( $_POST['path'] ?? '' ) );
		$args = array( 'page' => LIVEPRESS_404_PAGE );
		$log  = get_option( LIVEPRESS_404_LOG, array() );
		$log  = is_array( $log ) ? $log : array();

		if ( 'clear' === $what ) {
			delete_option( LIVEPRESS_404_LOG );
			$args['cleared'] = 1;
		} elseif ( 'ignore' === $what && '' !== $path ) {
			unset( $log[ $path ] );
			update_option( LIVEPRESS_404_LOG, $log, false );
			$args['ignored'] = 1;
		} elseif ( 'redirect' === $what && '' !== $path ) {
			$to = trim( (string) wp_unslash( $_POST['to'] ?? '' ) );
			/* A site path or a full URL, nothing else — this becomes a live
			   redirect target for every visitor. */
			$to = 0 === strpos( $to, '/' ) ? livepress_clean_404_path( $to ) : esc_url_raw( $to, array( 'http', 'https' ) );
			if ( '' === $to ) {
				$args['bad_target'] = 1;
			} elseif ( livepress_create_redirect( $path, $to ) ) {
				unset( $log[ $path ] );
				update_option( LIVEPRESS_404_LOG, $log, false );
				$args['redirected'] = 1;
			} else {
				$args['failed'] = 1;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
);

function livepress_render_broken() {
	$log = get_option( LIVEPRESS_404_LOG, array() );
	$log = is_array( $log ) ? $log : array();
	uasort( $log, static fn( $a, $b ) => ( (int) $b['hits'] ) <=> ( (int) $a['hits'] ) );

	$nonce  = wp_nonce_field( 'livepress_broken', '_wpnonce', true, false );
	$action = esc_url( admin_url( 'admin-post.php' ) );

	livepress_screen_open(
		__( 'Broken links', 'livepress' ),
		__( 'Addresses visitors asked for and this site does not have. The Cutover check covers everything the old sitemap advertised; this covers what it did not — a link somebody else published, a URL indexed years ago, an address on a business card. Ranked by how often each is asked for, because a path with forty hits and a referrer is a broken link worth fixing and a path with one hit is usually a typo.', 'livepress' ),
		$log
			? sprintf(
				'<form method="post" action="%s" class="lp-inline-form">%s<input type="hidden" name="action" value="livepress_broken">'
					. '<button class="lp-btn lp-btn--danger" type="submit" name="what" value="clear" '
					. 'onclick="return confirm(&#039;' . esc_attr( esc_js( __( 'Clear the whole list?', 'livepress' ) ) ) . '&#039;)">' . esc_html__( 'Clear list', 'livepress' ) . '</button></form>',
				$action,
				$nonce
			)
			: ''
	);

	foreach ( array(
		/* Not "live now". Rank Math's redirections take effect on WordPress
		   immediately, but visitors are on the headless frontend, which bakes
		   them in at build time via next.config.mjs — so this is pending until
		   the next deploy, and saying otherwise would send somebody to check a
		   URL that is still 404ing and conclude the button is broken. */
		'redirected' => array( 'ok', __( 'Redirect saved in Rank Math. It reaches visitors at the next frontend build — until then the address still 404s.', 'livepress' ) ),
		'ignored'    => array( 'ok', __( 'Removed from the list. It will come back if somebody asks for it again.', 'livepress' ) ),
		'cleared'    => array( 'ok', __( 'List cleared.', 'livepress' ) ),
		'bad_target' => array( '', __( 'That redirect target was not a site path or a full URL, so nothing was created.', 'livepress' ) ),
		'failed'     => array( '', __( 'Rank Math would not accept the redirect. Check that its Redirections module is enabled.', 'livepress' ) ),
	) as $param => $meta ) {
		if ( isset( $_GET[ $param ] ) ) {
			printf(
				'<div class="lp-notice%s"><p>%s</p></div>',
				'ok' === $meta[0] ? ' lp-notice--ok' : '',
				esc_html( $meta[1] )
			);
		}
	}

	$hits = array_sum( array_map( static fn( $r ) => (int) $r['hits'], $log ) );
	$refd = count( array_filter( $log, static fn( $r ) => ! empty( $r['refs'] ) ) );

	livepress_figures(
		array(
			array( 'value' => count( $log ), 'label' => __( 'addresses missed', 'livepress' ), 'tone' => $log ? 'warn' : 'quiet' ),
			array( 'value' => $hits, 'label' => __( 'requests for them', 'livepress' ) ),
			array( 'value' => $refd, 'label' => __( 'linked from somewhere', 'livepress' ), 'tone' => $refd ? 'danger' : 'quiet' ),
			array( 'value' => count( $log ) . ' / ' . LIVEPRESS_404_MAX, 'label' => __( 'list capacity', 'livepress' ), 'tone' => 'quiet' ),
		)
	);

	if ( ! $log ) {
		livepress_empty_state(
			__( 'Nothing has 404d', 'livepress' ),
			__( 'Either nobody has hit a missing page, or the frontend is not reporting them yet. Reporting needs the LivePress 404 reporter in the frontend\'s not-found route.', 'livepress' )
		);
		livepress_screen_close();
		return;
	}

	livepress_table_open(
		array(
			array( 'Requests', 'lp-num' ),
			array( __( 'Address asked for', 'livepress' ), '' ),
			array( __( 'Linked from', 'livepress' ), 'lp-shrink' ),
			array( __( 'Last seen', 'livepress' ), 'lp-shrink' ),
			array( __( 'Redirect it to', 'livepress' ), 'lp-shrink' ),
		)
	);

	foreach ( $log as $path => $row ) {
		printf(
			'<tr>'
				. '<td class="lp-num"><strong>%d</strong></td>'
				. '<td><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink %s">%s</td>'
				. '<td class="lp-shrink lp-muted">%s</td>'
				. '<td class="lp-shrink">'
					. '<form method="post" action="%s" class="lp-inline-form">%s'
						. '<input type="hidden" name="action" value="livepress_broken">'
						. '<input type="hidden" name="path" value="%s">'
						. '<input class="lp-input lp-input--sm" type="text" name="to" placeholder="/where-it-should-go" required>'
						. '<button class="lp-btn lp-btn--sm" type="submit" name="what" value="redirect">' . esc_html__( 'Redirect', 'livepress' ) . '</button>'
						. '<button class="lp-btn lp-btn--sm" type="submit" name="what" value="ignore" title="Remove from this list without creating a redirect">' . esc_html__( 'Ignore', 'livepress' ) . '</button>'
					. '</form>'
				. '</td>'
			. '</tr>',
			(int) $row['hits'],
			esc_html( $path ),
			empty( $row['refs'] ) ? 'lp-muted' : 'lp-bad',
			esc_html( empty( $row['refs'] ) ? 'nowhere' : implode( ', ', $row['refs'] ) ),
			esc_html( human_time_diff( (int) $row['last'] ) . ' ago' ),
			$action,
			$nonce,
			esc_attr( $path )
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
