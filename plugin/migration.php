<?php
/**
 * Will the old site's URLs survive the cutover?
 *
 * Moving a ranked WordPress site to a headless frontend changes addresses,
 * and every address Google holds that stops answering is a ranking thrown
 * away. The check is simple and nobody does it: take the old sitemap, ask the
 * new site about every URL in it, and look at what comes back.
 *
 * This screen exists because that was done by hand for this site, and it
 * found six classes of URL that would have 404'd the hour the domain changed
 * hands — the seven Rank Math sitemaps, every published image, a service page
 * the rebuild did not carry over, the feed, the paginated archive and a KML.
 * Forty-seven of forty-nine page URLs were already redirecting correctly, so
 * the work was not fixing a broken migration; it was finding the two per cent
 * nobody would have noticed until traffic fell.
 *
 * Following redirects to the end is the whole point. A 301 that lands on a
 * 404 is worse than no redirect at all — it looks fine in a spot check and
 * loses the page anyway — so a URL only counts as safe when the final
 * response is a 200.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_MIGRATION_PAGE  = 'livepress-migration';
const LIVEPRESS_MIGRATION_STATE = 'livepress_migration_state';

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', 'Cutover check', 'Cutover check', 'manage_options', LIVEPRESS_MIGRATION_PAGE, 'livepress_render_migration' );
	},
	25
);

/** Where the old site's sitemap lives. Derived from the legacy host, or set. */
function livepress_legacy_sitemap(): string {
	$explicit = (string) get_option( 'livepress_legacy_sitemap', '' );
	if ( '' !== $explicit ) {
		return $explicit;
	}
	$host = livepress_legacy_host();
	return $host ? 'https://' . $host . '/sitemap_index.xml' : '';
}

/**
 * Every URL a sitemap advertises, following one level of index.
 *
 * Rank Math and WordPress core both publish an *index* — a sitemap of
 * sitemaps — so fetching the advertised URL and reading its `<loc>` elements
 * returns seven more sitemaps rather than any pages. One level of recursion
 * covers every generator in common use; more would risk a loop for no gain.
 */
function livepress_sitemap_urls( string $url, int $depth = 0 ): array {
	$res = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}
	$body = (string) wp_remote_retrieve_body( $res );
	if ( ! preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $body, $m ) ) {
		return array();
	}

	$locs      = array_map( 'html_entity_decode', $m[1] );
	$is_index  = false !== stripos( $body, '<sitemapindex' );
	if ( $is_index && $depth < 1 ) {
		$out = array();
		foreach ( $locs as $child ) {
			$out = array_merge( $out, livepress_sitemap_urls( $child, $depth + 1 ) );
		}
		return $out;
	}

	return $locs;
}

/**
 * What the new site does with one path.
 *
 * Two requests at most: the first without following, so the immediate status
 * and target are visible; the second only when that was a redirect, to learn
 * where the chain actually ends. A URL that answers 200 straight away costs
 * one request.
 */
function livepress_probe_path( string $front, string $path ): array {
	$args  = array( 'timeout' => 12, 'redirection' => 0, 'sslverify' => true );
	$first = wp_remote_head( $front . $path, $args );

	if ( is_wp_error( $first ) ) {
		return array( 'code' => 0, 'final' => 0, 'lands' => '', 'note' => $first->get_error_message() );
	}

	$code     = (int) wp_remote_retrieve_response_code( $first );
	$location = (string) wp_remote_retrieve_header( $first, 'location' );

	if ( $code < 300 || $code >= 400 || '' === $location ) {
		return array( 'code' => $code, 'final' => $code, 'lands' => '', 'note' => '' );
	}

	$target = 0 === strpos( $location, 'http' ) ? $location : $front . $location;
	$second = wp_remote_head( $target, array( 'timeout' => 12, 'redirection' => 5 ) );
	$final  = is_wp_error( $second ) ? 0 : (int) wp_remote_retrieve_response_code( $second );

	return array(
		'code'  => $code,
		'final' => $final,
		'lands' => str_replace( untrailingslashit( $front ), '', $target ),
		'note'  => is_wp_error( $second ) ? $second->get_error_message() : '',
	);
}

