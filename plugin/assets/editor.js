/**
 * LivePress fullscreen editor.
 * Left: schema-driven field panel. Right: live iframe of the real frontend.
 * Every input streams over postMessage (aux-edit); Save persists via REST.
 */
/* global LIVEPRESS, wp */
(function () {
	"use strict";
	var B = LIVEPRESS;
	var values = JSON.parse( JSON.stringify( B.values ) );
	var dirty = false;
	var frame = null;

	/* ---------- tiny DOM helper ---------- */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === "class" ) { node.className = attrs[ k ]; }
			else if ( k === "text" ) { node.textContent = attrs[ k ]; }
			else if ( k.slice( 0, 2 ) === "on" ) { node.addEventListener( k.slice( 2 ), attrs[ k ] ); }
			else { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { if ( c ) { node.appendChild( c ); } } );
		return node;
	}

	function fill( node, children ) {
		( children || [] ).forEach( function ( c ) { if ( c ) { node.appendChild( c ); } } );
		return node;
	}

	/* ---------- live bridge ---------- */
	function fieldDef( key ) {
		for ( var i = 0; i < B.schema.sections.length; i++ ) {
			var fs = B.schema.sections[ i ].fields;
			for ( var j = 0; j < fs.length; j++ ) {
				if ( fs[ j ].key === key ) { return fs[ j ]; }
			}
		}
		return null;
	}
	function broadcast( key ) {
		var def = fieldDef( key );
		if ( ! def || ! frame || ! frame.contentWindow ) { return; }
		var v = values[ key ];
		var value = v;
		if ( def.kind === "lines" ) {
			value = String( v || "" ).split( "\n" ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
		}
		try {
			frame.contentWindow.postMessage( { type: "aux-edit", path: def.path, value: value }, B.frontend );
		} catch ( e ) { /* frame not ready */ }
	}
	function broadcastAll() {
		B.schema.sections.forEach( function ( s ) {
			s.fields.forEach( function ( f ) { broadcast( f.key ); } );
		} );
	}

	function setValue( key, v ) {
		values[ key ] = v;
		dirty = true;
		document.getElementById( "lp-save" ).classList.add( "is-dirty" );
		broadcast( key );
		persistDraft();
		refreshReviewCount();
		refreshSectionBadges();
	}

	/* ---------- Wave 1: safety ----------
	 *
	 * Editing used to be a one-way street. Every keystroke streamed into the
	 * preview, Save wrote straight to the live page, and there was no way to
	 * see what you had changed, take it back, survive a closed tab, or find
	 * out that somebody else had edited the same page while you worked.
	 */

	/* The document as it was when this editor opened. Everything below is a
	   comparison against this, so it is deep-copied rather than referenced. */
	var original = JSON.parse( JSON.stringify( B.values ) );
	var DRAFT_KEY = "livepress:draft:" + ( B.postId || B.mode || "globals" );

	function asText( v ) {
		return typeof v === "string" ? v : JSON.stringify( v == null ? "" : v );
	}

	/** Every field whose value differs from the one this editor loaded. */
	function changedFields() {
		var out = [];
		B.schema.sections.forEach( function ( s ) {
			s.fields.forEach( function ( f ) {
				var before = asText( original[ f.key ] );
				var after = asText( values[ f.key ] );
				if ( before !== after ) {
					out.push( { key: f.key, label: f.label || f.key, section: s.label, before: before, after: after } );
				}
			} );
		} );
		if ( asText( original.section_order ) !== asText( values.section_order ) ) {
			out.push( { key: "section_order", label: "Section order", section: "Layout",
				before: asText( original.section_order ), after: asText( values.section_order ) } );
		}
		return out;
	}

	/* ---------- autosave recovery ---------- */

	/*
	 * A closed tab, a crash or a stray refresh used to lose everything since
	 * the last Save. The working copy is mirrored to this browser so it can be
	 * offered back. Deliberately local: a half-finished edit is not something
	 * to publish to a shared server behind the editor's back.
	 */
	var persistTimer = null;
	function persistDraft() {
		clearTimeout( persistTimer );
		persistTimer = setTimeout( function () {
			try {
				window.localStorage.setItem( DRAFT_KEY, JSON.stringify( {
					at: Date.now(),
					base: B.modified || "",
					values: values
				} ) );
			} catch ( e ) { /* private mode, or full. Not worth interrupting a save for. */ }
		}, 600 );
	}

	function clearDraft() {
		try { window.localStorage.removeItem( DRAFT_KEY ); } catch ( e ) {}
	}

	function readDraft() {
		try {
			var raw = window.localStorage.getItem( DRAFT_KEY );
			if ( ! raw ) { return null; }
			var d = JSON.parse( raw );
			return d && d.values ? d : null;
		} catch ( e ) { return null; }
	}

	/* ---------- the review / recover strip ---------- */

	function bar( message, actions, tone ) {
		var wrap = el( "div", { class: "lp-bar" + ( tone ? " " + tone : "" ) } );
		wrap.appendChild( el( "span", { class: "lp-bar-msg", text: message } ) );
		var right = el( "div", { class: "lp-bar-actions" } );
		actions.forEach( function ( a ) {
			right.appendChild( el( "button", { class: "lp-mini" + ( a.danger ? " danger" : "" ), type: "button", text: a.label, onclick: a.onclick } ) );
		} );
		wrap.appendChild( right );
		return wrap;
	}

	function showBar( node ) {
		var host = document.getElementById( "lp-bars" );
		if ( host ) { host.appendChild( node ); }
	}

	function openReview() {
		var changes = changedFields();
		var body = el( "div", { class: "lp-review-body" } );

		if ( ! changes.length ) {
			body.appendChild( el( "p", { class: "lp-review-empty", text: "Nothing has changed since this page was opened." } ) );
		} else {
			changes.forEach( function ( c ) {
				body.appendChild( el( "div", { class: "lp-change" }, [
					el( "div", { class: "lp-change-head" }, [
						el( "strong", { text: c.label } ),
						el( "span", { class: "lp-change-sec", text: c.section } )
					] ),
					el( "div", { class: "lp-change-cols" }, [
						el( "div", { class: "lp-change-before" }, [
							el( "span", { class: "lp-sublabel", text: "Before" } ),
							el( "code", { text: c.before.slice( 0, 400 ) || "(empty)" } )
						] ),
						el( "div", { class: "lp-change-after" }, [
							el( "span", { class: "lp-sublabel", text: "After" } ),
							el( "code", { text: c.after.slice( 0, 400 ) || "(empty)" } )
						] )
					] )
				] ) );
			} );
		}

		var modal = el( "div", { class: "lp-modal" }, [
			el( "div", { class: "lp-modal-card" }, [
				el( "div", { class: "lp-modal-head" }, [
					el( "strong", { text: changes.length ? changes.length + " change" + ( changes.length === 1 ? "" : "s" ) + " to publish" : "No changes" } ),
					el( "button", { class: "lp-mini", type: "button", text: "Close", onclick: function () { modal.remove(); } } )
				] ),
				body,
				el( "div", { class: "lp-modal-foot" }, [
					el( "button", {
						class: "lp-mini danger", type: "button", text: "Discard all changes",
						onclick: function () {
							if ( ! changes.length ) { modal.remove(); return; }
							if ( ! window.confirm( "Discard " + changes.length + " change" + ( changes.length === 1 ? "" : "s" ) + " and reload the saved version?" ) ) { return; }
							clearDraft();
							dirty = false;
							window.location.reload();
						}
					} ),
					el( "button", { class: "lp-save", type: "button", text: "Publish", onclick: function () { modal.remove(); save(); } } )
				] )
			] )
		] );
		modal.addEventListener( "click", function ( e ) { if ( e.target === modal ) { modal.remove(); } } );
		document.body.appendChild( modal );
	}

	/* ---------- conflict detection ---------- */

	/*
	 * Two editors on one page used to overwrite each other in silence — last
	 * save wins, the other person's work simply gone with nothing to show it
	 * ever existed. This compares the document's modified time against the one
	 * this editor opened with.
	 */
	/**
	 * Has somebody else changed a field this save is about to write?
	 *
	 * The old version compared the document's modification time and warned on
	 * any difference, which meant a colleague fixing a typo in the footer put
	 * a frightening dialog in front of someone editing the hero — and the
	 * dialog was right to be frightening, because the save really did write
	 * all sixty-six fields.
	 *
	 * Now that a save only carries the fields that moved, the only genuine
	 * conflict is one where their value for a field *I* am writing differs
	 * from the value I loaded. Anything else merges, so it asks nothing.
	 */
	/* ---------- scheduling ---------- */

	/*
	 * Park the current changes and let them go out later.
	 *
	 * WordPress schedules a *post* by holding back its first publication.
	 * These pages are already published and stay published — what an editor
	 * wants to schedule is the **change**: the new headline goes live Monday
	 * at 9am, not the page. So the changed values are parked on the document
	 * and swapped in by cron.
	 *
	 * Built on `changedFields()` for the same reason the save is: scheduling
	 * the whole document would park sixty-six values and, when they landed,
	 * overwrite whatever anybody else had done in the meantime.
	 */
	function scheduleState() {
		return B.pending || null;
	}

	/** `datetime-local` wants local wall-clock, not an ISO instant. */
	function toLocalInput( date ) {
		var pad = function ( n ) { return ( n < 10 ? "0" : "" ) + n; };
		return date.getFullYear() + "-" + pad( date.getMonth() + 1 ) + "-" + pad( date.getDate() ) +
			"T" + pad( date.getHours() ) + ":" + pad( date.getMinutes() );
	}

	function renderPendingBar() {
		var p = scheduleState();
		var host = document.getElementById( "lp-bars" );
		if ( ! host ) { return; }
		var existing = host.querySelector( ".lp-bar.scheduled" );
		if ( existing ) { existing.remove(); }
		if ( ! p ) { return; }

		var when = new Date( p.at * 1000 );
		var node = bar(
			p.fields.length + ( p.fields.length === 1 ? " change goes" : " changes go" ) +
				" live " + when.toLocaleString() + ( p.by ? ", scheduled by " + p.by : "" ),
			[ {
				label: "Cancel it",
				danger: true,
				onclick: function () {
					wp.apiFetch( { path: "/livepress/v1/schedule/" + B.postId, method: "DELETE" } )
						.then( function () { B.pending = null; renderPendingBar(); } );
				},
			} ],
			"warn"
		);
		node.classList.add( "scheduled" );
		host.appendChild( node );
	}

	function openSchedule() {
		var changes = changedFields();

		var body = el( "div", { class: "lp-review-body" } );
		if ( ! changes.length ) {
			body.appendChild( el( "p", { class: "lp-review-empty", text: "Nothing has changed yet, so there is nothing to schedule. Edit a field first." } ) );
		} else {
			body.appendChild( el( "p", { class: "lp-hint", text:
				"These " + changes.length + " change" + ( changes.length === 1 ? "" : "s" ) +
				" will be written at the time you choose. The page stays published and unchanged until then, " +
				"and the previous values are recorded in Field history when it lands." } ) );
			var list = el( "ul", { class: "lp-sched-list" } );
			changes.forEach( function ( c ) {
				list.appendChild( el( "li", {}, [
					el( "strong", { text: c.label } ),
					el( "span", { class: "lp-change-sec", text: c.section } ),
				] ) );
			} );
			body.appendChild( list );
		}

		/* Default to nine tomorrow morning — the time somebody actually means
		   when they say "put this out tomorrow", rather than this minute. */
		var when = new Date();
		when.setDate( when.getDate() + 1 );
		when.setHours( 9, 0, 0, 0 );

		var input = el( "input", { class: "lp-input", type: "datetime-local" } );
		input.value = toLocalInput( when );
		input.min = toLocalInput( new Date( Date.now() + 60000 ) );

		var error = el( "p", { class: "lp-hint lp-sched-error" } );

		var go = el( "button", { class: "lp-save", type: "button", text: "Schedule" } );
		go.disabled = ! changes.length;
		go.addEventListener( "click", function () {
			var at = Math.floor( new Date( input.value ).getTime() / 1000 );
			if ( ! at || at * 1000 <= Date.now() ) {
				error.textContent = "Pick a time in the future.";
				return;
			}
			var payload = {};
			changes.forEach( function ( c ) { payload[ c.key ] = metaValue( c.key ); } );

			go.disabled = true;
			go.textContent = "Scheduling…";
			wp.apiFetch( {
				path: "/livepress/v1/schedule/" + B.postId,
				method: "POST",
				data: { at: at, values: payload },
			} )
				.then( function ( pending ) {
					B.pending = pending;
					modal.remove();
					renderPendingBar();
				} )
				.catch( function ( err ) {
					error.textContent = ( err && err.message ) || "Could not schedule that change.";
					go.disabled = false;
					go.textContent = "Schedule";
				} );
		} );

		var modal = el( "div", { class: "lp-modal" }, [
			el( "div", { class: "lp-modal-card" }, [
				el( "div", { class: "lp-modal-head" }, [
					el( "strong", { text: "Schedule this change" } ),
					el( "button", { class: "lp-mini", type: "button", text: "Close", onclick: function () { modal.remove(); } } ),
				] ),
				body,
				el( "div", { class: "lp-modal-foot" }, [
					el( "div", { class: "lp-sched-when" }, [
						el( "label", { class: "lp-label", text: "Goes live" } ),
						input,
						error,
					] ),
					go,
				] ),
			] ),
		] );
		document.body.appendChild( modal );
		input.focus();
	}

	function checkConflict() {
		if ( ! B.postId || ! B.modified ) { return Promise.resolve( null ); }
		var mine = changedFields();
		if ( ! mine.length ) { return Promise.resolve( null ); }

		return wp.apiFetch( { path: "/wp/v2/" + B.restBase + "/" + B.postId + "?_fields=modified_gmt,meta" } )
			.then( function ( doc ) {
				var now = ( doc && doc.modified_gmt ) || "";
				if ( ! now || now === B.modified ) { return null; }

				/* The document moved. Did it move under one of my fields? */
				var theirs = ( doc && doc.meta ) || {};
				var clashes = mine.filter( function ( c ) {
					if ( ! ( c.key in theirs ) ) { return false; }
					return String( theirs[ c.key ] == null ? "" : theirs[ c.key ] ) !== c.before;
				} );
				return clashes.length ? { at: now, fields: clashes } : null;
			} )
			.catch( function () { return null; } );
	}

	/** Keep the Review button honest about how much is waiting to go out. */
	function refreshReviewCount() {
		var btn = document.getElementById( "lp-review" );
		if ( ! btn ) { return; }
		var n = changedFields().length;
		btn.textContent = n ? "Review (" + n + ")" : "Review";
		btn.classList.toggle( "has-changes", n > 0 );
	}

	/**
	 * Offer back a working copy this browser kept from a previous visit.
	 *
	 * Only when it actually differs from what the server now holds — otherwise
	 * every reload would ask a pointless question. And only as an offer: the
	 * saved version stays on screen until the person chooses, because silently
	 * restoring an old draft over a colleague's published work would be a
	 * worse failure than losing the draft.
	 */
	function offerRecovery() {
		var d = readDraft();
		if ( ! d || ! d.values ) { return; }

		var differs = false;
		Object.keys( d.values ).forEach( function ( k ) {
			if ( asText( d.values[ k ] ) !== asText( values[ k ] ) ) { differs = true; }
		} );
		if ( ! differs ) { clearDraft(); return; }

		var when = new Date( d.at || Date.now() );
		var stale = d.base && B.modified && d.base !== B.modified;

		showBar( bar(
			"Unsaved changes from " + when.toLocaleString() + " were recovered from this browser." +
				( stale ? " The page has been saved by someone since." : "" ),
			[
				{
					label: "Restore them",
					onclick: function () {
						Object.keys( d.values ).forEach( function ( k ) { values[ k ] = d.values[ k ]; } );
						dirty = true;
						broadcastAll();
						rerenderPanel();
						refreshReviewCount();
						document.getElementById( "lp-bars" ).innerHTML = "";
					}
				},
				{
					label: "Discard",
					danger: true,
					onclick: function () {
						clearDraft();
						document.getElementById( "lp-bars" ).innerHTML = "";
					}
				}
			],
			stale ? "warn" : ""
		) );
	}

	/** Rebuild the field panel in place, after values change wholesale. */
	function rerenderPanel() {
		var host = document.querySelector( ".lp-sections" );
		if ( ! host || ! host.parentNode ) { return; }
		var fresh = buildPanel();
		host.parentNode.replaceChild( fresh, host );
	}

	/* ---------- Wave 2: speed ----------
	 *
	 * Pages carry between 30 and 66 fields. Scrolling to find one was the
	 * single largest cost of every edit, and nothing on screen said which
	 * sections still needed work, or whether a title would survive the point
	 * where Google stops showing it.
	 */

	/* Marks to aim near, not hard limits: Google measures pixels, not
	   characters. The counter warns and never blocks. */
	var SEO_LIMITS = { rank_math_title: 60, rank_math_description: 155 };

	/** A field counts as filled when it holds something a reader would see. */
	function fieldFilled( def ) {
		var v = values[ def.key ];
		if ( def.kind === "repeater" ) { return !! ( v && v.length ); }
		return String( v == null ? "" : v ).trim() !== "";
	}

	function sectionState( section ) {
		var total = section.fields.length;
		var filled = 0;
		section.fields.forEach( function ( f ) { if ( fieldFilled( f ) ) { filled++; } } );
		return { total: total, filled: filled,
			state: filled === 0 ? "empty" : ( filled === total ? "full" : "part" ) };
	}

	function refreshSectionBadges() {
		B.schema.sections.forEach( function ( section ) {
			var sec = document.querySelector( SEC_SEL( section.key ) );
			if ( ! sec ) { return; }
			var badge = sec.querySelector( ".lp-sec-count" );
			if ( ! badge ) { return; }
			var st = sectionState( section );
			badge.textContent = st.filled + "/" + st.total;
			badge.className = "lp-sec-count is-" + st.state;
		} );
	}

	function SEC_SEL( key ) { return ".lp-section[data-key=" + JSON.stringify( key ) + "]"; }
	function FIELD_SEL( key ) { return ".lp-field[data-field=" + JSON.stringify( key ) + "]"; }

	/* ---------- character counters ---------- */
	function attachCounter( key, input ) {
		var limit = SEO_LIMITS[ key ];
		if ( ! limit ) { return null; }
		var out = el( "span", { class: "lp-count" } );
		function paint() {
			var n = String( input.value || "" ).length;
			out.textContent = n + " / " + limit;
			out.className = "lp-count" + ( n > limit ? " over" : ( n > limit * 0.9 ? " near" : "" ) );
		}
		input.addEventListener( "input", paint );
		paint();
		return out;
	}

	/* ---------- auto-growing textareas ---------- */
	/* A fixed height is either wasted space or a slot you edit through. */
	function autoGrow( ta ) {
		function fit() {
			ta.style.height = "auto";
			ta.style.height = Math.min( ta.scrollHeight + 2, 420 ) + "px";
		}
		ta.addEventListener( "input", fit );
		setTimeout( fit, 0 );
		return ta;
	}

	/* ---------- image preview ---------- */
	/*
	 * An image field showed a URL, so the only way to know what was in a slot
	 * was to look at the preview and guess which picture it was. This shows
	 * the picture, and flags one too small for the slot it fills — which is
	 * how a blurry render reaches a live page with nobody noticing.
	 *
	 * A field can hold `/gallery/villa-moon-b.webp`, a file in the frontend's
	 * `public/`, as readily as a media-library URL. Resolved against this admin
	 * — which is where a bare src in an admin page would point — it 404s, and
	 * the thumbnail cried "will not load" over an image that is fine on the
	 * site. The frontend is the host that serves it, so resolve against that.
	 */
	function imagePreview( getUrl ) {
		var wrap = el( "div", { class: "lp-thumb" } );
		function paint() {
			wrap.innerHTML = "";
			var url = getUrl();
			if ( ! url ) { wrap.classList.remove( "has" ); return; }
			wrap.classList.add( "has" );
			var src = url;
			try { src = new URL( url, B.frontend ).href; } catch ( e ) {}
			var img = el( "img", { src: src, alt: "" } );
			var meta = el( "span", { class: "lp-thumb-meta", text: "loading" } );
			img.addEventListener( "load", function () {
				var w = img.naturalWidth, h = img.naturalHeight;
				meta.textContent = w + " x " + h;
				if ( w && w < 900 ) {
					meta.textContent += " - small";
					meta.classList.add( "warn" );
				}
				/*
				 * Weight, which dimensions alone do not reveal: the same
				 * 1600x900 render is 180KB or 4MB depending only on how it was
				 * exported, and nothing in this editor would have said so.
				 *
				 * HEAD because the browser already has the pixels and this is
				 * only after the header. Content-Length is not CORS-safelisted,
				 * so a cross-origin image simply reports no weight rather than
				 * a wrong one — the dimensions above still stand.
				 */
				fetch( src, { method: "HEAD" } )
					.then( function ( r ) {
						var bytes = Number( r.headers.get( "content-length" ) || 0 );
						if ( ! bytes ) { return; }
						var mb = bytes / 1048576;
						meta.textContent += " - " + ( mb >= 1
							? mb.toFixed( 1 ) + " MB"
							: Math.round( bytes / 1024 ) + " KB" );
						if ( bytes > 1048576 ) {
							meta.textContent += " - heavy";
							meta.classList.add( "warn" );
						}
					} )
					.catch( function () { /* offline, or CORS. Not worth saying. */ } );
			} );
			img.addEventListener( "error", function () {
				meta.textContent = "will not load";
				meta.classList.add( "warn" );
			} );
			wrap.appendChild( img );
			wrap.appendChild( meta );
		}
		paint();
		wrap.refresh = paint;
		return wrap;
	}

	/* ---------- jump to a field ---------- */
	function fieldIndex() {
		var out = [];
		B.schema.sections.forEach( function ( s ) {
			s.fields.forEach( function ( f ) {
				out.push( { key: f.key, label: f.label || f.key, section: s.label, sectionKey: s.key } );
			} );
		} );
		return out;
	}

	function jumpToField( entry ) {
		var sec = document.querySelector( SEC_SEL( entry.sectionKey ) );
		if ( ! sec ) { return; }
		sec.classList.add( "open" );
		var field = sec.querySelector( FIELD_SEL( entry.key ) );
		var target = field || sec;
		target.scrollIntoView( { behavior: "smooth", block: "center" } );
		target.classList.add( "flash" );
		setTimeout( function () { target.classList.remove( "flash" ); }, 1400 );
		var input = field && field.querySelector( ".lp-input" );
		if ( input ) { setTimeout( function () { input.focus(); }, 260 ); }
	}

	function openPalette() {
		var all = fieldIndex();
		var input = el( "input", { class: "lp-pal-input", type: "text", placeholder: "Jump to a field" } );
		var list = el( "div", { class: "lp-pal-list" } );
		var active = 0;
		var shown = [];

		function render() {
			var q = input.value.trim().toLowerCase();
			shown = all.filter( function ( f ) {
				return ! q || ( f.label + " " + f.section + " " + f.key ).toLowerCase().indexOf( q ) !== -1;
			} ).slice( 0, 40 );
			if ( active >= shown.length ) { active = 0; }
			list.innerHTML = "";
			shown.forEach( function ( f, i ) {
				list.appendChild( el( "button", {
					class: "lp-pal-row" + ( i === active ? " active" : "" ), type: "button",
					onclick: function () { modal.remove(); jumpToField( f ); }
				}, [
					el( "span", { class: "lp-pal-label", text: f.label } ),
					el( "span", { class: "lp-pal-sec", text: f.section } )
				] ) );
			} );
		}

		input.addEventListener( "input", render );
		input.addEventListener( "keydown", function ( e ) {
			if ( e.key === "ArrowDown" ) { e.preventDefault(); active = Math.min( active + 1, shown.length - 1 ); render(); }
			else if ( e.key === "ArrowUp" ) { e.preventDefault(); active = Math.max( active - 1, 0 ); render(); }
			else if ( e.key === "Enter" ) { e.preventDefault(); if ( shown[ active ] ) { modal.remove(); jumpToField( shown[ active ] ); } }
			else if ( e.key === "Escape" ) { modal.remove(); }
		} );

		var modal = el( "div", { class: "lp-modal lp-pal" }, [
			el( "div", { class: "lp-pal-card" }, [ input, list ] )
		] );
		modal.addEventListener( "click", function ( e ) { if ( e.target === modal ) { modal.remove(); } } );
		document.body.appendChild( modal );
		render();
		input.focus();
	}

	/* ---------- resizable panel ---------- */
	/* 470px suits SEO fields and is tight for body copy. Which one an editor
	   is doing is not something this plugin can know, so it stops guessing. */
	function makeResizable( panel ) {
		var stored = null;
		try { stored = window.localStorage.getItem( "livepress:panelWidth" ); } catch ( e ) {}
		if ( stored ) { panel.style.width = stored; }

		var grip = el( "div", { class: "lp-grip", title: "Drag to resize" } );
		var dragging = false;
		grip.addEventListener( "mousedown", function ( e ) { dragging = true; e.preventDefault(); document.body.style.cursor = "col-resize"; } );
		document.addEventListener( "mousemove", function ( e ) {
			if ( ! dragging ) { return; }
			panel.style.width = Math.max( 340, Math.min( e.clientX, window.innerWidth - 420 ) ) + "px";
		} );
		document.addEventListener( "mouseup", function () {
			if ( ! dragging ) { return; }
			dragging = false;
			document.body.style.cursor = "";
			try { window.localStorage.setItem( "livepress:panelWidth", panel.style.width ); } catch ( e ) {}
		} );
		return grip;
	}

	/* ---------- field renderers ---------- */
	function inputFor( key, def ) {
		if ( def.kind === "textarea" || def.kind === "lines" ) {
			var ta = el( "textarea", { class: "lp-input", rows: def.kind === "lines" ? 5 : 3 } );
			ta.value = values[ key ] || "";
			ta.addEventListener( "input", function () { setValue( key, ta.value ); } );
			return autoGrow( ta );
		}
		var input = el( "input", { class: "lp-input", type: "text" } );
		input.value = values[ key ] || "";
		input.addEventListener( "input", function () { setValue( key, input.value ); } );
		return input;
	}

	/*
	 * Getting a picture into an image field.
	 *
	 * There were two problems. Repeater image columns had a picker button
	 * labelled 🖼 and nothing else; **scalar image fields had no button at
	 * all** — just a text box holding a URL and, since this week, a thumbnail.
	 * So on a field like the contact page's "Panel image" the only way to
	 * change the picture was to paste a URL you had gone and found elsewhere.
	 *
	 * And choosing from the library is not the same as having the file. The
	 * common case is a render that has just come out of the studio and is
	 * sitting on somebody's desktop: it has to reach the server before it can
	 * be chosen. So there are two buttons, not one, and the field also accepts
	 * a file dropped straight onto it.
	 *
	 * Uploads go to `/wp/v2/media` through `wp.apiFetch`, which carries the
	 * REST nonce — the same endpoint and the same permission check the Media
	 * Library itself uses, so nothing here is a second way into the server.
	 */

	var ICON_LIBRARY =
		'<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.3" ' +
		'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
		'<rect x="1.5" y="3" width="13" height="10" rx="1.4"/><path d="M1.5 10.5l3.5-3 3 2.5 2.5-2 4 3.5"/>' +
		'<circle cx="5.75" cy="6.25" r="1"/></svg>';

	var ICON_UPLOAD =
		'<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.3" ' +
		'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
		'<path d="M8 10.5V2.5M5 5.5L8 2.5l3 3"/><path d="M2.5 10.5v2a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-2"/></svg>';

	/** Send one file to the media library and hand back its URL. */
	function uploadImage( file ) {
		var data = new FormData();
		data.append( "file", file, file.name );
		return wp.apiFetch( { path: "/wp/v2/media", method: "POST", body: data } )
			.then( function ( att ) {
				/* `source_url` is the original. The library also returns sized
				   versions, but a field that asked for an image should get the
				   one that was uploaded, not a thumbnail of it. */
				return ( att && att.source_url ) || "";
			} );
	}

	/**
	 * Choose / Upload, plus drop-anywhere on the field.
	 *
	 * `host` is the element a file may be dropped on — the whole field, so the
	 * target is the size of the thing you are looking at rather than a button.
	 */
	function mediaControls( assign, host ) {
		var picker = el( "button", { class: "lp-media-btn", type: "button", title: "Choose from the Media Library" } );
		picker.innerHTML = ICON_LIBRARY;
		picker.appendChild( el( "span", { text: "Choose" } ) );
		picker.addEventListener( "click", function () {
			var frame = wp.media( { title: "Choose image", multiple: false, library: { type: "image" } } );
			frame.on( "select", function () {
				assign( frame.state().get( "selection" ).first().toJSON().url );
			} );
			frame.open();
		} );

		var file = el( "input", { type: "file", accept: "image/*", class: "lp-file" } );
		var upload = el( "button", { class: "lp-media-btn", type: "button", title: "Upload a file from this computer" } );
		upload.innerHTML = ICON_UPLOAD;
		upload.appendChild( el( "span", { text: "Upload" } ) );
		upload.addEventListener( "click", function () { file.click(); } );

		function run( f ) {
			if ( ! f || ! /^image\//.test( f.type ) ) { return; }
			var label = upload.querySelector( "span" );
			var was = label.textContent;
			upload.disabled = true;
			label.textContent = "Uploading…";
			uploadImage( f )
				.then( function ( url ) {
					if ( url ) { assign( url ); }
					label.textContent = url ? "Uploaded" : "Failed";
				} )
				.catch( function ( err ) {
					label.textContent = "Failed";
					console.error( "LivePress upload failed", err );
				} )
				.then( function () {
					setTimeout( function () { label.textContent = was; upload.disabled = false; }, 1600 );
					file.value = "";
				} );
		}

		file.addEventListener( "change", function () { run( file.files && file.files[ 0 ] ); } );

		if ( host ) {
			/* dragover must be cancelled or the browser navigates to the file,
			   which loses whatever was unsaved in the editor. */
			host.addEventListener( "dragover", function ( e ) {
				if ( e.dataTransfer && e.dataTransfer.types.indexOf( "Files" ) !== -1 ) {
					e.preventDefault();
					host.classList.add( "lp-dropping" );
				}
			} );
			host.addEventListener( "dragleave", function () { host.classList.remove( "lp-dropping" ); } );
			host.addEventListener( "drop", function ( e ) {
				if ( ! e.dataTransfer || ! e.dataTransfer.files.length ) { return; }
				e.preventDefault();
				host.classList.remove( "lp-dropping" );
				run( e.dataTransfer.files[ 0 ] );
			} );
		}

		return el( "div", { class: "lp-media-actions" }, [ picker, upload, file ] );
	}

	/** Kept for callers that only want the library picker. */
	function mediaButton( assign ) {
		return mediaControls( assign, null );
	}

	function repeaterRow( key, def, row, idx, rerender ) {
		var handle = el( "span", { class: "lp-drag", text: "⋮⋮", draggable: "true", title: "Drag to reorder" } );
		handle.addEventListener( "dragstart", function ( e ) {
			e.dataTransfer.setData( "text/plain", String( idx ) );
			e.dataTransfer.effectAllowed = "move";
		} );

		var body = el( "div", { class: "lp-row-fields" } );
		def.subs.forEach( function ( sub ) {
			var wrapCls = "lp-subfield" + ( sub.kind === "textarea" ? " wide" : "" );
			var field;
			if ( sub.kind === "textarea" ) {
				field = el( "textarea", { class: "lp-input", rows: 2 } );
			} else {
				field = el( "input", { class: "lp-input", type: "text" } );
			}
			field.value = row[ sub.key ] || "";
			field.addEventListener( "input", function () {
				row[ sub.key ] = field.value;
				setValue( key, values[ key ] );
			} );
			var wrapCell = el( "div", { class: wrapCls } );
			var inner = [ el( "label", { class: "lp-sublabel", text: sub.label } ), field ];
			if ( sub.kind === "image" ) {
				var pair = el( "div", { class: "lp-media-pair" }, [ field, mediaControls( function ( url ) {
					field.value = url;
					row[ sub.key ] = url;
					setValue( key, values[ key ] );
					thumb.refresh();
				}, wrapCell ) ] );
				/*
				 * Repeater image columns had no preview at all — only scalar
				 * image fields got one. On this site that is most of the
				 * pictures: every gallery, every case study, every process
				 * step keeps its images in a repeater, so the editor showed a
				 * URL and nothing else for exactly the fields where seeing the
				 * picture matters most.
				 */
				var thumb = imagePreview( function () { return row[ sub.key ]; } );
				field.addEventListener( "input", function () { thumb.refresh(); } );
				inner = [ el( "label", { class: "lp-sublabel", text: sub.label } ), pair, thumb ];
			}
			body.appendChild( fill( wrapCell, inner ) );
		} );

		/* Duplicating beats adding-then-retyping for a row that differs in one
		   field, which is most of them: a gallery row keeps its alt pattern, a
		   process step keeps its shape. Deep-copied so the clone does not share
		   the original's object and edit both at once. */
		var duplicate = el( "button", {
			class: "lp-row-del", type: "button", text: "⧉", title: "Duplicate row",
			onclick: function () {
				var clone = JSON.parse( JSON.stringify( row ) );
				values[ key ].splice( idx + 1, 0, clone );
				setValue( key, values[ key ] );
				rerender();
			},
		} );

		var remove = el( "button", {
			class: "lp-row-del", type: "button", text: "✕", title: "Remove row",
			onclick: function () {
				values[ key ].splice( idx, 1 );
				setValue( key, values[ key ] );
				rerender();
			},
		} );

		var rowEl = el( "div", { class: "lp-row", "data-idx": String( idx ) }, [ handle, body, duplicate, remove ] );
		rowEl.addEventListener( "dragover", function ( e ) { e.preventDefault(); rowEl.classList.add( "drop" ); } );
		rowEl.addEventListener( "dragleave", function () { rowEl.classList.remove( "drop" ); } );
		rowEl.addEventListener( "drop", function ( e ) {
			e.preventDefault();
			rowEl.classList.remove( "drop" );
			var from = parseInt( e.dataTransfer.getData( "text/plain" ), 10 );
			if ( isNaN( from ) || from === idx ) { return; }
			var moved = values[ key ].splice( from, 1 )[ 0 ];
			values[ key ].splice( idx, 0, moved );
			setValue( key, values[ key ] );
			rerender();
		} );
		return rowEl;
	}

	function repeaterFor( key, def ) {
		var box = el( "div", { class: "lp-repeater" } );
		function rerender() {
			box.innerHTML = "";
			( values[ key ] || [] ).forEach( function ( row, idx ) {
				box.appendChild( repeaterRow( key, def, row, idx, rerender ) );
			} );
			box.appendChild( el( "button", {
				class: "lp-row-add", type: "button", text: "+ Add row",
				onclick: function () {
					var blank = {};
					def.subs.forEach( function ( sub ) { blank[ sub.key ] = ""; } );
					values[ key ] = values[ key ] || [];
					values[ key ].push( blank );
					setValue( key, values[ key ] );
					rerender();
				},
			} ) );
		}
		rerender();
		return box;
	}

	/* ---------- global panels: design tokens + menus ---------- */
	var globals = B.globals || { design: {}, nav: [], footer: {} };
	var globalsDirty = {};

	function markGlobalDirty( key ) {
		globalsDirty[ key ] = true;
		dirty = true;
		document.getElementById( "lp-save" ).classList.add( "is-dirty" );
	}
	function sendRaw( msg ) {
		try { frame.contentWindow.postMessage( msg, B.frontend ); } catch ( e ) {}
	}

	/*
	 * The Design panel.
	 *
	 * This used to be a radius slider and two colour pickers labelled "Gold
	 * accent" (#e3c257) and "Primary accent" (#e6cb4e) — two near-identical
	 * yellows matching no token the site actually uses, writing an option no
	 * part of the frontend ever read. Moving them changed nothing, and Save
	 * said it worked.
	 *
	 * Now it edits the real custom properties from the frontend's globals.css,
	 * the list arrives from PHP (`livepress_design_tokens()`), and the frontend
	 * both applies them live over postMessage and renders them server-side from
	 * the saved option. Radius is gone: there is no radius token to drive.
	 */
	function designSection() {
		var d = globals.design || {};
		globals.design = d;
		var tokens = B.designTokens || [];
		var box = el( "div", { class: "lp-fields" } );
		var rows = [];

		function push() {
			sendRaw( { type: "aux-design", tokens: d } );
			markGlobalDirty( "design" );
		}

		/* A saved value, or the brand default when nothing is overridden. */
		function current( t ) {
			return /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test( d[ t.key ] || "" ) ? d[ t.key ] : t.fallback;
		}

		tokens.forEach( function ( t ) {
			var swatch = el( "input", { class: "lp-swatch", type: "color", "aria-label": t.label } );
			var hex = el( "input", {
				class: "lp-input lp-hex",
				type: "text",
				spellcheck: "false",
				maxlength: "7",
				"aria-label": t.label + " hex value",
			} );
			var reset = el( "button", {
				class: "lp-mini",
				type: "button",
				text: "Reset",
				title: "Back to the brand value, " + t.fallback,
			} );

			function paint( value, alsoHex ) {
				swatch.value = value;
				if ( alsoHex ) { hex.value = value; }
				/* An override equal to the brand value is not an override. Storing
				   it would emit a rule that changes nothing and make the screen
				   claim a change it did not make. */
				if ( value.toLowerCase() === t.fallback.toLowerCase() ) {
					delete d[ t.key ];
				} else {
					d[ t.key ] = value;
				}
				row.classList.toggle( "is-changed", Boolean( d[ t.key ] ) );
			}

			swatch.value = current( t );
			hex.value = current( t );

			swatch.addEventListener( "input", function () {
				paint( swatch.value, true );
				push();
			} );

			/* Typed hex only takes effect once it is a colour, so the preview does
			   not flash through #f, #ff, #ff0 on the way to #ff0000. */
			hex.addEventListener( "input", function () {
				var v = hex.value.trim();
				if ( v && v.charAt( 0 ) !== "#" ) { v = "#" + v; }
				if ( ! /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test( v ) ) {
					hex.classList.add( "is-invalid" );
					return;
				}
				hex.classList.remove( "is-invalid" );
				paint( v, false );
				push();
			} );
			hex.addEventListener( "blur", function () {
				hex.classList.remove( "is-invalid" );
				hex.value = current( t );
			} );

			reset.addEventListener( "click", function () {
				paint( t.fallback, true );
				push();
			} );

			var row = el( "div", { class: "lp-field lp-token" + ( d[ t.key ] ? " is-changed" : "" ) }, [
				el( "label", { class: "lp-label", text: t.label } ),
				el( "div", { class: "lp-token-row" }, [ swatch, hex, reset ] ),
				el( "p", { class: "lp-hint", text: t.hint } ),
			] );
			rows.push( { def: t, paint: paint, row: row } );
			box.appendChild( row );
		} );

		if ( ! tokens.length ) {
			box.appendChild( el( "p", { class: "lp-hint", text: "No design tokens are exposed." } ) );
			return box;
		}

		box.appendChild( el( "div", { class: "lp-token-foot" }, [
			el( "button", {
				class: "lp-mini danger",
				type: "button",
				text: "Reset all to brand",
				onclick: function () {
					if ( ! window.confirm( "Put every colour back to the brand default?" ) ) { return; }
					rows.forEach( function ( r ) { r.paint( r.def.fallback, true ); } );
					push();
				},
			} ),
		] ) );

		return box;
	}

	/* ---------- section-order panel ---------- */
	function orderSection() {
		var blocks = B.schema.blocks || [];
		var labels = {};
		blocks.forEach( function ( b ) { labels[ b.key ] = b.label; } );

		function currentOrder() {
			var saved = String( values.section_order || "" ).split( "\n" ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
			var known = blocks.map( function ( b ) { return b.key; } );
			var out = saved.filter( function ( k ) { return known.indexOf( k ) !== -1; } );
			known.forEach( function ( k ) { if ( out.indexOf( k ) === -1 ) { out.push( k ); } } );
			return out;
		}
		function apply( order ) {
			values.section_order = order.join( "\n" );
			dirty = true;
			document.getElementById( "lp-save" ).classList.add( "is-dirty" );
			try {
				frame.contentWindow.postMessage( { type: "aux-edit", path: "sectionOrder", value: order }, B.frontend );
			} catch ( e ) {}
		}

		var box = el( "div", { class: "lp-fields" } );
		var list = el( "div", { class: "lp-repeater" } );
		function rerender() {
			list.innerHTML = "";
			currentOrder().forEach( function ( key, idx ) {
				var handle = el( "span", { class: "lp-drag", text: "⋮⋮", draggable: "true" } );
				handle.addEventListener( "dragstart", function ( e ) {
					e.dataTransfer.setData( "text/plain", String( idx ) );
				} );
				var rowEl = el( "div", { class: "lp-row lp-order-row" }, [
					handle,
					el( "span", { class: "lp-order-label", text: labels[ key ] || key } ),
				] );
				rowEl.addEventListener( "dragover", function ( e ) { e.preventDefault(); rowEl.classList.add( "drop" ); } );
				rowEl.addEventListener( "dragleave", function () { rowEl.classList.remove( "drop" ); } );
				rowEl.addEventListener( "drop", function ( e ) {
					e.preventDefault();
					rowEl.classList.remove( "drop" );
					var from = parseInt( e.dataTransfer.getData( "text/plain" ), 10 );
					if ( isNaN( from ) || from === idx ) { return; }
					var order = currentOrder();
					var moved = order.splice( from, 1 )[ 0 ];
					order.splice( idx, 0, moved );
					apply( order );
					rerender();
				} );
				list.appendChild( rowEl );
			} );
		}
		rerender();
		box.appendChild( list );
		return box;
	}

	/* ---------- sections panel ---------- */
	var MODE = B.mode || "page";
	function buildPanel() {
		var panel = el( "div", { class: "lp-sections" } );

		/* Which pinned panels this mode shows. Design has its own sidebar
		   screen; page editors carry only page concerns. There is no "menus"
		   mode any more — that screen redirects to the site-chrome document,
		   whose Navigation section is the nav the site actually renders. */
		var pinned = [];
		if ( MODE === "page" && ( B.schema.blocks || [] ).length ) {
			pinned.push( [ "Section order", orderSection ] );
		}
		if ( MODE === "design" ) {
			pinned.push( [ "Brand colours", designSection ] );
		}
		pinned.forEach( function ( g, gi ) {
			var body = g[ 1 ]();
			var head = el( "button", { class: "lp-sec-head", type: "button" }, [
				el( "span", { text: g[ 0 ] } ),
				el( "span", { class: "lp-caret", text: "▾" } ),
			] );
			// Standalone screens open their panels immediately.
			var open = MODE !== "page" || gi === -1 ? " open" : "";
			var sec = el( "div", { class: "lp-section lp-global" + open }, [ head, body ] );
			head.addEventListener( "click", function () { sec.classList.toggle( "open" ); } );
			panel.appendChild( sec );
		} );
		B.schema.sections.forEach( function ( section, i ) {
			var fields = el( "div", { class: "lp-fields" } );
			section.fields.forEach( function ( def ) {
				var control = def.kind === "repeater" ? repeaterFor( def.key, def ) : inputFor( def.key, def );
				var label = el( "label", { class: "lp-label", text: def.label } );
				var counter = def.kind === "repeater" ? null : attachCounter( def.key, control );
				if ( counter ) { label.appendChild( counter ); }
				var kids = [ label, control ];
				var wrap = el( "div", { class: "lp-field", "data-field": def.key } );
				/* An image field is a URL until you can see it — and until this
				   it was *only* a URL: no picker, no upload, so changing the
				   picture meant pasting an address found somewhere else. */
				if ( def.kind === "image" ) {
					var thumb = imagePreview( function () { return values[ def.key ]; } );
					control.addEventListener( "input", function () { thumb.refresh(); } );
					kids.push(
						mediaControls( function ( url ) {
							control.value = url;
							setValue( def.key, url );
							thumb.refresh();
						}, wrap ),
						thumb
					);
				}
				fields.appendChild( fill( wrap, kids ) );
			} );
			var st = sectionState( section );
			var head = el( "button", { class: "lp-sec-head", type: "button" }, [
				el( "span", { text: section.label } ),
				el( "span", { class: "lp-sec-right" }, [
					el( "span", { class: "lp-sec-count is-" + st.state, text: st.filled + "/" + st.total } ),
					el( "span", { class: "lp-caret", text: "▾" } )
				] ),
			] );
			var sec = el( "div", { class: "lp-section" + ( i === 0 ? " open" : "" ), "data-key": section.key }, [ head, fields ] );
			head.addEventListener( "click", function () { sec.classList.toggle( "open" ); } );
			panel.appendChild( sec );
		} );
		return panel;
	}

	/* ---------- save ---------- */
	function save() {
		checkConflict().then( function ( clash ) {
			if ( clash ) {
				var names = clash.fields.map( function ( c ) { return c.label; } ).join( ", " );
				var one = clash.fields.length === 1;
				if ( ! window.confirm(
					"Somebody else changed " + names + " after you opened this page.\n\n" +
					"Saving replaces their version of " + ( one ? "that field" : "those fields" ) +
					" with yours. Everything else you changed merges normally.\n\nContinue?"
				) ) { return; }
			}
			doSave();
		} );
	}

	/** One field's value in the shape WordPress stores it. */
	function metaValue( key ) {
		var def = fieldDef( key );
		return def && def.kind === "repeater"
			? JSON.stringify( values[ key ] || [] )
			: String( values[ key ] == null ? "" : values[ key ] );
	}

	/**
	 * The meta bag for the fields that actually moved.
	 *
	 * This used to be every field in the schema, changed or not — sixty-six
	 * writes to fix one word on the home page. That was not merely wasteful:
	 * it is how two people editing different sections overwrote each other.
	 * Whoever saved second wrote their editor's *loaded* value over the
	 * other's fresh one, and the only defence was a confirm dialog you could
	 * click through.
	 *
	 * Sending only what changed means edits to different fields merge on the
	 * server for free, and a real collision is now a collision on one field
	 * rather than on the whole document.
	 */
	function changedMeta() {
		var meta = {};
		changedFields().forEach( function ( c ) { meta[ c.key ] = metaValue( c.key ); } );
		return meta;
	}

	function doSave() {
		var btn = document.getElementById( "lp-save" );
		btn.textContent = "Saving…";
		var meta = changedMeta();
		var jobs = [];
		if ( B.postId && Object.keys( meta ).length ) {
			jobs.push( wp.apiFetch( { path: "/wp/v2/" + B.restBase + "/" + B.postId, method: "POST", data: { meta: meta } } ) );
		}
		Object.keys( globalsDirty ).forEach( function ( key ) {
			jobs.push( wp.apiFetch( {
				path: "/livepress/v1/option/" + key,
				method: "POST",
				data: globals[ key ],
			} ) );
		} );
		Promise.all( jobs )
			.then( function () {
				dirty = false;
				globalsDirty = {};
				/* Published, so this is the new baseline and the local copy has
				   nothing left to recover. */
				original = JSON.parse( JSON.stringify( values ) );
				clearDraft();
				refreshReviewCount();
				btn.classList.remove( "is-dirty" );
				btn.textContent = "Saved ✓";
				setTimeout( function () { btn.textContent = "Save"; }, 1600 );
			} )
			.catch( function ( err ) {
				btn.textContent = "Save failed";
				console.error( "LivePress save failed", err );
				setTimeout( function () { btn.textContent = "Save"; }, 2500 );
			} );
	}

	/* ---------- boot ---------- */
	/* Ctrl/Cmd+S saves. Without this the browser offers to save the HTML of
	   an editor that lives at a fullscreen admin URL, which is never what the
	   person pressing it wanted. */
	function bindShortcuts() {
		document.addEventListener( "keydown", function ( e ) {
			var mod = e.metaKey || e.ctrlKey;
			if ( mod && ( e.key === "s" || e.key === "S" ) ) {
				e.preventDefault();
				save();
			}
			if ( mod && ( e.key === "k" || e.key === "K" ) ) {
				e.preventDefault();
				openPalette();
			}
		} );
	}

	function boot() {
		var root = document.getElementById( "livepress-root" );
		frame = el( "iframe", { class: "lp-frame", src: B.frontend + B.path + ( B.path.indexOf( "?" ) === -1 ? "?edit=1" : "&edit=1" ) } );

		/*
		 * Device-size preview switcher: desktop / laptop / tablet / mobile.
		 *
		 * Drawn, not emoji. The four were 🖥 💻 ▯ 📱 — which render at a
		 * different size and colour in every font, ignore `color` so they
		 * never dimmed with the rest of the bar or lit with the accent when
		 * selected, and gave a screen reader "desktop computer" to read out
		 * where the button already says Desktop. ▯ is not even a device; it
		 * is a white vertical rectangle standing in for a tablet.
		 *
		 * One 16px grid, one stroke weight, `currentColor` throughout, so the
		 * set reads as one family and inherits every state the button has.
		 */
		var DEVICE_ICONS = {
			// Monitor on a stand.
			desktop: '<rect x="1" y="2.5" width="14" height="9" rx="1.4"/><path d="M6 14h4M8 11.5V14"/>',
			// Screen sitting on a wider base.
			laptop: '<rect x="2.5" y="3" width="11" height="7.5" rx="1.2"/><path d="M1 12.75h14"/>',
			// Portrait slab, home indicator.
			tablet: '<rect x="2.5" y="1.5" width="11" height="13" rx="1.6"/><path d="M7 12.4h2"/>',
			// Narrower portrait slab, earpiece above the screen.
			mobile: '<rect x="4.5" y="1" width="7" height="14" rx="1.8"/><path d="M6.9 3.1h2.2M7.3 13h1.4"/>',
		};

		function deviceIcon( name ) {
			return '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" '
				+ 'stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
				+ 'focusable="false">' + DEVICE_ICONS[ name ] + '</svg>';
		}

		var frameWrap = el( "div", { class: "lp-frame-wrap" }, [ frame ] );
		var deviceBar = el( "div", { class: "lp-devicebar", role: "group", "aria-label": "Preview width" } );
		[
			[ "desktop", "Desktop", "" ],
			[ "laptop", "Laptop", "1280px" ],
			[ "tablet", "Tablet", "834px" ],
			[ "mobile", "Mobile", "390px" ],
		].forEach( function ( d, i ) {
			var btn = el( "button", {
				class: "lp-device" + ( i === 0 ? " active" : "" ),
				type: "button",
				title: d[ 1 ] + ( d[ 2 ] ? " — " + d[ 2 ] : "" ),
				"aria-label": d[ 1 ],
				"aria-pressed": i === 0 ? "true" : "false",
				onclick: function () {
					deviceBar.querySelectorAll( ".lp-device" ).forEach( function ( b ) {
						b.classList.remove( "active" );
						b.setAttribute( "aria-pressed", "false" );
					} );
					btn.classList.add( "active" );
					btn.setAttribute( "aria-pressed", "true" );
					frame.style.width = d[ 2 ] || "100%";
					frameWrap.classList.toggle( "framed", !! d[ 2 ] );
				},
			} );
			/* innerHTML only for the icon: createElement cannot make SVG
			   nodes, and DEVICE_ICONS holds four literals defined above. The
			   label goes through el(), which sets textContent. */
			btn.innerHTML = deviceIcon( d[ 0 ] );
			btn.appendChild( el( "span", { class: "lp-device-label", text: d[ 1 ] } ) );
			deviceBar.appendChild( btn );
		} );

		var panelEl = el( "div", { class: "lp-panel" } );
		var grip = makeResizable( panelEl );
		root.appendChild( el( "div", { class: "lp-app" }, [
			fill( panelEl, [
				el( "div", { class: "lp-topbar" }, [
					el( "a", { class: "lp-back", href: B.backUrl, text: "←" } ),
					el( "div", { class: "lp-title" }, [
						el( "strong", { text: B.title } ),
						el( "span", { class: "lp-sub", text: "livepress · " + B.path } ),
					] ),
					/* Rank Math only renders its analysis on the classic screen, and
					   a Site Page always redirects here, so without this link the
					   marketer can never see a score for a page. */
					B.seoUrl ? el( "a", { class: "lp-mini", href: B.seoUrl, title: "Open Rank Math analysis for this page", text: "SEO" } ) : null,
					el( "button", { id: "lp-review", class: "lp-mini", type: "button", text: "Review", onclick: openReview } ),
					B.postId ? el( "button", { id: "lp-schedule", class: "lp-mini", type: "button", text: "Schedule", onclick: openSchedule } ) : null,
					el( "button", { id: "lp-save", class: "lp-save", type: "button", text: "Save", onclick: save } ),
				] ),
				el( "div", { id: "lp-bars" } ),
				buildPanel(),
			] ),
			grip,
			el( "div", { class: "lp-preview" }, [ deviceBar, frameWrap ] ),
		] ) );

		// Re-sync current (possibly unsaved) values whenever the page (re)loads.
		window.addEventListener( "message", function ( e ) {
			if ( ! e.data ) { return; }
			if ( e.data.type === "aux-edit-ready" ) {
				broadcastAll();
				/* Re-apply unsaved colour changes after any preview reload —
				   the frontend renders the *saved* tokens server-side, so
				   without this a reload silently reverts what is on screen to
				   the last save while the panel still shows the new value. */
				if ( globalsDirty.design ) { sendRaw( { type: "aux-design", tokens: globals.design } ); }
			}
			// Click-to-edit: clicking a section in the preview opens its panel.
			if ( e.data.type === "aux-focus" && e.data.section ) {
				var target = document.querySelector( '.lp-section[data-key="' + e.data.section + '"]' );
				if ( ! target ) { return; }
				document.querySelectorAll( ".lp-section.open" ).forEach( function ( s ) {
					if ( s !== target && ! s.classList.contains( "lp-global" ) ) { s.classList.remove( "open" ); }
				} );
				target.classList.add( "open", "flash" );
				target.scrollIntoView( { behavior: "smooth", block: "start" } );
				setTimeout( function () { target.classList.remove( "flash" ); }, 1400 );
			}
		} );
		window.addEventListener( "beforeunload", function ( e ) {
			if ( dirty ) { e.preventDefault(); e.returnValue = ""; }
		} );
		bindShortcuts();
		refreshReviewCount();
		offerRecovery();
		renderPendingBar();
		focusRequestedSection();
	}

	/*
	 * Open the section named in `?focus=`, if there is one.
	 *
	 * This is how the Menus item in the sidebar lands somewhere useful: it
	 * redirects to the site-chrome document, which carries seven sections, and
	 * without this the editor would open on Studio details and leave the
	 * navigation the person came for several scrolls down.
	 *
	 * Reuses the click-to-edit path rather than adding a second way to open a
	 * section, so the two cannot drift apart.
	 */
	function focusRequestedSection() {
		var key = new URLSearchParams( window.location.search ).get( "focus" );
		if ( ! key ) { return; }
		var target = document.querySelector( '.lp-section[data-key="' + key.replace( /[^\w-]/g, "" ) + '"]' );
		if ( ! target ) { return; }
		target.classList.add( "open", "flash" );
		target.scrollIntoView( { block: "start" } );
		setTimeout( function () { target.classList.remove( "flash" ); }, 1400 );
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", boot );
	} else {
		boot();
	}
})();
