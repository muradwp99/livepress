<?php
/**
 * LivePress settings, and a doctor that proves the wiring.
 *
 * Every setting this plugin has was a database row with no UI: `livepress_frontend`
 * defaulted to `http://localhost:3000` and `livepress_revalidate_secret` to an
 * empty string, and the only ways to change either were SQL or WP-CLI. That is
 * fine for the one install it was written on and is the single thing stopping
 * anybody else from using it.
 *
 * The second half matters more than the first. A settings form that saves a
 * string tells you nothing — it will happily store a frontend URL that 404s,
 * a secret the frontend does not share, and a preview path that never loads,
 * and the plugin then fails in ways that look like bugs. Every one of those
 * has happened here at least once:
 *
 *   - the editor previewed a host that was not running, and looked broken
 *   - revalidation posted to an endpoint with a mismatched secret, and saves
 *     appeared to work while the frontend stayed stale for five minutes
 *   - the edit bridge was not loaded on the frontend, so keystrokes streamed
 *     into a page that ignored them
 *
 * So the screen checks each link in the chain and says which one is broken.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_SETTINGS = 'livepress-settings';

/** Options this screen owns: name => [label, default]. */
function livepress_settings_fields(): array {
	return array(
		'livepress_frontend'          => array( __( 'Frontend URL', 'livepress' ), 'http://localhost:3000' ),
		'livepress_revalidate_secret' => array( __( 'Revalidate secret', 'livepress' ), '' ),
		'livepress_legacy_host'       => array( __( 'Previous site host', 'livepress' ), '' ),
	);
}

/**
 * The host the site used to live on, for the Images check.
 *
 * `asset-check.php` had `'realistic3d.co' === $host` compiled into it. That
 * test only makes sense during a migration — assets still pointing at the
 * domain that is about to stop being WordPress — and it is meaningless on any
 * other install, so it belongs in configuration rather than in the source.
 */
function livepress_legacy_host(): string {
	return (string) get_option( 'livepress_legacy_host', '' );
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Settings', 'livepress' ), __( 'Settings', 'livepress' ), 'manage_options', LIVEPRESS_SETTINGS, 'livepress_render_settings' );
	},
	30
);

