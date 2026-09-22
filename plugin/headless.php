<?php
/**
 * This host is a backend, not a website.
 *
 * WordPress here is headless. The frontend renders every page, reading this
 * host over REST and serving its uploads — but WordPress still has a theme and
 * still answers every public URL, so this domain was quietly serving a second,
 * complete copy of the marketing site: 200 OK, the same titles, a
 * `<meta name="robots" content="follow, index">` on every page, and a
 * robots.txt advertising a sitemap that listed every blog post at a backend
 * URL. Two hosts, one set of content, both inviting search engines in.
 *
 * So a public front-end request moves to the real site, path and query intact.
 * No mapping table is needed here: the frontend already redirects the legacy
 * WordPress paths — `/about-us/` to `/about`, `/virtual-tour-real-estate/` to
 * `/blog/virtual-tour-real-estate` — so handing it the path it was asked for
 * is enough, and stays right when those mappings change.
 *
 * WHAT THIS MUST NOT TOUCH, and does not:
 *
 * - `/wp-json/**` — the frontend's entire data source: every page's copy, the
 *   blog, the enquiry endpoint that the contact form posts to, and the preview
 *   tokens. REST dispatch answers and exits during `parse_request`, which is
 *   long before `template_redirect`.
 * - `/wp-content/**` — every image on the site is served from here. Static
 *   files; Apache answers them and PHP never runs.
 * - `/wp-admin/**`, `/wp-login.php`, `/wp-cron.php`, `admin-ajax.php` — none of
 *   them reach the template loader.
 *
 * That is the reason this hangs off `template_redirect` instead of a rule in
 * .htaccess. The hook only fires where a public page would have been rendered,
 * so those exclusions are structural — they hold because of where the hook
 * sits, not because somebody maintained a regex correctly. A blanket rewrite
 * rule with a hand-written exclusion list would take the whole site down the
 * first time one was missed.
 *
 * Logged-in users are left alone, so previewing an unpublished post and Rank
 * Math's own fetches of a permalink still work. It also means the studio will
 * not see this happen while signed in — open the domain in a private window to
 * watch it work.
 *
 * @package LivePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where to send public traffic, or an empty string when there is nowhere safe.
 *
 * This reads the same option the editor previews, so the redirect and the
 * editor cannot disagree about where the site lives. It refuses anything that
 * is not an absolute http(s) URL on a different host, because the option
 * defaults to `http://localhost:3000` and sending the public there — with a
 * permanent redirect their browser will cache — is far worse than serving a
 * duplicate page.
 *
 * @return string Origin with no trailing slash, or ''.
 */
function livepress_headless_origin(): string {
	$parts = wp_parse_url( livepress_frontend() );

	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
		return '';
	}

	$host = strtolower( $parts['host'] );

	/* A loopback address is the unconfigured default, not a destination. */
	if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
		return '';
	}

	/* Redirecting this host to itself is an infinite loop. */
	if ( $host === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		return '';
	}

	return $parts['scheme'] . '://' . $host
		. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
}

/**
 * Move a public page view to the frontend, permanently.
 *
 * Priority 0: before anything else can render or emit output, since a redirect
 * after headers are sent is not a redirect at all.
 */
function livepress_headless_redirect(): void {
	/* Structurally unreachable from this hook, all four of them. Stated anyway,
	   because the cost of being wrong is the whole site and the cost of the
	   guard is four function calls. */
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	/* robots.txt has to keep answering. A crawler that cannot read it cannot be
	   told anything, and one that cannot fetch these pages never sees the 301
	   that takes them out of the index. */
	if ( is_robots() || is_favicon() ) {
		return;
	}

	/* Previews, and anyone with a reason to look at the backend directly. */
	if ( is_user_logged_in() ) {
		return;
	}

	/** Off switch that is not "edit the plugin" or "turn off the editor". */
	if ( ! apply_filters( 'livepress_headless_redirect', true ) ) {
		return;
	}

	$origin = livepress_headless_origin();
	if ( '' === $origin ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
		: '/';

	/* This ends up in a Location header. Anything outside printable ASCII has
	   no business there — a percent-encoded path is already ASCII, and a raw
	   newline would be a response-splitting bug. */
	$uri = (string) preg_replace( '/[^\x21-\x7E]/', '', $uri );
	if ( '' === $uri || '/' !== $uri[0] ) {
		$uri = '/' . ltrim( $uri, '/' );
	}

	wp_redirect( $origin . $uri, 301 );
	exit;
}
add_action( 'template_redirect', 'livepress_headless_redirect', 0 );
