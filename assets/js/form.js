/**
 * Lead Forms — front-end behaviour.
 *
 * Progressive enhancement only: the markup is a real <form> that posts to
 * admin-post.php, and this script merely intercepts the submit event so the
 * page does not have to reload. If the script fails to load, the form still
 * works.
 *
 * No build step and no dependencies — it ships as-is.
 */
( function () {
	'use strict';

	var config = window.leadFormsConfig || {};
	var strings = config.i18n || {};

	/**
	 * Find every field's error slot and empty it.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function clearErrors( form ) {
		form.querySelectorAll( '[data-lf-error]' ).forEach( function ( node ) {
			node.textContent = '';
		} );

		form.querySelectorAll( '[data-lf-field]' ).forEach( function ( node ) {
			node.classList.remove( 'has-error' );
		} );

		form.querySelectorAll( '[aria-invalid="true"]' ).forEach( function ( node ) {
			node.removeAttribute( 'aria-invalid' );
		} );
	}

	/**
	 * Paint server-side validation errors next to their fields.
	 *
	 * @param {HTMLFormElement} form   The form element.
	 * @param {Object}          errors Field key => message.
	 */
	function showErrors( form, errors ) {
		var firstInvalid = null;

		Object.keys( errors || {} ).forEach( function ( key ) {
			var wrapper = form.querySelector( '[data-lf-field="' + CSS.escape( key ) + '"]' );

			if ( ! wrapper ) {
				return;
			}

			var slot = wrapper.querySelector( '[data-lf-error]' );

			if ( slot ) {
				slot.textContent = errors[ key ];
			}

			wrapper.classList.add( 'has-error' );

			var control = wrapper.querySelector( 'input, select, textarea' );

			if ( control ) {
				control.setAttribute( 'aria-invalid', 'true' );

				if ( ! firstInvalid ) {
					firstInvalid = control;
				}
			}
		} );

		// Move focus to the first problem so keyboard and screen-reader users
		// are not left guessing what changed.
		if ( firstInvalid ) {
			firstInvalid.focus( { preventScroll: false } );
		}
	}

	/**
	 * Update the live region under the form.
	 *
	 * @param {HTMLFormElement} form    The form element.
	 * @param {string}          message Text to announce.
	 * @param {boolean}         success Whether this is a success message.
	 */
	function setStatus( form, message, success ) {
		var status = form.querySelector( '[data-lf-status]' );

		if ( ! status ) {
			return;
		}

		status.textContent = message;
		status.classList.toggle( 'is-visible', Boolean( message ) );
		status.classList.toggle( 'lf-status--success', Boolean( success ) );
		status.classList.toggle( 'lf-status--error', Boolean( message ) && ! success );
	}

	/**
	 * Toggle the busy state of the form and its submit button.
	 *
	 * @param {HTMLFormElement} form The form element.
	 * @param {boolean}         busy Whether a request is in flight.
	 */
	function setBusy( form, busy ) {
		var button = form.querySelector( '[data-lf-submit]' );

		form.classList.toggle( 'is-busy', busy );
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );

		if ( button ) {
			button.disabled = busy;
		}
	}

	/**
	 * Ask the server for a fresh nonce.
	 *
	 * Full-page caches can serve a form whose embedded nonce has already
	 * expired, so one is fetched right before submitting.
	 *
	 * @param {string} formId The form ID.
	 * @return {Promise<string|null>} The nonce, or null when unavailable.
	 */
	function refreshNonce( formId ) {
		if ( ! config.tokenUrl ) {
			return Promise.resolve( null );
		}

		var url = config.tokenUrl + ( config.tokenUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'form_id=' + encodeURIComponent( formId );

		return fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				return data && data.nonce ? data.nonce : null;
			} )
			.catch( function () {
				// A failed refresh is not fatal: the embedded nonce may still
				// be valid, so fall through and let the server decide.
				return null;
			} );
	}

	/**
	 * Send the form and act on the response.
	 *
	 * @param {HTMLFormElement} form    The form element.
	 * @param {boolean}         isRetry Whether this is the single allowed retry.
	 * @return {Promise<void>}
	 */
	function submit( form, isRetry ) {
		var formId = form.getAttribute( 'data-form-id' );

		return refreshNonce( formId )
			.then( function ( nonce ) {
				var nonceInput = form.querySelector( '[data-lf-nonce]' );

				if ( nonce && nonceInput ) {
					nonceInput.value = nonce;
				}

				return fetch( config.submitUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { Accept: 'application/json' },
					body: new FormData( form ),
				} );
			} )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return null;
					} )
					.then( function ( data ) {
						return { status: response.status, data: data };
					} );
			} )
			.then( function ( result ) {
				var data = result.data || {};

				// An expired nonce is worth exactly one silent retry.
				if ( result.status === 403 && ! isRetry ) {
					return submit( form, true );
				}

				if ( result.status >= 200 && result.status < 300 && data.success ) {
					if ( data.redirect ) {
						window.location.assign( data.redirect );
						return;
					}

					form.classList.add( 'is-sent' );
					setStatus( form, data.message || '', true );
					form.reset();

					// Announce the outcome to assistive tech, then park focus
					// somewhere sensible.
					var status = form.querySelector( '[data-lf-status]' );

					if ( status ) {
						status.setAttribute( 'tabindex', '-1' );
						status.focus( { preventScroll: true } );
						status.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}

					return;
				}

				if ( data.errors && Object.keys( data.errors ).length ) {
					showErrors( form, data.errors );
				}

				setStatus( form, data.message || strings.genericError || 'Something went wrong.', false );
			} )
			.catch( function () {
				setStatus( form, strings.networkError || 'Network error.', false );
			} )
			.finally( function () {
				setBusy( form, false );
			} );
	}

	/**
	 * Wire one form up.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function init( form ) {
		if ( form.dataset.lfReady === '1' || ! config.submitUrl || ! window.fetch ) {
			return;
		}

		form.dataset.lfReady = '1';

		form.addEventListener( 'submit', function ( event ) {
			// Let the browser show its own messages for empty required fields
			// before falling back to the server round trip.
			if ( typeof form.reportValidity === 'function' && ! form.reportValidity() ) {
				return;
			}

			event.preventDefault();

			if ( form.classList.contains( 'is-busy' ) ) {
				return;
			}

			clearErrors( form );
			setStatus( form, strings.sending || '', true );
			setBusy( form, true );

			submit( form, false );
		} );

		// Clearing an error as soon as the visitor edits the field keeps the
		// feedback from feeling stale.
		form.addEventListener( 'input', function ( event ) {
			var wrapper = event.target.closest( '[data-lf-field]' );

			if ( ! wrapper || ! wrapper.classList.contains( 'has-error' ) ) {
				return;
			}

			wrapper.classList.remove( 'has-error' );
			event.target.removeAttribute( 'aria-invalid' );

			var slot = wrapper.querySelector( '[data-lf-error]' );

			if ( slot ) {
				slot.textContent = '';
			}
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-lf-form]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
