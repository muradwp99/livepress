<?php
/**
 * LivePress admin screens: Content health and Field history — and the shared
 * chrome every LivePress screen is built from.
 *
 * Separate from the plugin bootstrap because these are the only parts that
 * render HTML, and mixing a few hundred lines of markup into the file that
 * registers hooks made both harder to read.
 *
 * The chrome lives here rather than in a sixth file because it is four small
 * functions, and Activity, Images and Scheduled were each inventing their own
 * header, table and empty state — which is how five screens in one plugin
 * ended up with five ideas of what a table looks like and inline hex colours
 * in twelve printf() calls.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Content health', 'livepress' ), __( 'Content health', 'livepress' ), 'edit_pages', 'livepress-health', 'livepress_render_health' );
		add_submenu_page( 'livepress', __( 'Field history', 'livepress' ), __( 'Field history', 'livepress' ), 'edit_pages', 'livepress-history', 'livepress_render_history' );
	},
	20
);

/* ------------------------------------------------------------------ */
/* Shared chrome                                                        */
/* ------------------------------------------------------------------ */

/**
 * The screens that get the LivePress stylesheet.
 *
 * Adding a screen and forgetting to add it here is the one mistake this list
 * invites, and the symptom is not an error — it is a page that renders with
 * every class name in place and none of the styles, which reads as "the
 * plugin is broken" rather than as "one array is short". It has now happened
 * twice: once for the whole editor, and once for Settings.
 */
function livepress_screen_slugs(): array {
	return array(
		'livepress-health',
		'livepress-history',
		'livepress-activity',
		'livepress-assets',
		'livepress-scheduled',
		LIVEPRESS_SETTINGS,
		LIVEPRESS_SCHEMA_PAGE,
		LIVEPRESS_MIGRATION_PAGE,
		LIVEPRESS_404_PAGE,
		LIVEPRESS_FORMS_PAGE,
	);
}

/*
 * Unlike the editor, these run inside wp-admin with its sidebar and admin bar
 * showing, so the stylesheet is loaded the ordinary way and only on the five
 * screens that use it. Versioned by filemtime for the same reason the editor
 * is: the host serves assets with a year-long max-age.
 */
add_action(
	'admin_enqueue_scripts',
	function () {
		if ( ! in_array( $_GET['page'] ?? '', livepress_screen_slugs(), true ) ) {
			return;
		}
		wp_enqueue_style(
			'livepress-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			livepress_asset_version( 'assets/admin.css' )
		);
	}
);

/**
 * Open a screen: title, one line saying what it is for, and its actions.
 *
 * `$actions` is already-escaped markup because every caller passes a link
 * built from `wp_nonce_url`, which escaping again would break.
 */
function livepress_screen_open( string $title, string $lede = '', string $actions = '' ): void {
	echo '<div class="wrap livepress-screen"><div class="lp-head"><div>';
	printf( '<h1>%s</h1>', esc_html( $title ) );
	if ( '' !== $lede ) {
		printf( '<p class="lp-lede">%s</p>', esc_html( $lede ) );
	}
	echo '</div>';
	if ( '' !== $actions ) {
		printf( '<div class="lp-head-actions">%s</div>', $actions );
	}
	echo '</div>';

	/*
	 * WordPress relocates every admin notice to just after the first <h1> it
	 * finds inside .wrap — at any depth, so the title being nested in a header
	 * element does not protect it. Site Kit and Rank Math both notice on these
	 * screens, and both landed between the title and its own description,
	 * splitting the header in half.
	 *
	 * `.wp-header-end` is the marker core looks for first: notices go here
	 * instead, which is below the finished header and above the content.
	 */
	echo '<hr class="wp-header-end">';
}

function livepress_screen_close(): void {
	echo '</div>';
}

/**
 * The summary figures above a table.
 *
 * Each row is `value`, `label`, and an optional `tone`. A count that ought to
 * be zero should pass `'tone' => 0 === $n ? 'quiet' : 'danger'`: the point of
 * the strip is that a healthy site looks calm and a problem is the only thing
 * carrying colour.
 */