/**
 * Work through the list, a few seconds at a time.
 *
 * Sixty-nine URLs at up to two requests each is well past what a page load
 * should hold open, so progress is parked in an option and the screen offers
 * to continue. Same shape as the Images check, for the same reason.
 */
function livepress_run_migration_check( int $budget_seconds = 20 ): array {
	$state = get_option( LIVEPRESS_MIGRATION_STATE, array() );
	$front = untrailingslashit( livepress_frontend() );

	if ( empty( $state['queue'] ) && empty( $state['done'] ) ) {
		$urls  = livepress_sitemap_urls( livepress_legacy_sitemap() );
		$paths = array();
		foreach ( $urls as $u ) {
			$p = (string) wp_parse_url( $u, PHP_URL_PATH );
			$p = '' === $p ? '/' : untrailingslashit( $p );
			$paths[ '' === $p ? '/' : $p ] = true;
		}
		$state = array(
			'queue'  => array_keys( $paths ),
			'done'   => array(),
			'total'  => count( $paths ),
			'ran_at' => time(),
			'source' => livepress_legacy_sitemap(),
		);
	}

	$started = microtime( true );
	while ( ! empty( $state['queue'] ) && microtime( true ) - $started < $budget_seconds ) {
		$path            = array_shift( $state['queue'] );
		$result          = livepress_probe_path( $front, $path );
		$result['path']  = $path;
		$state['done'][] = $result;
	}

	$state['ran_at'] = time();
	update_option( LIVEPRESS_MIGRATION_STATE, $state, false );
	return $state;
}

