<?php
/**
 * Form submissions, and the forms that produce them.
 *
 * Two different questions, so two screens:
 *
 *   Enquiries — what came in. Who, from which page, and what they said.
 *   Forms     — what exists. Which forms the site has, what each collects,
 *               where it appears, and how much it has brought in.
 *
 * The submissions list is WordPress's own for the `enquiry` post type rather
 * than a new one. It already has search, date filtering, bulk actions and a
 * CSV export that work; replacing it would mean rebuilding all of that to
 * arrive somewhere no better. What it lacked was legibility — the person's
 * name was buried inside a post title reading "Jane Smith — Contact — Project
 * enquiry", and there was no phone column at all, so answering "who is this
 * and where did they come from" meant opening every row.
 *
 * So the list moves under LivePress and gains the columns it was missing.
 *
 * The Forms screen reads a generated registry (`livepress-forms.php`, from
 * `lib/livepress/forms.ts`). The forms are React components with layouts built
 * for where they sit and that stays true — this is not a builder and does not
 * render them. It is the answer to "what forms does this site have", which
 * previously required grepping the frontend for `useEnquiryForm` and reading
 * eight call sites.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_FORMS_PAGE = 'livepress-forms';

/** The post type r3d-forms stores submissions in. */
const LIVEPRESS_ENQUIRY_TYPE = 'enquiry';

/**
 * Put the submissions list inside LivePress.
 *
 * Via the registration filter rather than by re-registering: r3d-forms owns
 * this post type, and taking it over would mean inheriting every argument it
 * sets today and any it sets later.
 */
add_filter(
	'register_post_type_args',
	function ( $args, $type ) {
		if ( LIVEPRESS_ENQUIRY_TYPE === $type ) {
			$args['show_in_menu'] = 'livepress';
		}
		return $args;
	},
	10,
	2
);

/** The generated list of the site's forms. */
function livepress_forms(): array {
	static $forms = null;
	if ( null === $forms ) {
		$path  = __DIR__ . '/livepress-forms.php';
		$forms = file_exists( $path ) ? (array) require $path : array();
	}
	return $forms;
}

/**
 * The fixed part of a form's `source`, for matching submissions to it.
 *
 * A form that appears on many pages writes `Newsletter — /blog/whatever`, so
 * the stable half is everything before the `{path}` placeholder.
 */
function livepress_form_prefix( array $form ): string {
	$source = (string) ( $form['source'] ?? '' );
	$at     = strpos( $source, '{path}' );
	return false === $at ? $source : substr( $source, 0, $at );
}