add_action(
	'admin_post_livepress_save_settings',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to do that.', 'livepress' ), 403 );
		}
		check_admin_referer( 'livepress_save_settings' );

		foreach ( livepress_settings_fields() as $option => $meta ) {
			unset( $meta );
			$raw = isset( $_POST[ $option ] ) ? wp_unslash( $_POST[ $option ] ) : '';
			$raw = is_string( $raw ) ? trim( $raw ) : '';

			if ( 'livepress_frontend' === $option ) {
				/* esc_url_raw drops anything that is not a real http(s) URL, so a
				   typo cannot become a request target. Trailing slash removed
				   because every caller concatenates a path onto it. */
				$raw = $raw ? untrailingslashit( esc_url_raw( $raw, array( 'http', 'https' ) ) ) : '';
			} elseif ( 'livepress_legacy_host' === $option ) {
				/* A host, not a URL — accept either and keep the host. */
				$host = wp_parse_url( false === strpos( $raw, '//' ) ? '//' . $raw : $raw, PHP_URL_HOST );
				$raw  = $host ? strtolower( $host ) : '';
			} else {
				$raw = sanitize_text_field( $raw );
			}

			update_option( $option, $raw );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => LIVEPRESS_SETTINGS, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

/** A secret nobody has to invent. */
add_action(
	'admin_post_livepress_generate_secret',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to do that.', 'livepress' ), 403 );
		}
		check_admin_referer( 'livepress_generate_secret' );
		update_option( 'livepress_revalidate_secret', wp_generate_password( 40, false ) );
		wp_safe_redirect( add_query_arg( array( 'page' => LIVEPRESS_SETTINGS, 'generated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

/**
 * Check every link between WordPress and the frontend.
 *
 * Each entry is the smallest request that can distinguish "this works" from
 * "this is why nothing works", and the `fix` line is what to do about it.
 * Deliberately sequential and slow rather than parallel: there are five of
 * them, they run when somebody presses a button, and a clear answer beats a
 * fast one.
 */
function livepress_run_doctor(): array {
	$front  = untrailingslashit( livepress_frontend() );
	$secret = livepress_revalidate_secret();
	$checks = array();

	$check = static function ( string $name, string $state, string $detail, string $fix = '' ): array {
		return compact( 'name', 'state', 'detail', 'fix' );
	};

	/* 1. Is a frontend URL even set, and is it plausible? */
	if ( '' === $front ) {
		$checks[] = $check( __( 'Frontend URL', 'livepress' ), 'fail', __( 'Not set.', 'livepress' ), __( 'Enter the address your Next.js site runs on.', 'livepress' ) );
		return $checks;
	}
	if ( false !== strpos( $front, 'localhost' ) || false !== strpos( $front, '127.0.0.1' ) ) {
		$checks[] = $check(
			__( 'Frontend URL', 'livepress' ),
			'warn',
			$front . __( ' — a local address.', 'livepress' ),
			__( 'This only works while you are editing on the same machine that runs the dev server. Point it at a deployed URL for anyone else.', 'livepress' )
		);
	} else {
		$checks[] = $check( __( 'Frontend URL', 'livepress' ), 'pass', $front );
	}

	/* 2. Does it answer at all? */
	$res  = wp_remote_get( $front . '/?lpdoctor=' . wp_generate_password( 6, false ), array( 'timeout' => 10, 'redirection' => 3 ) );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	if ( $code >= 200 && $code < 400 ) {
		$checks[] = $check( __( 'Frontend responds', 'livepress' ), 'pass', 'HTTP ' . $code . '.' );
	} else {
		$checks[] = $check(
			__( 'Frontend responds', 'livepress' ),
			'fail',
			is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code . '.',
			__( 'The editor previews this URL in an iframe. While it does not answer, the right-hand pane stays blank and the plugin looks broken.', 'livepress' )
		);
		return $checks;
	}

	/* 3. Is the edit bridge on the page? Without it the iframe renders but
	      ignores every keystroke — the failure that looks most like a bug. */
	$body   = (string) wp_remote_retrieve_body( $res );
	$bridge = false !== strpos( $body, 'aux-edit' ) || false !== strpos( $body, 'data-lp' );
	$checks[] = $bridge
		? $check( __( 'Edit bridge', 'livepress' ), 'pass', __( 'The frontend is listening for live edits.', 'livepress' ) )
		: $check(
			__( 'Edit bridge', 'livepress' ),
			'warn',
			__( 'No edit markers found on the home page.', 'livepress' ),
			__( 'The preview will render but will not update as you type. Check that the frontend imports the LivePress bridge and that edit mode is active inside an iframe.', 'livepress' )
		);

	/* 4. Is there a secret, and does the frontend agree with it? A wrong
	      secret is silent: saves succeed, the page just never rebuilds. */
	if ( '' === $secret ) {
		$checks[] = $check(
			__( 'Revalidate secret', 'livepress' ),
			'warn',
			__( 'Not set.', 'livepress' ),
			__( 'Publishing still saves, but the frontend will not rebuild until its own cache expires. Generate one here and put the same value in the frontend environment.', 'livepress' )
		);
		return $checks;
	}

	$probe = wp_remote_post(
		$front . '/api/revalidate',
		array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'secret' => $secret, 'paths' => array( '/' ) ) ),
		)
	);
	$pcode = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );

	if ( 200 === $pcode ) {
		$checks[] = $check( __( 'Instant publish', 'livepress' ), 'pass', __( 'The frontend accepted a revalidation for /.', 'livepress' ) );
	} elseif ( 401 === $pcode || 403 === $pcode ) {
		$checks[] = $check(
			__( 'Instant publish', 'livepress' ),
			'fail',
			__( 'The frontend rejected the secret (HTTP ', 'livepress' ) . $pcode . ').',
			__( 'WordPress and the frontend hold different secrets. Copy the value below into the frontend environment and redeploy.', 'livepress' )
		);
	} elseif ( 404 === $pcode ) {
		$checks[] = $check(
			__( 'Instant publish', 'livepress' ),
			'warn',
			__( 'No /api/revalidate route on the frontend.', 'livepress' ),
			__( 'Saves will still publish, but the page updates on its own schedule rather than in seconds. Add the revalidate route to the frontend.', 'livepress' )
		);
	} else {
		$checks[] = $check(
			__( 'Instant publish', 'livepress' ),
			'fail',
			is_wp_error( $probe ) ? $probe->get_error_message() : 'HTTP ' . $pcode . '.',
			__( 'The revalidate endpoint did not answer as expected.', 'livepress' )
		);
	}

	return $checks;
}

