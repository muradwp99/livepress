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
	}

	/* ---------- field renderers ---------- */
	function inputFor( key, def ) {
		if ( def.kind === "textarea" || def.kind === "lines" ) {
			var ta = el( "textarea", { class: "lp-input", rows: def.kind === "lines" ? 5 : 3 } );
			ta.value = values[ key ] || "";
			ta.addEventListener( "input", function () { setValue( key, ta.value ); } );
			return ta;
		}
		var input = el( "input", { class: "lp-input", type: "text" } );
		input.value = values[ key ] || "";
		input.addEventListener( "input", function () { setValue( key, input.value ); } );
		return input;
	}

	function mediaButton( assign ) {
		return el( "button", {
			class: "lp-media-btn", type: "button", title: "Choose from Media Library",
			text: "🖼",
			onclick: function () {
				var picker = wp.media( { title: "Choose image", multiple: false, library: { type: "image" } } );
				picker.on( "select", function () {
					var att = picker.state().get( "selection" ).first().toJSON();
					assign( att.url );
				} );
				picker.open();
			},
		} );
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
			var inner = [ el( "label", { class: "lp-sublabel", text: sub.label } ), field ];
			if ( sub.kind === "image" ) {
				var pair = el( "div", { class: "lp-media-pair" }, [ field, mediaButton( function ( url ) {
					field.value = url;
					row[ sub.key ] = url;
					setValue( key, values[ key ] );
				} ) ] );
				inner = [ el( "label", { class: "lp-sublabel", text: sub.label } ), pair ];
			}
			body.appendChild( el( "div", { class: wrapCls }, inner ) );
		} );

		var remove = el( "button", {
			class: "lp-row-del", type: "button", text: "✕", title: "Remove row",
			onclick: function () {
				values[ key ].splice( idx, 1 );
				setValue( key, values[ key ] );
				rerender();
			},
		} );

		var rowEl = el( "div", { class: "lp-row", "data-idx": String( idx ) }, [ handle, body, remove ] );
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

	function designSection() {
		var d = globals.design || {};
		globals.design = d;
		var fields = el( "div", { class: "lp-fields" } );

		function push() {
			sendRaw( { type: "aux-design", tokens: d } );
			markGlobalDirty( "design" );
		}
		// Border radius slider.
		var radiusVal = el( "span", { class: "lp-range-val", text: ( d.radius || "12" ) + "px" } );
		var radius = el( "input", { class: "lp-range", type: "range", min: "0", max: "32", step: "1" } );
		radius.value = d.radius || "12";
		radius.addEventListener( "input", function () {
			d.radius = radius.value;
			radiusVal.textContent = radius.value + "px";
			push();
		} );
		fields.appendChild( el( "div", { class: "lp-field" }, [
			el( "label", { class: "lp-label", text: "Border radius" } ),
			el( "div", { class: "lp-range-row" }, [ radius, radiusVal ] ),
		] ) );
		// Brand colors.
		[ [ "gold", "Gold accent", "#e3c257" ], [ "lime", "Primary accent", "#e6cb4e" ] ].forEach( function ( c ) {
			var key = c[ 0 ];
			var picker = el( "input", { class: "lp-color", type: "color" } );
			picker.value = d[ key ] || c[ 2 ];
			picker.addEventListener( "input", function () {
				d[ key ] = picker.value;
				push();
			} );
			fields.appendChild( el( "div", { class: "lp-field" }, [
				el( "label", { class: "lp-label", text: c[ 1 ] } ),
				picker,
			] ) );
		} );
		return fields;
	}

	function menuSection() {
		var box = el( "div", { class: "lp-fields" } );
		var list = el( "div", { class: "lp-repeater" } );

		function push() {
			sendRaw( { type: "aux-menu", nav: globals.nav } );
			markGlobalDirty( "nav" );
		}
		function rerender() {
			list.innerHTML = "";
			globals.nav.forEach( function ( row, idx ) {
				var handle = el( "span", { class: "lp-drag", text: "⋮⋮", draggable: "true" } );
				handle.addEventListener( "dragstart", function ( e ) {
					e.dataTransfer.setData( "text/plain", String( idx ) );
				} );
				var label = el( "input", { class: "lp-input", type: "text" } );
				label.value = row.label || row.key;
				label.addEventListener( "input", function () {
					row.label = label.value;
					push();
				} );
				var visible = el( "button", {
					class: "lp-eye" + ( row.visible === 0 || row.visible === false ? " off" : "" ),
					type: "button", text: row.visible === 0 || row.visible === false ? "🚫" : "👁",
					title: "Show / hide",
					onclick: function () {
						row.visible = ( row.visible === 0 || row.visible === false ) ? 1 : 0;
						push();
						rerender();
					},
				} );
				var rowEl = el( "div", { class: "lp-row lp-nav-row" }, [
					handle,
					el( "span", { class: "lp-nav-key", text: row.key } ),
					label,
					visible,
				] );
				rowEl.addEventListener( "dragover", function ( e ) { e.preventDefault(); rowEl.classList.add( "drop" ); } );
				rowEl.addEventListener( "dragleave", function () { rowEl.classList.remove( "drop" ); } );
				rowEl.addEventListener( "drop", function ( e ) {
					e.preventDefault();
					rowEl.classList.remove( "drop" );
					var from = parseInt( e.dataTransfer.getData( "text/plain" ), 10 );
					if ( isNaN( from ) || from === idx ) { return; }
					var moved = globals.nav.splice( from, 1 )[ 0 ];
					globals.nav.splice( idx, 0, moved );
					push();
					rerender();
				} );
				list.appendChild( rowEl );
			} );
		}
		rerender();
		box.appendChild( list );
		return box;
	}

	function footerSection() {
		var f = globals.footer || {};
		globals.footer = f;
		f.columns = f.columns || [];
		var box = el( "div", { class: "lp-fields" } );

		function push() {
			sendRaw( { type: "aux-footer", footer: f } );
			markGlobalDirty( "footer" );
		}
		var blurb = el( "textarea", { class: "lp-input", rows: 2 } );
		blurb.value = f.blurb || "";
		blurb.addEventListener( "input", function () { f.blurb = blurb.value; push(); } );
		box.appendChild( el( "div", { class: "lp-field" }, [
			el( "label", { class: "lp-label", text: "Blurb" } ), blurb,
		] ) );

		var colsBox = el( "div", { class: "lp-repeater" } );
		function rerender() {
			colsBox.innerHTML = "";
			f.columns.forEach( function ( col, ci ) {
				col.links = col.links || [];
				var title = el( "input", { class: "lp-input", type: "text" } );
				title.value = col.title || "";
				title.addEventListener( "input", function () { col.title = title.value; push(); } );

				var linksBox = el( "div", { class: "lp-links" } );
				col.links.forEach( function ( link, li ) {
					var lab = el( "input", { class: "lp-input", type: "text", placeholder: "Label" } );
					lab.value = link.label || "";
					lab.addEventListener( "input", function () { link.label = lab.value; push(); } );
					var href = el( "input", { class: "lp-input", type: "text", placeholder: "/path" } );
					href.value = link.href || "";
					href.addEventListener( "input", function () { link.href = href.value; push(); } );
					var up = el( "button", { class: "lp-mini", type: "button", text: "↑", onclick: function () {
						if ( li === 0 ) { return; }
						col.links.splice( li - 1, 0, col.links.splice( li, 1 )[ 0 ] );
						push(); rerender();
					} } );
					var down = el( "button", { class: "lp-mini", type: "button", text: "↓", onclick: function () {
						if ( li >= col.links.length - 1 ) { return; }
						col.links.splice( li + 1, 0, col.links.splice( li, 1 )[ 0 ] );
						push(); rerender();
					} } );
					var del = el( "button", { class: "lp-mini danger", type: "button", text: "✕", onclick: function () {
						col.links.splice( li, 1 );
						push(); rerender();
					} } );
					linksBox.appendChild( el( "div", { class: "lp-link-row" }, [ lab, href, up, down, del ] ) );
				} );
				linksBox.appendChild( el( "button", { class: "lp-row-add", type: "button", text: "+ Add link", onclick: function () {
					col.links.push( { label: "", href: "" } );
					push(); rerender();
				} } ) );

				var delCol = el( "button", { class: "lp-row-del", type: "button", text: "✕", title: "Remove column", onclick: function () {
					f.columns.splice( ci, 1 );
					push(); rerender();
				} } );
				colsBox.appendChild( el( "div", { class: "lp-col" }, [
					el( "div", { class: "lp-col-head" }, [
						el( "label", { class: "lp-sublabel", text: "Column title" } ), delCol,
					] ),
					title, linksBox,
				] ) );
			} );
			colsBox.appendChild( el( "button", { class: "lp-row-add", type: "button", text: "+ Add column", onclick: function () {
				f.columns.push( { title: "", links: [] } );
				push(); rerender();
			} } ) );
		}
		rerender();
		box.appendChild( el( "div", { class: "lp-field" }, [
			el( "label", { class: "lp-label", text: "Columns" } ), colsBox,
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

		// Which pinned panels this mode shows. Menus/Design live in their own
		// sidebar screens now; page editors carry only page concerns.
		var pinned = [];
		if ( MODE === "page" && ( B.schema.blocks || [] ).length ) {
			pinned.push( [ "≡ Section order", orderSection ] );
		}
		if ( MODE === "design" ) {
			pinned.push( [ "🎨 Design", designSection ] );
		}
		if ( MODE === "menus" ) {
			pinned.push( [ "☰ Main menu", menuSection ] );
			pinned.push( [ "▤ Footer menu", footerSection ] );
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
				fields.appendChild( el( "div", { class: "lp-field" }, [
					el( "label", { class: "lp-label", text: def.label } ),
					def.kind === "repeater" ? repeaterFor( def.key, def ) : inputFor( def.key, def ),
				] ) );
			} );
			var head = el( "button", { class: "lp-sec-head", type: "button" }, [
				el( "span", { text: section.label } ),
				el( "span", { class: "lp-caret", text: "▾" } ),
			] );
			var sec = el( "div", { class: "lp-section" + ( i === 0 ? " open" : "" ), "data-key": section.key }, [ head, fields ] );
			head.addEventListener( "click", function () { sec.classList.toggle( "open" ); } );
			panel.appendChild( sec );
		} );
		return panel;
	}

	/* ---------- save ---------- */
	function save() {
		var btn = document.getElementById( "lp-save" );
		btn.textContent = "Saving…";
		var meta = { section_order: String( values.section_order || "" ) };
		B.schema.sections.forEach( function ( s ) {
			s.fields.forEach( function ( f ) {
				meta[ f.key ] = f.kind === "repeater"
					? JSON.stringify( values[ f.key ] || [] )
					: String( values[ f.key ] == null ? "" : values[ f.key ] );
			} );
		} );
		var jobs = [];
		if ( B.postId ) {
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
	function boot() {
		var root = document.getElementById( "livepress-root" );
		frame = el( "iframe", { class: "lp-frame", src: B.frontend + B.path + ( B.path.indexOf( "?" ) === -1 ? "?edit=1" : "&edit=1" ) } );

		// Device-size preview switcher: desktop / laptop / tablet / mobile.
		var frameWrap = el( "div", { class: "lp-frame-wrap" }, [ frame ] );
		var deviceBar = el( "div", { class: "lp-devicebar" } );
		[
			[ "🖥", "Desktop", "" ],
			[ "💻", "Laptop", "1280px" ],
			[ "▯", "Tablet", "834px" ],
			[ "📱", "Mobile", "390px" ],
		].forEach( function ( d, i ) {
			var btn = el( "button", {
				class: "lp-device" + ( i === 0 ? " active" : "" ),
				type: "button", text: d[ 0 ], title: d[ 1 ],
				onclick: function () {
					deviceBar.querySelectorAll( ".lp-device" ).forEach( function ( b ) { b.classList.remove( "active" ); } );
					btn.classList.add( "active" );
					frame.style.width = d[ 2 ] || "100%";
					frameWrap.classList.toggle( "framed", !! d[ 2 ] );
				},
			} );
			deviceBar.appendChild( btn );
		} );

		root.appendChild( el( "div", { class: "lp-app" }, [
			el( "div", { class: "lp-panel" }, [
				el( "div", { class: "lp-topbar" }, [
					el( "a", { class: "lp-back", href: B.backUrl, text: "←" } ),
					el( "div", { class: "lp-title" }, [
						el( "strong", { text: B.title } ),
						el( "span", { class: "lp-sub", text: "livepress · " + B.path } ),
					] ),
					el( "button", { id: "lp-save", class: "lp-save", type: "button", text: "Save", onclick: save } ),
				] ),
				buildPanel(),
			] ),
			el( "div", { class: "lp-preview" }, [ deviceBar, frameWrap ] ),
		] ) );

		// Re-sync current (possibly unsaved) values whenever the page (re)loads.
		window.addEventListener( "message", function ( e ) {
			if ( ! e.data ) { return; }
			if ( e.data.type === "aux-edit-ready" ) {
				broadcastAll();
				// Re-apply unsaved global edits after any preview reload.
				if ( globalsDirty.design ) { sendRaw( { type: "aux-design", tokens: globals.design } ); }
				if ( globalsDirty.nav ) { sendRaw( { type: "aux-menu", nav: globals.nav } ); }
				if ( globalsDirty.footer ) { sendRaw( { type: "aux-footer", footer: globals.footer } ); }
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
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", boot );
	} else {
		boot();
	}
})();