function livepress_figures( array $figures ): void {
	echo '<div class="lp-figures">';
	foreach ( $figures as $f ) {
		printf(
			'<div class="lp-figure%s"><span class="lp-figure-value">%s</span><span class="lp-figure-label">%s</span></div>',
			empty( $f['tone'] ) ? '' : ' is-' . esc_attr( $f['tone'] ),
			esc_html( (string) $f['value'] ),
			esc_html( (string) $f['label'] )
		);
	}
	echo '</div>';
}

/** Nothing to show, and what to do about it. */
function livepress_empty_state( string $title, string $body, string $action = '' ): void {
	printf(
		'<div class="lp-empty"><h2>%s</h2><p>%s</p>%s</div>',
		esc_html( $title ),
		esc_html( $body ),
		$action
	);
}

/**
 * Open a table. Each column is `array( label, class )`.
 *
 * A list of pairs rather than label => class, because more than one column
 * here has an empty label — the action column on four of the five screens —
 * and as array keys those collapse into one.
 */
function livepress_table_open( array $columns ): void {
	echo '<div class="lp-tablewrap"><table class="lp-table"><thead><tr>';
	foreach ( $columns as $col ) {
		$class = (string) ( $col[1] ?? '' );
		printf(
			'<th%s>%s</th>',
			'' === $class ? '' : ' class="' . esc_attr( $class ) . '"',
			esc_html( (string) ( $col[0] ?? '' ) )
		);
	}
	echo '</tr></thead><tbody>';
}

function livepress_table_close(): void {
	echo '</tbody></table></div>';
}

/** Human label for a field key, taken from the schema that defined it. */
function livepress_field_label( string $key ): string {
	static $map = null;
	if ( null === $map ) {
		$map = array();
		foreach ( livepress_schema() as $page ) {
			foreach ( $page['sections'] as $section ) {
				foreach ( $section['fields'] as $field ) {
					$map[ $field['key'] ] = $field['label'] ?? $field['key'];
				}
			}
		}
	}
	return $map[ $key ] ?? $key;
}

/**
 * What is filled in, and what is not, across every managed page.
 *
 * This answers the question that is impossible to answer by clicking through
 * twenty-four editors: where is the work unfinished?
 *
 * Two things it deliberately does NOT count, because counting them made the
 * first version cry wolf:
 *
 *   - a field with no saved value but a non-empty default is not empty. The
 *     page renders the built-in copy, which is usually the right copy. Before
 *     this distinction, 23 of 24 pages looked broken when none were.
 *   - a repeater row missing alt text only counts when that repeater actually
 *     HAS an alt column. Several carry an image and no alt field at all, and
 *     reporting those as defects asks an editor to fill a box that does not
 *     exist. That alone was most of the first count of 80.
 */
