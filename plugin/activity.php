<?php
/**
 * LivePress activity: who changed what, across the whole site.
 *
 * Field history answers "what did this page used to say". This answers the
 * other question a site with three people editing it eventually asks: what
 * happened today, and who did it. One is per-document and detailed; this is
 * site-wide and chronological, and neither replaces the other.
 *
 * Built from records that already exist — the per-page history meta and the
 * modification timestamps WordPress keeps anyway — rather than by adding a log
 * table. There is nothing here that was not already being written down.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Activity', 'livepress' ), __( 'Activity', 'livepress' ), 'edit_pages', 'livepress-activity', 'livepress_render_activity' );
	},
	21
);

/** How many entries one screen shows before it stops being readable. */
const LIVEPRESS_ACTIVITY_LIMIT = 200;

/**
 * Every recorded change, newest first.
 *
 * Field edits carry a user and a timestamp. A post's own modification does not
 * record who made it — WordPress keeps `post_modified` but no editor — so those
 * rows say so rather than guessing, because a log that invents an author is
 * worse than one that admits it does not know.
 */
function livepress_activity_rows(): array {
	$rows = array();

	$docs = get_posts(
		array(
			'post_type'   => array( 'sitepage', 'post' ),
			'numberposts' => -1,
			'post_status' => 'any',
		)
	);

	foreach ( $docs as $doc ) {
		$history = get_post_meta( $doc->ID, LIVEPRESS_HISTORY_KEY, true );
		if ( is_array( $history ) ) {
			foreach ( $history as $i => $entry ) {
				$rows[] = array(
					'time'  => (int) ( $entry['time'] ?? 0 ),
					'who'   => (int) ( $entry['user'] ?? 0 ),
					'doc'   => $doc,
					'what'  => 'field',
					'field' => (string) ( $entry['key'] ?? '' ),
					'old'   => (string) ( $entry['old'] ?? '' ),
					'index' => $i,
				);
			}
		}

		$rows[] = array(
			'time'  => (int) mysql2date( 'U', $doc->post_modified_gmt, false ),
			'who'   => 0,
			'doc'   => $doc,
			'what'  => 'saved',
			'field' => '',
			'old'   => '',
			'index' => -1,
		);
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		}
	);

	return array_slice( $rows, 0, LIVEPRESS_ACTIVITY_LIMIT );
}

function livepress_render_activity() {
	$rows = livepress_activity_rows();

	$edits = 0;
	$people = array();
	$today  = 0;
	$since  = time() - DAY_IN_SECONDS;
	foreach ( $rows as $r ) {
		if ( 'field' === $r['what'] ) {
			$edits++;
			if ( $r['who'] ) {
				$people[ $r['who'] ] = true;
			}
		}
		if ( $r['time'] >= $since ) {
			$today++;
		}
	}

	livepress_screen_open(
		__( 'Activity', 'livepress' ),
		sprintf(
			/* translators: %d is the number of recent changes listed. */
			__( 'The %d most recent changes across every page and article. Field edits name the person who made them; a plain save records only that WordPress wrote the document, because it does not keep an editor for that.', 'livepress' ),
			(int) LIVEPRESS_ACTIVITY_LIMIT
		)
	);

	if ( ! $rows ) {
		livepress_empty_state(
			__( 'Nothing recorded yet', 'livepress' ),
			__( 'Field edits and document saves will appear here as they happen, newest first.', 'livepress' )
		);
		livepress_screen_close();
		return;
	}

	livepress_figures(
		array(
			array( 'value' => count( $rows ), 'label' => __( 'changes shown', 'livepress' ) ),
			array( 'value' => $today, 'label' => __( 'in the last 24 hours', 'livepress' ) ),
			array( 'value' => $edits, 'label' => __( 'field edits', 'livepress' ) ),
			array( 'value' => count( $people ), 'label' => __( 'people editing', 'livepress' ), 'tone' => count( $people ) ? '' : 'quiet' ),
		)
	);

	livepress_table_open(
		array(
			array( 'When', 'lp-shrink' ),
			array( 'Document', '' ),
			array( 'Change', 'lp-shrink' ),
			array( 'Who', 'lp-shrink' ),
			array( 'Detail', '' ),
		)
	);

	foreach ( $rows as $r ) {
		$doc  = $r['doc'];
		$user = $r['who'] ? get_userdata( $r['who'] ) : null;

		$edit = 'sitepage' === $doc->post_type
			? admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $doc->ID )
			: get_edit_post_link( $doc->ID, '' );

		if ( 'field' === $r['what'] ) {
			$change = '<span class="lp-pill lp-pill--accent">' . esc_html__( 'edited', 'livepress' ) . '</span>';
			$detail = sprintf(
				'<span class="lp-title">%s</span><code class="lp-code">%s</code>',
				esc_html( livepress_field_label( $r['field'] ) ),
				'' === trim( $r['old'] )
					? esc_html__( '(was empty)', 'livepress' )
					: esc_html( mb_substr( $r['old'], 0, 160 ) )
			);
		} else {
			$change = '<span class="lp-pill lp-pill--quiet">' . esc_html__( 'saved', 'livepress' ) . '</span>';
			$detail = '<span class="lp-muted">' . esc_html__( 'Document written', 'livepress' ) . '</span>';
		}

		printf(
			'<tr>'
				. '<td class="lp-shrink"><span class="lp-when">%s</span><span class="lp-when-rel">%s</span></td>'
				. '<td><a class="lp-title" href="%s">%s</a><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink">%s</td><td class="lp-shrink">%s</td><td>%s</td>'
			. '</tr>',
			esc_html( $r['time'] ? date_i18n( 'j M Y H:i', $r['time'] ) : __( 'unknown', 'livepress' ) ),
			/* translators: %s is a human-readable interval such as "2 hours". */
			esc_html( $r['time'] ? sprintf( __( '%s ago', 'livepress' ), human_time_diff( $r['time'] ) ) : '' ),
			esc_url( (string) $edit ),
			esc_html( $doc->post_title ? $doc->post_title : $doc->post_name ),
			esc_html( $doc->post_type ),
			$change,
			$user ? esc_html( $user->display_name ) : '<span class="lp-muted">' . esc_html__( 'not recorded', 'livepress' ) . '</span>',
			$detail
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