/** How many submissions each form has produced, keyed by form id. */
function livepress_form_counts(): array {
	$counts = array();
	foreach ( livepress_forms() as $form ) {
		$counts[ $form['id'] ] = array( 'total' => 0, 'recent' => 0, 'last' => 0 );
	}

	$week = time() - WEEK_IN_SECONDS;
	foreach ( get_posts( array( 'post_type' => LIVEPRESS_ENQUIRY_TYPE, 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
		$source = (string) get_post_meta( $post->ID, 'source', true );
		foreach ( livepress_forms() as $form ) {
			$prefix = livepress_form_prefix( $form );
			if ( '' === $prefix || 0 !== strpos( $source, $prefix ) ) {
				continue;
			}
			$counts[ $form['id'] ]['total']++;
			$when = (int) mysql2date( 'U', $post->post_date_gmt, false );
			if ( $when >= $week ) {
				$counts[ $form['id'] ]['recent']++;
			}
			$counts[ $form['id'] ]['last'] = max( $counts[ $form['id'] ]['last'], $when );
			break; // one form owns a submission
		}
	}

	return $counts;
}

/* ------------------------------------------------------------------ */
/* Making the submissions list readable                                 */
/* ------------------------------------------------------------------ */

add_filter(
	'manage_' . LIVEPRESS_ENQUIRY_TYPE . '_posts_columns',
	function ( $columns ) {
		/* Rebuilt in reading order rather than appended, because the question
		   somebody has in front of this list is "who is this, and where did
		   they come from" — and the answer should be the first thing across. */
		$out = array();
		if ( isset( $columns['cb'] ) ) {
			$out['cb'] = $columns['cb'];
		}
		$out['lp_name']    = 'Name';
		$out['lp_email']   = 'Email';
		$out['lp_phone']   = 'Phone';
		$out['lp_page']    = 'Form and page';
		$out['lp_message'] = 'What they said';
		$out['date']       = 'Received';
		return $out;
	},
	20
);

add_action(
	'manage_' . LIVEPRESS_ENQUIRY_TYPE . '_posts_custom_column',
	function ( $column, $post_id ) {
		$get = static fn( $k ) => (string) get_post_meta( $post_id, $k, true );

		switch ( $column ) {
			case 'lp_name':
				$name = $get( 'name' );
				printf(
					'<strong><a href="%s">%s</a></strong>%s',
					esc_url( (string) get_edit_post_link( $post_id ) ),
					esc_html( '' !== $name ? $name : '(no name given)' ),
					'' !== $get( 'company' ) ? '<br><span style="color:#6b7386">' . esc_html( $get( 'company' ) ) . '</span>' : ''
				);
				break;

			case 'lp_email':
				$email = $get( 'email' );
				/* A mailto, because the next thing anybody does with this list
				   is reply to somebody on it. */
				echo '' !== $email
					? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>'
					: '<span style="color:#6b7386">—</span>';
				break;

			case 'lp_phone':
				$phone = $get( 'phone' );
				echo '' !== $phone
					? '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>'
					: '<span style="color:#6b7386">—</span>';
				break;

			case 'lp_page':
				$source = $get( 'source' );
				$kind   = $get( 'kind' );
				/* `source` is written as "Form name — /the/page", so splitting
				   on the dash gives the two facts separately. */
				$parts = array_map( 'trim', explode( '—', $source, 2 ) );
				printf(
					'%s%s<br><span style="color:#6b7386">%s</span>',
					esc_html( $parts[0] ?: 'unknown form' ),
					'' !== $kind && 'enquiry' !== $kind ? ' <em>(' . esc_html( $kind ) . ')</em>' : '',
					esc_html( $parts[1] ?? '' )
				);
				break;

			case 'lp_message':
				$message = $get( 'message' );
				$service = $get( 'service' );
				if ( '' !== $service ) {
					echo '<strong>' . esc_html( $service ) . '</strong><br>';
				}
				echo '' !== $message
					? esc_html( mb_strimwidth( $message, 0, 140, '…' ) )
					: '<span style="color:#6b7386">—</span>';
				break;
		}
	},
	10,
	2
);

/**
 * A link straight to the section that holds a form's wording.
 *
 * Three of the eight forms already had every word — eyebrow, heading, the
 * dropdown option lists, the placeholder, the submit label — in a LivePress
 * section, and nobody knew, because nothing anywhere said so. Finding it meant
 * reading the page schemas. `edited_in` is `page-slug#section-key`, and the
 * editor already takes a `focus` parameter (added so the Menus item could land
 * on the site-chrome navigation), so this opens the right page scrolled to the
 * right section.
 *
 * The five that return "in the component" are not a failure to look them up:
 * their wording really is compiled into the frontend, and saying so is more
 * use than an empty cell that reads as missing data.
 */
function livepress_form_edit_link( string $edited_in ): string {
	if ( '' === $edited_in || false === strpos( $edited_in, '#' ) ) {
		return '<span class="lp-pill lp-pill--quiet">in the component</span>';
	}

	list( $slug, $section ) = explode( '#', $edited_in, 2 );
	$post = get_page_by_path( $slug, OBJECT, 'sitepage' );
	if ( ! $post ) {
		return '<span class="lp-pill lp-pill--quiet">in the component</span>';
	}

	return sprintf(
		'<a class="lp-btn lp-btn--sm" href="%s">Edit wording</a>',
		esc_url(
			admin_url(
				'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $post->ID . '&focus=' . rawurlencode( $section )
			)
		)
	);
}

/* ------------------------------------------------------------------ */
/* The Forms screen                                                     */
/* ------------------------------------------------------------------ */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', 'Forms', 'Forms', 'edit_pages', LIVEPRESS_FORMS_PAGE, 'livepress_render_forms' );
	},
	27
);