function livepress_health_rows(): array {
	$rows = array();

	foreach ( livepress_schema() as $slug => $page ) {
		$post = get_page_by_path( $slug, OBJECT, 'sitepage' );
		if ( ! $post ) {
			continue;
		}

		$total   = 0;
		$empty   = 0;
		$default = 0;
		$no_alt  = 0;

		foreach ( $page['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$total++;
				$value    = get_post_meta( $post->ID, $field['key'], true );
				$fallback = (string) ( $field['default'] ?? '' );

				if ( 'repeater' === ( $field['kind'] ?? '' ) ) {
					$raw        = is_string( $value ) && '' !== $value ? $value : $fallback;
					$field_rows = json_decode( $raw ?: '[]', true );
					if ( ! is_array( $field_rows ) || ! $field_rows ) {
						if ( '' === trim( $fallback ) || '[]' === trim( $fallback ) ) {
							$empty++;
						} else {
							$default++;
						}
						continue;
					}
					if ( '' === trim( (string) $value ) ) {
						$default++;
					}

					/* Only a repeater that offers an alt column can be missing one. */
					$cols    = $field['subs'] ?? $field['fields'] ?? array();
					$has_alt_column = false;
					foreach ( (array) $cols as $c ) {
						if ( is_array( $c ) && ( $c['key'] ?? '' ) === 'alt' ) {
							$has_alt_column = true;
						}
					}
					if ( $has_alt_column ) {
						foreach ( $field_rows as $r ) {
							if ( ! is_array( $r ) ) {
								continue;
							}
							$has_image = ! empty( $r['src'] ) || ! empty( $r['image'] );
							if ( $has_image && empty( $r['alt'] ) ) {
								$no_alt++;
							}
						}
					}
					continue;
				}

				if ( '' !== trim( (string) $value ) ) {
					continue;
				}
				if ( '' !== trim( $fallback ) ) {
					$default++;
					continue;
				}
				$empty++;
			}
		}

		/* Word count comes from post_content, which page-text.php keeps in step
		   with the fields. Rank Math measures the same text on the classic
		   screen, one page at a time; the useful thing here is seeing all
		   twenty-four at once and spotting the thin one. */
		$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );

		$rows[] = array(
			'slug'     => $slug,
			'id'       => $post->ID,
			'title'    => $page['title'] ?? $slug,
			'path'     => $page['frontendPath'] ?? '',
			'total'    => $total,
			'empty'    => $empty,
			'default'  => $default,
			'no_alt'   => $no_alt,
			'seo'      => '' !== trim( (string) get_post_meta( $post->ID, 'rank_math_title', true ) ),
			'desc'     => '' !== trim( (string) get_post_meta( $post->ID, 'rank_math_description', true ) ),
			/* The one Rank Math scores against. Titles and descriptions were
			   carried across in the migration and every page has them; the
			   focus keyword is a judgement nobody has made yet, so a page can
			   look fully set up here and still score 7/100 over there. */
			'keyword'  => '' !== trim( (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ) ),
			'words'    => $words,
			'modified' => $post->post_modified,
		);
	}

	return $rows;
}

