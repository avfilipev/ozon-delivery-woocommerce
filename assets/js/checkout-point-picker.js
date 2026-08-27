/**
 * Выбор пункта выдачи Ozon на чекауте.
 *
 * Поиск идёт по локальному каталогу плагина: у Ozon нет метода поиска точек
 * по городу. Карта появится позже — сейчас это список с поиском.
 */
( function () {
	'use strict';

	var settings = window.ozonDeliveryPicker || {};

	function element( id ) {
		return document.getElementById( id );
	}

	function post( action, nonce, data ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', nonce );

		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function say( message ) {
		var status = element( 'ozon-delivery-status' );

		if ( status ) {
			status.textContent = message;
		}
	}

	function renderList( points ) {
		var list = element( 'ozon-delivery-points' );

		if ( ! list ) {
			return;
		}

		list.innerHTML = '';

		if ( ! points.length ) {
			say( settings.i18n.nothingFound );
			return;
		}

		say( '' );

		points.forEach( function ( point ) {
			var item = document.createElement( 'li' );
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'button ozon-delivery-point';
			button.dataset.pointId = point.id;
			button.textContent = point.name + ' — ' + point.address;

			item.appendChild( button );
			list.appendChild( item );
		} );
	}

	function choose( pointId ) {
		say( settings.i18n.saving );

		post( settings.chooseAction, settings.chooseNonce, {
			delivery_point_id: pointId,
		} )
			.then( function ( result ) {
				if ( ! result.success ) {
					say( result.data && result.data.message ? result.data.message : settings.i18n.failed );
					return;
				}

				say( settings.i18n.chosen + ' ' + result.data.point.address );

				// Пересчёт доставки: стоимость зависит от выбранной точки.
				document.body.dispatchEvent( new Event( 'update_checkout' ) );

				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger( 'update_checkout' );
				}
			} )
			.catch( function () {
				say( settings.i18n.failed );
			} );
	}

	function search() {
		var input = element( 'ozon-delivery-city' );

		if ( ! input || ! input.value.trim() ) {
			say( settings.i18n.enterCity );
			return;
		}

		say( settings.i18n.searching );

		post( settings.searchAction, settings.searchNonce, { city: input.value.trim() } )
			.then( function ( result ) {
				renderList( result.success ? result.data.points : [] );
			} )
			.catch( function () {
				say( settings.i18n.failed );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.target && event.target.id === 'ozon-delivery-search' ) {
			event.preventDefault();
			search();
			return;
		}

		if ( event.target && event.target.classList.contains( 'ozon-delivery-point' ) ) {
			event.preventDefault();
			choose( event.target.dataset.pointId );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Enter' && event.target && event.target.id === 'ozon-delivery-city' ) {
			event.preventDefault();
			search();
		}
	} );
}() );
