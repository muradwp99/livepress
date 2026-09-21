<?php
/**
 * Check that every image a page points at actually resolves.
 *
 * `npm run check:assets` already does this against the rendered site, and it
 * is the gate that matters before a deploy. This is deliberately the other
 * half: it checks the URLs as stored, so a render that has been deleted from
 * the media library, or a URL pasted with a typo, is caught in the admin at
 * the moment it is saved rather than after the next build.
 *
 * It also flags the one trap that script was shaped around — an asset pointing
 * at `realistic3d.co/wp-content/...` rather than `admin.`. That domain resolves
 * today because it is still the old WordPress; the hour it serves the new site,
 * every one of those paths 404s at once.
 *
 * Host detection is done by parsing the URL rather than with a lookbehind
 * regex. `admin.realistic3d.co` contains `realistic3d.co`, so a plain search
 * flags every correctly-migrated asset, and the regex that gets that right is
 * exactly the kind that stops working when a backslash goes missing.
 *
 * Image fields hold two kinds of value, and for a while this understood only
 * one. An absolute `https://admin.realistic3d.co/wp-content/...` is a media
 * library render; a bare `/gallery/villa-moon-b.webp` is a file in the Next
 * app's `public/`. Matching only the first meant nineteen paths across twelve
 * pages were never fetched while the screen reported "56 / 56 checked, 0
 * broken" — a checker that silently skips a class of value is worse than one
 * that says what it skipped. Both are collected now, and a relative path is
 * resolved against the frontend before it is fetched: `admin.` has no
 * `/gallery/`, so resolving one against this install's own host would report
 * every last one of them broken.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_ASSET_CACHE = 'livepress_asset_report';

/** Every asset URL stored on a Site Page, with where it came from. */
function livepress_collect_assets(): array {
	$found = array();

	foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, '_' ) ) {
				continue;
			}
			$value = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			preg_match_all( '#https?://[^\s"\'<>\\\\)]+#', $value, $abs );
			/* Frontend-relative paths. The lookbehind is what stops this from
			   re-matching the tail of an absolute URL already found above:
			   `admin.realistic3d.co/wp-content/x.jpg` would otherwise also be
			   reported as a second, non-existent asset `/wp-content/x.jpg`. */
			preg_match_all( '#(?<![\w:/])/[^\s"\'<>\\\\)]+#', $value, $rel );

			foreach ( array_merge( $abs[0], $rel[0] ) as $url ) {
				$clean = rtrim( $url, '.,;' );
				if ( ! preg_match( '#\.(jpe?g|png|webp|gif|svg|avif|mp4|webm)(\?|$)#i', $clean ) ) {
					continue;
				}
				if ( ! isset( $found[ $clean ] ) ) {
					$found[ $clean ] = array();
				}
				$where = $post->post_name . ' · ' . $key;
				if ( ! in_array( $where, $found[ $clean ], true ) ) {
					$found[ $clean ][] = $where;
				}
			}
		}
	}

	return $found;
}

/** True when this URL points at the domain that becomes the new site. */
function livepress_is_old_host( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) {
		return false;
	}
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	/* Anything on this install's own host is right by definition. The trap is
	   the bare domain without the subdomain. */
	$legacy = livepress_legacy_host();
	if ( '' === $legacy ) {
		return false; // no migration in progress, so nothing to warn about
	}
	return $host !== $home && false !== strpos( $url, '/wp-content/' ) && $legacy === $host;
}

/**
 * Fetch each one and report what does not answer.
 *
 * HEAD, because the body is irrelevant and some of these are 4MB renders. A
 * fresh cache-buster per URL: a reused one is served from the host cache, and
 * that once reported nine already-fixed assets as broken.
 */
function livepress_run_asset_check( int $budget_seconds = 45 ): array {
	$assets  = livepress_collect_assets();
	$started = microtime( true );
	$front   = livepress_frontend();

	$report = array(
		'checked'   => 0,
		'total'     => count( $assets ),
		'broken'    => array(),
		'old_host'  => array(),
		'ran_at'    => time(),
		'truncated' => false,
	);

	foreach ( $assets as $url => $where ) {
		if ( livepress_is_old_host( $url ) ) {
			$report['old_host'][] = array( 'url' => $url, 'where' => $where );
		}

		if ( microtime( true ) - $started > $budget_seconds ) {
			$report['truncated'] = true;
			break;
		}

		/* Leaves an absolute URL alone; turns `/gallery/x.webp` into the
		   frontend URL that serves it. */
		$target = WP_Http::make_absolute_url( $url, $front );

		$res = wp_remote_head(
			add_query_arg( 'lpcb', wp_generate_password( 8, false ), $target ),
			array( 'timeout' => 8, 'redirection' => 3 )
		);
		$report['checked']++;

		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 400 ) {
			$report['broken'][] = array(
				'url'   => $url,
				'code'  => $code ? $code : ( is_wp_error( $res ) ? $res->get_error_message() : 'no response' ),
				'where' => $where,
			);
		}
	}

	set_transient( LIVEPRESS_ASSET_CACHE, $report, HOUR_IN_SECONDS );
	return $report;
}