add_action(
	'admin_post_livepress_migration',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'livepress_migration' );

		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : ( isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '' );

		if ( 'restart' === $what || 'start' === $what ) {
			if ( isset( $_POST['sitemap'] ) ) {
				update_option( 'livepress_legacy_sitemap', esc_url_raw( trim( wp_unslash( $_POST['sitemap'] ) ), array( 'http', 'https' ) ) );
			}
			delete_option( LIVEPRESS_MIGRATION_STATE );
		}
		if ( 'clear' !== $what ) {
			livepress_run_migration_check();
		} else {
			delete_option( LIVEPRESS_MIGRATION_STATE );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => LIVEPRESS_MIGRATION_PAGE ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

/** Safe, redirected-safe, or lost. */
function livepress_migration_verdict( array $row ): string {
	if ( 200 === $row['final'] ) {
		return ( $row['code'] >= 300 && $row['code'] < 400 ) ? 'redirect' : 'ok';
	}
	return 'lost';
}

function livepress_render_migration() {
	$state   = get_option( LIVEPRESS_MIGRATION_STATE, array() );
	$done    = $state['done'] ?? array();
	$queue   = $state['queue'] ?? array();
	$sitemap = livepress_legacy_sitemap();

	$nonce  = wp_nonce_field( 'livepress_migration', '_wpnonce', true, false );
	$action = esc_url( admin_url( 'admin-post.php' ) );

	$button = $queue
		? sprintf(
			'<form method="post" action="%s" class="lp-inline-form">%s<input type="hidden" name="action" value="livepress_migration">'
				. '<button class="lp-btn lp-btn--primary" type="submit" name="what" value="continue">Continue (%d left)</button></form>',
			$action,
			$nonce,
			count( $queue )
		)
		: sprintf(
			'<form method="post" action="%s" class="lp-inline-form">%s<input type="hidden" name="action" value="livepress_migration">'
				. '<button class="lp-btn lp-btn--primary" type="submit" name="what" value="restart">%s</button></form>',
			$action,
			$nonce,
			$done ? 'Run again' : 'Run the check'
		);

	livepress_screen_open(
		'Cutover check',
		'Takes every URL in the old site\'s sitemap and asks this site what it does with each one. A redirect only counts as safe when the chain ends in a 200 — a 301 that lands on a 404 looks fine in a spot check and loses the page anyway.',
		$button
	);

	if ( '' === $sitemap ) {
		livepress_empty_state(
			'No sitemap to compare against',
			'Set the previous site\'s host in Settings and this will look for its sitemap automatically, or enter the sitemap address below.'
		);
	}

	/* The source, always editable — a site might not use the Rank Math name. */
	printf(
		'<form method="post" action="%s" class="lp-form"><div class="lp-setting">%s'
			. '<input type="hidden" name="action" value="livepress_migration">'
			. '<label class="lp-label" for="lp-sitemap">Old sitemap</label>'
			. '<input class="lp-input" type="text" id="lp-sitemap" name="sitemap" value="%s" spellcheck="false">'
			. '<p class="lp-hint">The sitemap the previous site publishes. An index of sitemaps is followed one level, which covers Rank Math, Yoast and WordPress core. Probing runs against %s.</p>'
			. '</div><div class="lp-form-actions"><button class="lp-btn" type="submit" name="what" value="start">Use this sitemap and run</button></div></form>',
		$action,
		$nonce,
		esc_attr( $sitemap ),
		esc_html( untrailingslashit( livepress_frontend() ) )
	);

	if ( ! $done ) {
		livepress_screen_close();
		return;
	}

	$counts = array( 'ok' => 0, 'redirect' => 0, 'lost' => 0 );
	foreach ( $done as $row ) {
		$counts[ livepress_migration_verdict( $row ) ]++;
	}

	livepress_figures(
		array(
			array( 'value' => (int) ( $state['total'] ?? count( $done ) ), 'label' => 'URLs in the old sitemap' ),
			array( 'value' => $counts['ok'], 'label' => 'answer directly', 'tone' => 'quiet' ),
			array( 'value' => $counts['redirect'], 'label' => 'redirect somewhere live', 'tone' => 'quiet' ),
			array( 'value' => $counts['lost'], 'label' => 'would be lost', 'tone' => $counts['lost'] ? 'danger' : 'quiet' ),
		)
	);

	if ( $queue ) {
		printf(
			'<div class="lp-notice"><p>Checked %d of %d so far. Each URL costs up to two requests, so this runs in batches — press Continue to carry on.</p></div>',
			count( $done ),
			(int) ( $state['total'] ?? count( $done ) )
		);
	} elseif ( ! $counts['lost'] ) {
		echo '<div class="lp-notice lp-notice--ok"><p>Every URL in the old sitemap resolves on this site. Nothing in it would 404 at cutover.</p></div>';
	}

	/* Losses first: on a healthy migration this table is mostly noise, and the
	   three rows that matter are the reason anybody opened the screen. */
	usort(
		$done,
		static function ( $a, $b ) {
			$rank = array( 'lost' => 0, 'redirect' => 1, 'ok' => 2 );
			$va   = $rank[ livepress_migration_verdict( $a ) ];
			$vb   = $rank[ livepress_migration_verdict( $b ) ];
			return $va === $vb ? strcmp( $a['path'], $b['path'] ) : $va <=> $vb;
		}
	);

	livepress_table_open(
		array(
			array( '', 'lp-shrink' ),
			array( 'Old URL', '' ),
			array( 'Status', 'lp-num' ),
			array( 'Where it lands', '' ),
		)
	);

	$pill = array(
		'ok'       => array( 'lp-pill--quiet', 'answers' ),
		'redirect' => array( 'lp-pill--quiet', 'redirects' ),
		'lost'     => array( 'lp-pill--missing', 'lost' ),
	);

	foreach ( $done as $row ) {
		$verdict = livepress_migration_verdict( $row );
		$chain   = $row['code'] === $row['final']
			? (string) $row['code']
			: $row['code'] . ' → ' . ( $row['final'] ?: 'no answer' );

		printf(
			'<tr><td class="lp-shrink"><span class="lp-pill %s">%s</span></td>'
				. '<td><span class="lp-sub">%s</span></td>'
				. '<td class="lp-num %s">%s</td>'
				. '<td><span class="lp-sub">%s</span>%s</td></tr>',
			esc_attr( $pill[ $verdict ][0] ),
			esc_html( $pill[ $verdict ][1] ),
			esc_html( $row['path'] ),
			'lost' === $verdict ? 'lp-bad' : 'lp-muted',
			esc_html( $chain ),
			esc_html( $row['lands'] ?: ( 200 === $row['final'] ? 'stays here' : '' ) ),
			'' !== $row['note'] ? '<span class="lp-when-rel">' . esc_html( $row['note'] ) . '</span>' : ''
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
