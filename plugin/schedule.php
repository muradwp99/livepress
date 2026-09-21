<?php
/**
 * Publish a set of field changes at a chosen time.
 *
 * WordPress schedules a *post* by holding back its first publication. These
 * pages are already published and stay published — what an editor wants to
 * schedule here is a **change**: the new homepage headline goes live on Monday
 * at 9am, not the page itself. So the pending values are parked on the
 * document and swapped in by cron, rather than the post status being touched.
 *
 * Applying a change is the same write the editor makes, so everything already
 * built for it still holds: field history records the previous value, and the
 * instant-publish hook purges the frontend as soon as the swap lands.
 */

defined( 'ABSPATH' ) || exit;

const LIVEPRESS_SCHEDULE_KEY  = '_livepress_scheduled';
const LIVEPRESS_SCHEDULE_HOOK = 'livepress_apply_scheduled';

/** Bookkeeping, never content — it must not reach the frontend. */
add_filter(
	'is_protected_meta',
	function ( $protected, $meta_key ) {
		return LIVEPRESS_SCHEDULE_KEY === $meta_key ? true : $protected;
	},
	10,
	2
);

/**
 * Park a set of values to be written later.
 *
 * `$values` is field key => new value. Stored as one record per document,
 * because two pending changes to the same field would race and the later one
 * would win by accident rather than by intent.
 */
function livepress_schedule_change( int $post_id, array $values, int $when ): bool {
	$post = get_post( $post_id );
	if ( ! $post || 'sitepage' !== $post->post_type || ! $values || $when <= time() ) {
		return false;
	}

	/* Slashed on the way in as well as on the way out. `$values` holds field
	   values verbatim, repeaters among them as JSON, and update_post_meta
	   unslashes whatever it is given — so parking a scheduled change would
	   strip the backslashes out of it days before anybody looked, and the
	   applier would faithfully publish the damaged version. */
	update_post_meta(
		$post_id,
		LIVEPRESS_SCHEDULE_KEY,
		wp_slash(
			array(
				'at'     => $when,
				'by'     => get_current_user_id(),
				'values' => $values,
			)
		)
	);

	/* One event per document. Clearing first means rescheduling moves the
	   existing change rather than queuing a second one. */
	wp_clear_scheduled_hook( LIVEPRESS_SCHEDULE_HOOK, array( $post_id ) );
	wp_schedule_single_event( $when, LIVEPRESS_SCHEDULE_HOOK, array( $post_id ) );

	return true;
}

/** Drop a pending change without applying it. */
function livepress_cancel_scheduled( int $post_id ): void {
	delete_post_meta( $post_id, LIVEPRESS_SCHEDULE_KEY );
	wp_clear_scheduled_hook( LIVEPRESS_SCHEDULE_HOOK, array( $post_id ) );
}

/** What is waiting on this document, in the shape the editor wants. */
function livepress_pending_for( int $post_id ): ?array {
	$pending = get_post_meta( $post_id, LIVEPRESS_SCHEDULE_KEY, true );
	if ( ! is_array( $pending ) || empty( $pending['values'] ) ) {
		return null;
	}
	$user = ! empty( $pending['by'] ) ? get_userdata( (int) $pending['by'] ) : null;
	return array(
		'at'     => (int) ( $pending['at'] ?? 0 ),
		'by'     => $user ? $user->display_name : '',
		'fields' => array_keys( $pending['values'] ),
	);
}

/*
 * The route that was missing.
 *
 * Everything else here was built and reachable — the cron applier, the admin
 * screen, the cancel action, the meta key — and `livepress_schedule_change()`
 * was called from nowhere at all. There was no UI and no endpoint, so the
 * screen could only ever say "Nothing scheduled" and the feature existed
 * entirely on paper.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'livepress/v1',
			'/schedule/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => function ( $request ) {
						return current_user_can( 'edit_post', (int) $request['id'] );
					},
					'callback'            => function ( $request ) {
						$post_id = (int) $request['id'];
						$body    = $request->get_json_params();
						$when    = isset( $body['at'] ) ? (int) $body['at'] : 0;
						$given   = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();

						if ( $when <= time() ) {
							return new WP_Error( 'bad_time', 'Choose a time in the future.', array( 'status' => 400 ) );
						}

						/* Only keys this plugin actually owns, and only strings.
						   The editor sends the right thing; the endpoint is
						   public to anyone who can edit the post, so it does not
						   take the editor's word for it. */
						$allowed = livepress_tracked_keys();
						$values  = array();
						foreach ( $given as $key => $value ) {
							if ( is_string( $key ) && isset( $allowed[ $key ] ) && is_string( $value ) ) {
								$values[ $key ] = $value;
							}
						}
						if ( ! $values ) {
							return new WP_Error( 'no_values', 'Nothing to schedule.', array( 'status' => 400 ) );
						}

						if ( ! livepress_schedule_change( $post_id, $values, $when ) ) {
							return new WP_Error( 'failed', 'Could not schedule that change.', array( 'status' => 400 ) );
						}
						return rest_ensure_response( livepress_pending_for( $post_id ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => function ( $request ) {
						return current_user_can( 'edit_post', (int) $request['id'] );
					},
					'callback'            => function ( $request ) {
						livepress_cancel_scheduled( (int) $request['id'] );
						return rest_ensure_response( array( 'ok' => true ) );
					},
				),
			)
		);
	}
);

/**
 * Write the pending values.
 *
 * Runs on cron, so there is no logged-in user: history would otherwise record
 * the change as made by nobody. The scheduler's id is restored for the write
 * so the person who scheduled it is the person it is attributed to.
 */
