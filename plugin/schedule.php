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
				/*
				 * The preview token, kept on the record rather than in its own
				 * store so it cannot outlive what it grants access to. When the
				 * change lands or is cancelled the record goes and the link
				 * stops working the same instant — a token with a lifetime of
				 * its own is one somebody has to remember to revoke.
				 *
				 * Regenerated on every schedule, so rescheduling invalidates a
				 * link already sent. That is the safer default: the old link
				 * was shared to show a specific change, and after an edit it
				 * would quietly show a different one.
				 */
				'token'  => wp_generate_password( 32, false ),
			)
		)
	);

	/* One event per document. Clearing first means rescheduling moves the
	   existing change rather than queuing a second one. */
	wp_clear_scheduled_hook( LIVEPRESS_SCHEDULE_HOOK, array( $post_id ) );
	wp_schedule_single_event( $when, LIVEPRESS_SCHEDULE_HOOK, array( $post_id ) );

	return true;
}

/**
 * The document a preview token belongs to, or 0.
 *
 * A meta_value LIKE scan rather than an index: there is one pending record per
 * document and a handful of documents, so this reads a few rows. If that ever
 * stops being true the token belongs in its own indexed table, not in a
 * cleverer query over this one.
 *
 * hash_equals on the way out because the LIKE narrows the candidates but does
 * not authenticate them — the comparison that decides access is this one, and
 * it should not leak its answer through timing.
 */
function livepress_preview_post_for_token( string $token ): int {
	if ( strlen( $token ) < 32 ) {
		return 0;
	}
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 20",
			LIVEPRESS_SCHEDULE_KEY,
			'%' . $wpdb->esc_like( $token ) . '%'
		)
	);
	foreach ( $rows as $row ) {
		$pending = maybe_unserialize( $row->meta_value );
		if ( is_array( $pending ) && ! empty( $pending['token'] ) && hash_equals( (string) $pending['token'], $token ) ) {
			return (int) $row->post_id;
		}
	}
	return 0;
}

/**
 * Serve a scheduled change to whoever holds its link.
 *
 * Public on purpose, and that is the whole point: the person you want to show
 * a change to is usually the person without a WordPress login. What stands
 * between the link and the content is the token — 32 characters of
 * wp_generate_password, living on the pending record so it dies when the
 * change lands.
 *
 * It returns only the fields that change, for one document. Not the document,
 * not its neighbours, not anything the token's holder could walk to from here.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'livepress/v1', '/preview/(?P<token>[A-Za-z0-9]{32,64})', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $request ) {
			$token   = (string) $request['token'];
			$post_id = livepress_preview_post_for_token( $token );
			if ( ! $post_id ) {
				return new WP_Error( 'not_found', 'No preview for that link.', array( 'status' => 404 ) );
			}
			$pending = get_post_meta( $post_id, LIVEPRESS_SCHEDULE_KEY, true );
			$post    = get_post( $post_id );
			if ( ! is_array( $pending ) || empty( $pending['values'] ) || ! $post ) {
				return new WP_Error( 'not_found', 'No preview for that link.', array( 'status' => 404 ) );
			}

			$schema = livepress_schema();
			$path   = $schema[ $post->post_name ]['frontendPath'] ?? '/';

			/* Never cached. A preview is a moving target by definition, and a
			   CDN holding one would serve a change after it had been cancelled. */
			do_action( 'litespeed_control_set_nocache', 'livepress preview must be fresh' );
			nocache_headers();

			return rest_ensure_response( array(
				'path'   => str_replace( '{slug}', $post->post_name, $path ),
				'at'     => (int) ( $pending['at'] ?? 0 ),
				'values' => (object) $pending['values'],
			) );
		},
	) );
} );

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
		'token'  => (string) ( $pending['token'] ?? '' ),
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
