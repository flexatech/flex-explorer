/* Flex Explorer - read-only browser. Uses the jQuery bundled with WordPress. */
( function ( $ ) {
	'use strict';

	var cfg = window.flexExplorer || {};
	var i18n = cfg.i18n || {};

	var $crumbs = $( '.flex-explorer__crumbs' );
	var $list = $( '.flex-explorer__list' );
	var $viewer = $( '.flex-explorer__viewer' );
	var $searchInput = $( '.flex-explorer__search-input' );
	var $searchClear = $( '.flex-explorer__search-clear' );
	var $zip = $( '.flex-explorer__zip' );

	var currentPath = '';
	var searchTimer = null;

	/**
	 * Format a byte count into a short human string. Output is plain numbers
	 * and ASCII units, so it is inserted with .text() either way.
	 */
	function formatSize( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
		i = Math.min( i, units.length - 1 );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i ? 1 : 0 ) + ' ' + units[ i ];
	}

	function formatDate( unix ) {
		var d = new Date( unix * 1000 );
		return d.toLocaleString();
	}

	function post( action, path, extra ) {
		return $.post( cfg.ajaxUrl, $.extend( {
			action: action,
			nonce: cfg.nonce,
			path: path
		}, extra || {} ) );
	}

	/**
	 * Build a GET URL for a download endpoint. The nonce rides in the query
	 * string; the server validates it the same way as the POST handlers.
	 */
	function fileUrl( action, path ) {
		return cfg.ajaxUrl +
			'?action=' + encodeURIComponent( action ) +
			'&nonce=' + encodeURIComponent( cfg.nonce ) +
			'&path=' + encodeURIComponent( path );
	}

	/**
	 * Kick off a download in a throwaway iframe so a server-side error (e.g. a
	 * folder too big to zip) lands there instead of navigating this page away.
	 */
	function triggerDownload( url ) {
		var iframe = document.createElement( 'iframe' );
		iframe.style.display = 'none';
		iframe.src = url;
		document.body.appendChild( iframe );
		window.setTimeout( function () {
			document.body.removeChild( iframe );
		}, 60000 );
	}

	/**
	 * Render the breadcrumb from the current relative path. Every label goes
	 * in via .text(), so nothing here can inject markup.
	 */
	function renderCrumbs( path ) {
		$crumbs.empty();

		var $root = $( '<button type="button"></button>' ).text( cfg.root || 'root' );
		$root.on( 'click', function () {
			loadDir( '' );
		} );
		$crumbs.append( $root );

		if ( ! path ) {
			$root.replaceWith( $( '<span class="flex-explorer__current"></span>' ).text( cfg.root || 'root' ) );
			return;
		}

		var parts = path.split( '/' );
		var acc = '';

		parts.forEach( function ( part, idx ) {
			acc = acc ? acc + '/' + part : part;
			$crumbs.append( $( '<span class="flex-explorer__sep"></span>' ).text( '/' ) );

			if ( idx === parts.length - 1 ) {
				$crumbs.append( $( '<span class="flex-explorer__current"></span>' ).text( part ) );
			} else {
				var target = acc;
				var $btn = $( '<button type="button"></button>' ).text( part );
				$btn.on( 'click', function () {
					loadDir( target );
				} );
				$crumbs.append( $btn );
			}
		} );
	}

	function msg( text ) {
		return $( '<p class="flex-explorer__msg"></p>' ).text( text );
	}

	function renderList( data ) {
		$list.empty();

		if ( ! data.entries.length ) {
			$list.append( msg( i18n.empty ) );
			return;
		}

		var $table = $( '<table class="flex-explorer__table"></table>' );
		var $head = $( '<tr></tr>' );
		$head.append( $( '<th></th>' ).text( i18n.name ) );
		$head.append( $( '<th></th>' ).text( i18n.size ) );
		$head.append( $( '<th></th>' ).text( i18n.modified ) );
		$table.append( $( '<thead></thead>' ).append( $head ) );

		var $body = $( '<tbody></tbody>' );

		data.entries.forEach( function ( entry ) {
			var $row = $( '<tr class="flex-explorer__row"></tr>' );
			$row.addClass( 'dir' === entry.type ? 'flex-explorer__row--dir' : 'flex-explorer__row--file' );

			var $name = $( '<td class="flex-explorer__col-name"></td>' );
			var $icon = $( '<span class="flex-explorer__icon dashicons"></span>' );
			$icon.addClass( 'dir' === entry.type ? 'dashicons-category' : 'dashicons-media-default' );
			$name.append( $icon ).append( $( '<span></span>' ).text( entry.name ) );
			$row.append( $name );

			$row.append( $( '<td></td>' ).text( 'dir' === entry.type ? i18n.folder : formatSize( entry.size ) ) );
			$row.append( $( '<td></td>' ).text( formatDate( entry.modified ) ) );

			$row.on( 'click', function () {
				if ( 'dir' === entry.type ) {
					loadDir( entry.path );
				} else {
					$list.find( '.flex-explorer__row' ).removeClass( 'is-active' );
					$row.addClass( 'is-active' );
					loadFile( entry.path );
				}
			} );

			$body.append( $row );
		} );

		$table.append( $body );
		$list.append( $table );
	}

	function loadDir( path ) {
		exitSearch();
		$list.empty().append( msg( i18n.loading ) );
		currentPath = path;
		renderCrumbs( path );

		post( 'flex_explorer_list', path )
			.done( function ( res ) {
				if ( res && res.success ) {
					currentPath = res.data.path;
					renderCrumbs( currentPath );
					renderList( res.data );
				} else {
					$list.empty().append( msg( ( res && res.data && res.data.message ) || i18n.error ) );
				}
			} )
			.fail( function () {
				$list.empty().append( msg( i18n.error ) );
			} );
	}

	function viewerHead( data ) {
		var $head = $( '<div class="flex-explorer__viewer-head"></div>' );
		$head.append( $( '<span class="flex-explorer__viewer-name"></span>' ).text( data.name ) );
		$head.append( $( '<span></span>' ).text( formatSize( data.size ) ) );

		// Download link. The filename is set via the download attribute and
		// also enforced server-side in the Content-Disposition header.
		var $dl = $( '<a class="button flex-explorer__download"></a>' )
			.text( i18n.download || 'Download' )
			.attr( 'href', fileUrl( 'flex_explorer_download', data.path ) )
			.attr( 'download', data.name );
		$head.append( $dl );

		return $head;
	}

	function loadFile( path ) {
		$viewer.empty().append( msg( i18n.loading ) );

		post( 'flex_explorer_read', path )
			.done( function ( res ) {
				$viewer.empty();

				if ( ! res || ! res.success ) {
					$viewer.append( msg( ( res && res.data && res.data.message ) || i18n.error ) );
					return;
				}

				var data = res.data;
				$viewer.append( viewerHead( data ) );

				if ( 'text' === data.kind ) {
					// .text() escapes the content; nothing is rendered as HTML.
					$viewer.append( $( '<pre></pre>' ).text( data.content ) );
				} else if ( 'image' === data.kind ) {
					$viewer.append( $( '<img alt="" />' ).attr( 'src', data.dataUri ) );
				} else if ( 'toolarge' === data.kind ) {
					$viewer.append( msg( i18n.tooLarge ) );
				} else {
					$viewer.append( msg( i18n.notViewable ) );
				}
			} )
			.fail( function () {
				$viewer.empty().append( msg( i18n.error ) );
			} );
	}

	/**
	 * Render search hits. Each row shows the full sandbox-relative path (via
	 * .text(), so still no markup injection) so matches are unambiguous.
	 */
	function renderSearchResults( data ) {
		$list.empty();
		$list.append( $( '<p class="flex-explorer__search-info"></p>' ).text( i18n.searchHint || '' ) );

		if ( ! data.entries.length ) {
			$list.append( msg( i18n.noMatches ) );
			return;
		}

		if ( data.capped ) {
			$list.append( msg( i18n.capped ) );
		}

		var $table = $( '<table class="flex-explorer__table"></table>' );
		var $body = $( '<tbody></tbody>' );

		data.entries.forEach( function ( entry ) {
			var $row = $( '<tr class="flex-explorer__row"></tr>' );
			$row.addClass( 'dir' === entry.type ? 'flex-explorer__row--dir' : 'flex-explorer__row--file' );

			var $name = $( '<td class="flex-explorer__col-name"></td>' );
			var $icon = $( '<span class="flex-explorer__icon dashicons"></span>' );
			$icon.addClass( 'dir' === entry.type ? 'dashicons-category' : 'dashicons-media-default' );
			$name.append( $icon ).append( $( '<span></span>' ).text( entry.path ) );
			$row.append( $name );

			$row.append( $( '<td></td>' ).text( 'dir' === entry.type ? i18n.folder : formatSize( entry.size ) ) );
			$row.append( $( '<td></td>' ).text( formatDate( entry.modified ) ) );

			$row.on( 'click', function () {
				if ( 'dir' === entry.type ) {
					loadDir( entry.path );
				} else {
					loadFile( entry.path );
				}
			} );

			$body.append( $row );
		} );

		$table.append( $body );
		$list.append( $table );
	}

	function runSearch( query ) {
		$searchClear.prop( 'hidden', false );
		$list.empty().append( msg( i18n.loading ) );

		post( 'flex_explorer_search', currentPath, { query: query } )
			.done( function ( res ) {
				if ( res && res.success ) {
					renderSearchResults( res.data );
				} else {
					$list.empty().append( msg( ( res && res.data && res.data.message ) || i18n.error ) );
				}
			} )
			.fail( function () {
				$list.empty().append( msg( i18n.error ) );
			} );
	}

	// Drop out of search mode. Called whenever we navigate to a real folder.
	function exitSearch() {
		if ( searchTimer ) {
			window.clearTimeout( searchTimer );
			searchTimer = null;
		}
		$searchInput.val( '' );
		$searchClear.prop( 'hidden', true );
	}

	$( function () {
		loadDir( '' );

		$searchInput.on( 'input', function () {
			var query = ( $searchInput.val() || '' ).trim();

			if ( searchTimer ) {
				window.clearTimeout( searchTimer );
				searchTimer = null;
			}

			if ( ! query ) {
				loadDir( currentPath );
				return;
			}

			searchTimer = window.setTimeout( function () {
				runSearch( query );
			}, 300 );
		} );

		$searchClear.on( 'click', function () {
			loadDir( currentPath );
		} );

		$zip.on( 'click', function () {
			triggerDownload( fileUrl( 'flex_explorer_zip', currentPath ) );
		} );
	} );
} )( jQuery );