function livepress_render_health() {
	$rows      = livepress_health_rows();
	$need_seo  = 0;
	$need_kw   = 0;
	$need_fill = 0;
	$need_alt  = 0;
	foreach ( $rows as $r ) {
		if ( ! $r['seo'] || ! $r['desc'] ) {
			$need_seo++;
		}
		if ( ! $r['keyword'] ) {
			$need_kw++;
		}
		if ( $r['empty'] > 0 ) {
			$need_fill++;
		}
		$need_alt += $r['no_alt'];
	}

	$sync = wp_nonce_url( admin_url( 'admin-post.php?action=livepress_sync_text' ), 'livepress_sync_text' );

	livepress_screen_open(
		__( 'Content health', 'livepress' ),
		__( 'Where the work is unfinished, across every managed page at once. A field with no saved value but a built-in default is not counted as empty — the page renders that copy, and counting it made the first version report every page as broken.', 'livepress' ),
		sprintf( '<a class="lp-btn" href="%s">' . esc_html__( 'Rebuild page text', 'livepress' ) . '</a>', esc_url( $sync ) )
	);

	if ( isset( $_GET['synced'] ) ) {
		printf(
			'<div class="lp-notice lp-notice--ok"><p>%s</p></div>',
			/* translators: %d is how many pages were rebuilt. */
			esc_html( sprintf( __( 'Rebuilt the analysable text on %d pages. Rank Math scores them against this.', 'livepress' ), (int) $_GET['synced'] ) )
		);
	}

	livepress_figures(
		array(
			array( 'value' => count( $rows ), 'label' => __( 'pages managed', 'livepress' ) ),
			array( 'value' => $need_seo, 'label' => __( 'missing SEO', 'livepress' ), 'tone' => $need_seo ? 'danger' : 'quiet' ),
			array( 'value' => $need_kw, 'label' => __( 'no focus keyword', 'livepress' ), 'tone' => $need_kw ? 'warn' : 'quiet' ),
			array( 'value' => $need_fill, 'label' => __( 'with empty fields', 'livepress' ), 'tone' => $need_fill ? 'warn' : 'quiet' ),
			array( 'value' => $need_alt, 'label' => __( 'images without alt text', 'livepress' ), 'tone' => $need_alt ? 'danger' : 'quiet' ),
		)
	);

	if ( ! $rows ) {
		livepress_empty_state(
			__( 'No managed pages yet', 'livepress' ),
			__( 'Every page defined in the LivePress schema will appear here once it exists in WordPress.', 'livepress' )
		);
		livepress_screen_close();
		return;
	}

	livepress_table_open(
		array(
			array( 'Page', '' ),
			array( __( 'SEO title', 'livepress' ), 'lp-shrink' ),
			array( 'Description', 'lp-shrink' ),
			array( __( 'Focus keyword', 'livepress' ), 'lp-shrink' ),
			array( __( 'Fields filled', 'livepress' ), 'lp-num' ),
			array( __( 'Built-in default', 'livepress' ), 'lp-num' ),
			array( 'Words', 'lp-num' ),
			array( __( 'No alt text', 'livepress' ), 'lp-num' ),
			array( __( 'Last edited', 'livepress' ), 'lp-shrink' ),
			array( '', 'lp-shrink' ),
		)
	);

	/* Present is a quiet mark, absent is the pill. Marking both sides makes
	   every one of forty-eight cells shout and none of them read. */
	$ok      = '<span class="lp-ok" aria-label="set">&#10003;</span>';
	$missing = '<span class="lp-pill lp-pill--missing">' . esc_html__( 'missing', 'livepress' ) . '</span>';

	foreach ( $rows as $r ) {
		printf(
			'<tr>'
				. '<td><a class="lp-title" href="%s">%s</a><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink">%s</td><td class="lp-shrink">%s</td><td class="lp-shrink">%s</td>'
				. '<td class="lp-num">%d / %d</td><td class="lp-num lp-muted">%d</td>'
				. '<td class="lp-num">%s</td><td class="lp-num">%s</td>'
				. '<td class="lp-shrink lp-muted">%s</td>'
				. '<td class="lp-shrink"><a class="lp-btn lp-btn--sm" href="%s">' . esc_html__( 'Edit', 'livepress' ) . '</a></td>'
			. '</tr>',
			esc_url( admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $r['id'] ) ),
			esc_html( $r['title'] ),
			esc_html( $r['path'] ),
			$r['seo'] ? $ok : $missing,
			$r['desc'] ? $ok : $missing,
			$r['keyword'] ? $ok : '<span class="lp-pill lp-pill--warn">' . esc_html__( 'not set', 'livepress' ) . '</span>',
			(int) ( $r['total'] - $r['empty'] ),
			(int) $r['total'],
			(int) $r['default'],
			$r['words'] < 300
				? '<span class="lp-warn" title="Thin for a page Google should rank">' . (int) $r['words'] . '</span>'
				: '<span class="lp-muted">' . (int) $r['words'] . '</span>',
			$r['no_alt'] ? '<span class="lp-bad">' . (int) $r['no_alt'] . '</span>' : '<span class="lp-muted">0</span>',
			esc_html( mysql2date( 'j M Y H:i', $r['modified'] ) ),
			esc_url( admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $r['id'] ) )
		);
	}

	livepress_table_close();
	livepress_screen_close();
}