add_action(
	'admin_post_livepress_doctor',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to do that.', 'livepress' ), 403 );
		}
		check_admin_referer( 'livepress_doctor' );
		set_transient( 'livepress_doctor_report', livepress_run_doctor(), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => LIVEPRESS_SETTINGS, 'checked' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

function livepress_render_settings() {
	$report = get_transient( 'livepress_doctor_report' );
	$doctor = wp_nonce_url( admin_url( 'admin-post.php?action=livepress_doctor' ), 'livepress_doctor' );

	livepress_screen_open(
		__( 'Settings', 'livepress' ),
		__( 'Where the frontend lives and how WordPress reaches it. The check below does not read these values back to you — it uses them, and reports which link in the chain is broken.', 'livepress' ),
		sprintf( '<a class="lp-btn lp-btn--primary" href="%s">' . esc_html__( 'Run the check', 'livepress' ) . '</a>', esc_url( $doctor ) )
	);

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="lp-notice lp-notice--ok"><p>' . esc_html__( 'Settings saved. Run the check to confirm the frontend agrees.', 'livepress' ) . '</p></div>';
	}
	if ( isset( $_GET['generated'] ) ) {
		printf(
			'<div class="lp-notice lp-notice--ok"><p>%s</p></div>',
			wp_kses_post( sprintf(
				/* translators: %s is the LIVEPRESS_REVALIDATE_SECRET constant name, in <code>. */
				__( 'New secret generated. Copy it into the frontend environment as %s and redeploy, or instant publish will stop working until you do.', 'livepress' ),
				'<code>LIVEPRESS_REVALIDATE_SECRET</code>'
			) )
		);
	}

	/* ---------- the doctor ---------- */

	if ( is_array( $report ) && $report ) {
		$tone = array( 'pass' => 'lp-pill--quiet', 'warn' => 'lp-pill--warn', 'fail' => 'lp-pill--missing' );
		$word = array( 'pass' => 'working', 'warn' => 'check', 'fail' => 'broken' );

		echo '<h2 class="lp-subhead">' . esc_html__( 'Connection', 'livepress' ) . '</h2>';
		livepress_table_open(
			array(
				array( '', 'lp-shrink' ),
				array( 'Link', 'lp-shrink' ),
				array( __( 'What happened', 'livepress' ), '' ),
			)
		);
		foreach ( $report as $row ) {
			$state = (string) $row['state'];
			printf(
				'<tr><td class="lp-shrink"><span class="lp-pill %s">%s</span></td>'
					. '<td class="lp-shrink"><span class="lp-title">%s</span></td>'
					. '<td>%s%s</td></tr>',
				esc_attr( $tone[ $state ] ?? 'lp-pill--quiet' ),
				esc_html( $word[ $state ] ?? $state ),
				esc_html( (string) $row['name'] ),
				esc_html( (string) $row['detail'] ),
				'' !== $row['fix'] ? '<span class="lp-when-rel">' . esc_html( (string) $row['fix'] ) . '</span>' : ''
			);
		}
		livepress_table_close();
	} elseif ( isset( $_GET['checked'] ) ) {
		echo '<div class="lp-notice"><p>' . esc_html__( 'The check produced no results, which should not happen. Try again.', 'livepress' ) . '</p></div>';
	}

	/* ---------- the form ---------- */

	echo '<h2 class="lp-subhead">' . esc_html__( 'Connection settings', 'livepress' ) . '</h2>';
	printf(
		'<form method="post" action="%s" class="lp-form">',
		esc_url( admin_url( 'admin-post.php' ) )
	);
	wp_nonce_field( 'livepress_save_settings' );
	echo '<input type="hidden" name="action" value="livepress_save_settings">';

	$hints = array(
		'livepress_frontend'          => __( 'The address of the Next.js site this WordPress feeds. The editor previews it in an iframe, so it must be reachable from your browser.', 'livepress' ),
		'livepress_revalidate_secret' => __( 'Shared with the frontend so a save can rebuild the affected pages in seconds instead of waiting for a cache to expire. It must match the frontend environment exactly.', 'livepress' ),
		'livepress_legacy_host'       => __( 'Optional, and only during a migration. The domain the site used to run on — the Images screen flags any asset still pointing there, because those stop resolving the hour that domain changes hands.', 'livepress' ),
	);

	foreach ( livepress_settings_fields() as $option => $meta ) {
		$value = (string) get_option( $option, $meta[1] );
		printf(
			'<div class="lp-setting"><label class="lp-label" for="%1$s">%2$s</label>'
				. '<input class="lp-input" type="text" id="%1$s" name="%1$s" value="%3$s" spellcheck="false" autocomplete="off">'
				. '<p class="lp-hint">%4$s</p></div>',
			esc_attr( $option ),
			esc_html( $meta[0] ),
			esc_attr( $value ),
			esc_html( $hints[ $option ] ?? '' )
		);
	}

	printf(
		'<div class="lp-form-actions"><button type="submit" class="lp-btn lp-btn--primary">' . esc_html__( 'Save settings', 'livepress' ) . '</button>'
			. '<a class="lp-btn" href="%s">' . esc_html__( 'Generate a new secret', 'livepress' ) . '</a></div>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=livepress_generate_secret' ), 'livepress_generate_secret' ) )
	);
	echo '</form>';

	livepress_screen_close();
}