function livepress_render_forms() {
	$forms  = livepress_forms();
	$counts = livepress_form_counts();
	$total  = array_sum( array_column( $counts, 'total' ) );
	$recent = array_sum( array_column( $counts, 'recent' ) );
	$quiet  = count( array_filter( $counts, static fn( $c ) => 0 === $c['total'] ) );

	livepress_screen_open(
		'Forms',
		'Every form on the site, what it collects, and where it appears. The forms are built into the frontend with layouts made for where they sit, so this lists them rather than rendering them — it is the answer to "what forms do we have", which otherwise means reading the frontend source.',
		sprintf(
			'<a class="lp-btn" href="%s">See the submissions</a>',
			esc_url( admin_url( 'edit.php?post_type=' . LIVEPRESS_ENQUIRY_TYPE ) )
		)
	);

	if ( ! $forms ) {
		livepress_empty_state(
			'No form registry found',
			'The list is generated from the frontend. Run `npm run livepress:gen` and deploy livepress-forms.php alongside the schema files.'
		);
		livepress_screen_close();
		return;
	}

	livepress_figures(
		array(
			array( 'value' => count( $forms ), 'label' => 'forms on the site' ),
			array( 'value' => $total, 'label' => 'submissions all time' ),
			array( 'value' => $recent, 'label' => 'in the last 7 days' ),
			array( 'value' => $quiet, 'label' => 'never submitted', 'tone' => $quiet ? 'warn' : 'quiet' ),
		)
	);

	livepress_table_open(
		array(
			array( 'Form', '' ),
			array( 'Type', 'lp-shrink' ),
			array( 'Appears on', '' ),
			array( 'Collects', '' ),
			array( 'Wording', 'lp-shrink' ),
			array( 'Submissions', 'lp-num' ),
			array( 'Last one', 'lp-shrink' ),
		)
	);

	foreach ( $forms as $form ) {
		$c    = $counts[ $form['id'] ] ?? array( 'total' => 0, 'recent' => 0, 'last' => 0 );
		$link = add_query_arg(
			array( 'post_type' => LIVEPRESS_ENQUIRY_TYPE, 's' => livepress_form_prefix( $form ) ),
			admin_url( 'edit.php' )
		);

		printf(
			'<tr>'
				. '<td><span class="lp-title">%s</span><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink"><span class="lp-pill %s">%s</span></td>'
				. '<td class="lp-muted">%s</td>'
				. '<td><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink">%s</td>'
				. '<td class="lp-num">%s</td>'
				. '<td class="lp-shrink lp-muted">%s</td>'
			. '</tr>',
			esc_html( $form['label'] ),
			esc_html( $form['component'] ),
			'enquiry' === $form['kind'] ? 'lp-pill--accent' : 'lp-pill--quiet',
			esc_html( $form['kind'] ),
			esc_html( $form['appears_on'] ),
			esc_html( implode( ', ', (array) $form['fields'] ) ),
			livepress_form_edit_link( (string) ( $form['edited_in'] ?? '' ) ),
			$c['total']
				? sprintf( '<a href="%s"><strong>%d</strong></a>', esc_url( $link ), (int) $c['total'] )
				: '<span class="lp-muted">0</span>',
			esc_html( $c['last'] ? human_time_diff( $c['last'] ) . ' ago' : 'never' )
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