function livepress_render_history() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

	livepress_screen_open(
		__( 'Field history', 'livepress' ),
		__( 'WordPress revisions do not cover custom fields, so this is the only record of what a field held before it changed. Most recent first.', 'livepress' )
	);

	if ( isset( $_GET['restored'] ) ) {
		printf(
			'<div class="lp-notice lp-notice--ok"><p>%s</p></div>',
			sprintf(
				/* translators: %s is the field name, already wrapped in <strong>. */
				__( 'Restored %s to its previous value. The frontend has already been rebuilt.', 'livepress' ),
				'<strong>' . esc_html( livepress_field_label( sanitize_text_field( wp_unslash( $_GET['restored'] ) ) ) ) . '</strong>'
			)
		);
	}

	echo '<form method="get" class="lp-filter"><input type="hidden" name="page" value="livepress-history">';
	echo '<label for="lp-history-page">' . esc_html__( 'Page', 'livepress' ) . '</label>';
	echo '<select id="lp-history-page" name="post" onchange="this.form.submit()"><option value="0">' . esc_html__( 'Choose a page&hellip;', 'livepress' ) . '</option>';
	foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
		printf(
			'<option value="%d"%s>%s</option>',
			(int) $p->ID,
			selected( $post_id, $p->ID, false ),
			esc_html( $p->post_title )
		);
	}
	echo '</select><noscript><button type="submit" class="lp-btn">' . esc_html__( 'Show', 'livepress' ) . '</button></noscript></form>';

	if ( ! $post_id ) {
		livepress_empty_state(
			__( 'Choose a page', 'livepress' ),
			__( 'Pick a page above to see every field change recorded against it, and to put any one of them back.', 'livepress' )
		);
		livepress_screen_close();
		return;
	}

	$history = get_post_meta( $post_id, LIVEPRESS_HISTORY_KEY, true );
	$history = is_array( $history ) ? $history : array();

	if ( ! $history ) {
		livepress_empty_state(
			__( 'No changes recorded yet', 'livepress' ),
			__( 'Nothing on this page has been edited since history started. The first edit will appear here with its previous value.', 'livepress' ),
			sprintf(
				'<a class="lp-btn lp-btn--primary" href="%s">' . esc_html__( 'Open the editor', 'livepress' ) . '</a>',
				esc_url( admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $post_id ) )
			)
		);
		livepress_screen_close();
		return;
	}

	livepress_table_open(
		array(
			array( 'When', 'lp-shrink' ),
			array( 'Field', 'lp-shrink' ),
			array( 'Who', 'lp-shrink' ),
			array( __( 'Previous value', 'livepress' ), '' ),
			array( '', 'lp-shrink' ),
		)
	);

	foreach ( array_reverse( $history, true ) as $i => $e ) {
		$user = get_userdata( (int) ( $e['user'] ?? 0 ) );
		$when = (int) ( $e['time'] ?? 0 );
		$url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=livepress_restore&post=' . $post_id . '&i=' . (int) $i ),
			'livepress_restore_' . $post_id . '_' . (int) $i
		);
		printf(
			'<tr>'
				. '<td class="lp-shrink"><span class="lp-when">%s</span><span class="lp-when-rel">%s</span></td>'
				. '<td class="lp-shrink"><span class="lp-title">%s</span><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink">%s</td>'
				. '<td><code class="lp-code">%s</code></td>'
				. '<td class="lp-shrink"><a class="lp-btn lp-btn--sm" href="%s">' . esc_html__( 'Restore', 'livepress' ) . '</a></td>'
			. '</tr>',
			esc_html( $when ? date_i18n( 'j M Y H:i', $when ) : 'unknown' ),
			esc_html( $when ? human_time_diff( $when ) . ' ago' : '' ),
			esc_html( livepress_field_label( (string) ( $e['key'] ?? '' ) ) ),
			esc_html( (string) ( $e['key'] ?? '' ) ),
			$user ? esc_html( $user->display_name ) : '<span class="lp-muted">' . esc_html__( 'unknown', 'livepress' ) . '</span>',
			'' === trim( (string) ( $e['old'] ?? '' ) )
				? '<span class="lp-muted">' . esc_html__( '(was empty)', 'livepress' ) . '</span>'
				: esc_html( mb_substr( (string) $e['old'], 0, 600 ) ),
			esc_url( $url )
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