add_action(
	'admin_post_livepress_check_assets',
	function () {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'livepress_check_assets' );
		livepress_run_asset_check();
		wp_safe_redirect( admin_url( 'admin.php?page=livepress-assets' ) );
		exit;
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Images', 'livepress' ), __( 'Images', 'livepress' ), 'edit_pages', 'livepress-assets', 'livepress_render_assets' );
	},
	22
);

function livepress_render_assets() {
	$report = get_transient( LIVEPRESS_ASSET_CACHE );
	$run    = wp_nonce_url( admin_url( 'admin-post.php?action=livepress_check_assets' ), 'livepress_check_assets' );

	livepress_screen_open(
		__( 'Images', 'livepress' ),
		__( 'Checks that every image a page points at still resolves. The build gate checks the rendered site; this checks the values as stored, so a deleted render or a mistyped URL shows up here rather than after the next deploy.', 'livepress' ),
		sprintf( '<a class="lp-btn lp-btn--primary" href="%s">Run check now</a>', esc_url( $run ) )
	);

	if ( ! is_array( $report ) ) {
		livepress_empty_state(
			__( 'Not run yet', 'livepress' ),
			__( 'Checking fetches every image URL stored on a page, so it takes around twenty seconds. The result is kept for an hour.', 'livepress' ),
			sprintf( '<a class="lp-btn lp-btn--primary" href="%s">Run the first check</a>', esc_url( $run ) )
		);
		livepress_screen_close();
		return;
	}

	$broken = count( $report['broken'] );
	$old    = count( $report['old_host'] );

	livepress_figures(
		array(
			array( 'value' => (int) $report['checked'] . ' / ' . (int) $report['total'], 'label' => 'images checked' ),
			array( 'value' => $broken, 'label' => 'not loading', 'tone' => $broken ? 'danger' : 'quiet' ),
			array( 'value' => $old, 'label' => 'on the old domain', 'tone' => $old ? 'danger' : 'quiet' ),
			array(
				'value' => human_time_diff( (int) $report['ran_at'] ) . ' ago',
				'label' => empty( $report['truncated'] ) ? 'last run' : 'last run — stopped early on time',
				'tone'  => 'quiet',
			),
		)
	);

	if ( ! $broken && ! $old ) {
		echo '<div class="lp-notice lp-notice--ok"><p>Every image resolves, and none point at the old domain.</p></div>';
		livepress_screen_close();
		return;
	}

	foreach (
		array(
			'broken'   => array( 'Not loading', 'The URL is stored on a page but does not answer. Either the render was deleted from the media library or the address was mistyped.' ),
			'old_host' => array( 'Pointing at the previous site', sprintf( 'These resolve today because %s still serves the old WordPress. The hour it serves the new site, every one of them 404s at once.', livepress_legacy_host() ) ),
		) as $key => $section
	) {
		if ( empty( $report[ $key ] ) ) {
			continue;
		}
		printf( '<h2 class="lp-subhead">%s</h2><p class="lp-lede lp-lede--spaced">%s</p>', esc_html( $section[0] ), esc_html( $section[1] ) );
		livepress_table_open(
			array(
				array( 'Status', 'lp-shrink' ),
				array( 'Image', '' ),
				array( 'Used on', '' ),
			)
		);
		foreach ( $report[ $key ] as $row ) {
			printf(
				'<tr><td class="lp-shrink"><span class="lp-pill lp-pill--missing">%s</span></td>'
					. '<td><span class="lp-sub">%s</span></td><td class="lp-muted">%s</td></tr>',
				esc_html( (string) ( $row['code'] ?? 'old host' ) ),
				esc_html( $row['url'] ),
				esc_html( implode( ', ', $row['where'] ) )
			);
		}
		livepress_table_close();
	}

	livepress_screen_close();
}