add_action(
	LIVEPRESS_SCHEDULE_HOOK,
	function ( $post_id ) {
		$post_id = (int) $post_id;
		$pending = get_post_meta( $post_id, LIVEPRESS_SCHEDULE_KEY, true );
		if ( ! is_array( $pending ) || empty( $pending['values'] ) ) {
			return;
		}

		$previous = get_current_user_id();
		if ( ! empty( $pending['by'] ) ) {
			wp_set_current_user( (int) $pending['by'] );
		}

		foreach ( $pending['values'] as $key => $value ) {
			if ( is_string( $key ) && '' !== $key && 0 !== strpos( $key, '_' ) ) {
				/* wp_slash: update_post_meta unslashes its input, and a
				   repeater is JSON whose newlines are written backslash-n, so
				   an unslashed write turns every one into a literal `n`.
				   Scheduling a change to a repeater would have published a
				   corrupted version of it at the appointed time — the worst
				   possible moment to find out. */
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		wp_set_current_user( $previous );
		delete_post_meta( $post_id, LIVEPRESS_SCHEDULE_KEY );
	}
);

/** Everything waiting to go out, for the admin screen. */
function livepress_pending_changes(): array {
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'sitepage', 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
		$pending = get_post_meta( $post->ID, LIVEPRESS_SCHEDULE_KEY, true );
		if ( is_array( $pending ) && ! empty( $pending['values'] ) ) {
			$out[] = array(
				'post'   => $post,
				'at'     => (int) ( $pending['at'] ?? 0 ),
				'by'     => (int) ( $pending['by'] ?? 0 ),
				'fields' => array_keys( $pending['values'] ),
				'next'   => wp_next_scheduled( LIVEPRESS_SCHEDULE_HOOK, array( $post->ID ) ),
			);
		}
	}
	usort( $out, static fn( $a, $b ) => $a['at'] <=> $b['at'] );
	return $out;
}

add_action(
	'admin_post_livepress_cancel_scheduled',
	function () {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'livepress_cancel_scheduled_' . $post_id );
		livepress_cancel_scheduled( $post_id );
		wp_safe_redirect( admin_url( 'admin.php?page=livepress-scheduled' ) );
		exit;
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'livepress', __( 'Scheduled', 'livepress' ), __( 'Scheduled', 'livepress' ), 'edit_pages', 'livepress-scheduled', 'livepress_render_scheduled' );
	},
	23
);

function livepress_render_scheduled() {
	$pending = livepress_pending_changes();

	livepress_screen_open(
		__( 'Scheduled changes', 'livepress' ),
		__( 'Field changes waiting to go live. These pages stay published throughout — what is scheduled is the edit, not the page. When it lands, the previous value is recorded in Field history and the frontend is rebuilt immediately.', 'livepress' )
	);

	if ( ! $pending ) {
		livepress_empty_state(
			__( 'Nothing scheduled', 'livepress' ),
			__( 'Open a page in the editor, make your changes, and choose a time instead of publishing straight away. It will appear here until it goes out.', 'livepress' ),
			sprintf(
				'<a class="lp-btn" href="%s">Go to Site Pages</a>',
				esc_url( admin_url( 'edit.php?post_type=sitepage' ) )
			)
		);
		livepress_screen_close();
		return;
	}

	$broken = 0;
	foreach ( $pending as $row ) {
		if ( ! $row['next'] ) {
			$broken++;
		}
	}

	livepress_figures(
		array(
			array( 'value' => count( $pending ), 'label' => count( $pending ) === 1 ? 'change waiting' : 'changes waiting' ),
			array( 'value' => date_i18n( 'j M H:i', $pending[0]['at'] ), 'label' => 'next one out' ),
			array( 'value' => $broken, 'label' => 'missing a cron event', 'tone' => $broken ? 'danger' : 'quiet' ),
		)
	);

	livepress_table_open(
		array(
			array( 'Goes live', 'lp-shrink' ),
			array( 'Page', '' ),
			array( 'Scheduled by', 'lp-shrink' ),
			array( 'Fields', '' ),
			array( '', 'lp-shrink' ),
		)
	);

	foreach ( $pending as $row ) {
		$user = $row['by'] ? get_userdata( $row['by'] ) : null;
		$url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=livepress_cancel_scheduled&post=' . $row['post']->ID ),
			'livepress_cancel_scheduled_' . $row['post']->ID
		);
		$labels = array_map( 'livepress_field_label', $row['fields'] );
		printf(
			'<tr>'
				. '<td class="lp-shrink"><span class="lp-when">%s</span>%s</td>'
				. '<td><a class="lp-title" href="%s">%s</a><span class="lp-sub">%s</span></td>'
				. '<td class="lp-shrink">%s</td>'
				. '<td>%s</td>'
				. '<td class="lp-shrink"><a class="lp-btn lp-btn--sm" href="%s">Cancel</a></td>'
			. '</tr>',
			esc_html( date_i18n( 'j M Y H:i', $row['at'] ) ),
			$row['next']
				? '<span class="lp-when-rel">in ' . esc_html( human_time_diff( time(), $row['at'] ) ) . '</span>'
				: '<span class="lp-pill lp-pill--missing">cron event missing</span>',
			esc_url( admin_url( 'admin.php?page=' . LIVEPRESS_PAGE . '&post=' . $row['post']->ID ) ),
			esc_html( $row['post']->post_title ),
			esc_html( $row['post']->post_name ),
			$user ? esc_html( $user->display_name ) : '<span class="lp-muted">unknown</span>',
			esc_html( implode( ', ', $labels ) ),
			esc_url( $url )
		);
	}

	livepress_table_close();
	livepress_screen_close();
}
