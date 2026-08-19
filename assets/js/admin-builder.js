/**
 * Lead Forms — the field builder repeater.
 *
 * The rows are ordinary form inputs, so the builder keeps working (minus the
 * conveniences) even if this file fails to load.
 */
( function () {
	'use strict';

	var settings = window.leadFormsBuilder || {};
	var withOptions = settings.typesWithOptions || [];
	var byRule = settings.typesByRule || {};
	var strings = settings.i18n || {};

	/**
	 * Turn a label into the key used in merge tags and stored payloads.
	 *
	 * Mirrors Field::to_key() in PHP; the server sanitises again on save, so a
	 * mismatch here is cosmetic rather than a correctness problem.
	 *
	 * @param {string} value Raw label.
	 * @return {string} Key.
	 */
	function toKey( value ) {
		return String( value )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
	}

	/**
	 * Rewrite every row's input names so indices stay unique and in order.
	 *
	 * @param {HTMLElement} container The rows wrapper.
	 */
	function reindex( container ) {
		container.querySelectorAll( '[data-lf-row]' ).forEach( function ( row, index ) {
			row.querySelectorAll( '[name^="lf_fields["]' ).forEach( function ( input ) {
				input.name = input.name.replace( /^lf_fields\[[^\]]*\]/, 'lf_fields[' + index + ']' );
			} );
		} );
	}

	/**
	 * Show or hide the choices textarea for the row's current type.
	 *
	 * @param {HTMLElement} row The row element.
	 */
	function syncOptionsVisibility( row ) {
		var type = row.querySelector( '[data-lf-type-input]' );
		var options = row.querySelector( '[data-lf-options]' );

		if ( ! type || ! options ) {
			return;
		}

		options.hidden = withOptions.indexOf( type.value ) === -1;
	}

	/**
	 * Show the validation controls that apply to the row's current type.
	 *
	 * The length, range and count blocks all use the same [min]/[max] input
	 * names, so the inactive ones are disabled as well as hidden — otherwise
	 * two pairs would submit and the later one would win.
	 *
	 * @param {HTMLElement} row The row element.
	 */
	function syncRules( row ) {
		var type = row.querySelector( '[data-lf-type-input]' );

		if ( ! type ) {
			return;
		}

		row.querySelectorAll( '[data-lf-rule]' ).forEach( function ( block ) {
			var rule = block.getAttribute( 'data-lf-rule' );
			var applies = ( byRule[ rule ] || [] ).indexOf( type.value ) !== -1;

			block.hidden = ! applies;

			block.querySelectorAll( 'input, select' ).forEach( function ( input ) {
				input.disabled = ! applies;
			} );
		} );

		syncCustomPattern( row );
	}

	/**
	 * Reveal the regex box only for the "Custom pattern…" preset.
	 *
	 * @param {HTMLElement} row The row element.
	 */
	function syncCustomPattern( row ) {
		var select = row.querySelector( '[data-lf-pattern-input]' );
		var block = row.querySelector( '[data-lf-custom-pattern]' );

		if ( ! block ) {
			return;
		}

		var show = Boolean( select ) && ! select.disabled && select.value === 'custom';

		block.hidden = ! show;

		block.querySelectorAll( 'input' ).forEach( function ( input ) {
			input.disabled = ! show;
		} );
	}

	/**
	 * Refresh the collapsed row summary.
	 *
	 * @param {HTMLElement} row The row element.
	 */
	function syncSummary( row ) {
		var label = row.querySelector( '[data-lf-label-input]' );
		var type = row.querySelector( '[data-lf-type-input]' );
		var title = row.querySelector( '[data-lf-row-title]' );
		var keyOut = row.querySelector( '[data-lf-row-key]' );
		var typeOut = row.querySelector( '[data-lf-row-type]' );
		var keyInput = row.querySelector( '[data-lf-key-input]' );

		if ( title && label ) {
			title.textContent = label.value.trim() || strings.untitled || 'Untitled field';
		}

		if ( keyOut && keyInput ) {
			keyOut.textContent = keyInput.value;
		}

		if ( typeOut && type && type.selectedOptions.length ) {
			typeOut.textContent = type.selectedOptions[ 0 ].textContent;
		}
	}

	/**
	 * Expand or collapse a row.
	 *
	 * @param {HTMLElement} row  The row element.
	 * @param {boolean}     open Whether to open it.
	 */
	function setOpen( row, open ) {
		var body = row.querySelector( '.lf-row__body' );
		var toggle = row.querySelector( '[data-lf-toggle]' );

		if ( body ) {
			body.hidden = ! open;
		}

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		row.classList.toggle( 'is-open', open );
	}

	/**
	 * Set up one builder instance.
	 *
	 * @param {HTMLElement} builder The builder wrapper.
	 */
	function init( builder ) {
		var rows = builder.querySelector( '[data-lf-rows]' );
		var template = builder.querySelector( '[data-lf-template]' );
		var addButton = builder.querySelector( '[data-lf-add]' );
		var addType = builder.querySelector( '[data-lf-add-type]' );
		var empty = builder.querySelector( '[data-lf-empty]' );

		if ( ! rows || ! template ) {
			return;
		}

		function refreshEmptyState() {
			if ( empty ) {
				empty.hidden = rows.querySelectorAll( '[data-lf-row]' ).length > 0;
			}
		}

		builder.querySelectorAll( '[data-lf-row]' ).forEach( function ( row ) {
			syncOptionsVisibility( row );
			syncRules( row );
			syncSummary( row );
		} );

		// --- Add ---------------------------------------------------------
		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				var index = rows.querySelectorAll( '[data-lf-row]' ).length;
				var markup = template.innerHTML.replace( /__INDEX__/g, String( index ) );
				var holder = document.createElement( 'div' );

				holder.innerHTML = markup;

				var row = holder.querySelector( '[data-lf-row]' );

				if ( ! row ) {
					return;
				}

				if ( addType ) {
					var typeInput = row.querySelector( '[data-lf-type-input]' );

					if ( typeInput ) {
						typeInput.value = addType.value;
					}
				}

				rows.appendChild( row );
				reindex( rows );
				syncOptionsVisibility( row );
				syncRules( row );
				syncSummary( row );
				setOpen( row, true );
				refreshEmptyState();

				var labelInput = row.querySelector( '[data-lf-label-input]' );

				if ( labelInput ) {
					labelInput.focus();
				}
			} );
		}

		// --- Row interactions (delegated) --------------------------------
		rows.addEventListener( 'click', function ( event ) {
			var row = event.target.closest( '[data-lf-row]' );

			if ( ! row ) {
				return;
			}

			if ( event.target.closest( '[data-lf-toggle]' ) ) {
				setOpen( row, ( row.querySelector( '.lf-row__body' ) || {} ).hidden !== false );
				return;
			}

			if ( event.target.closest( '[data-lf-remove]' ) ) {
				if ( ! window.confirm( strings.confirmRemove || 'Remove this field?' ) ) {
					return;
				}

				row.remove();
				reindex( rows );
				refreshEmptyState();
				return;
			}

			var move = event.target.closest( '[data-lf-move]' );

			if ( move ) {
				var direction = move.getAttribute( 'data-lf-move' );
				var sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;

				if ( ! sibling ) {
					return;
				}

				if ( direction === 'up' ) {
					rows.insertBefore( row, sibling );
				} else {
					rows.insertBefore( sibling, row );
				}

				reindex( rows );
				move.focus();
			}
		} );

		rows.addEventListener( 'input', function ( event ) {
			var row = event.target.closest( '[data-lf-row]' );

			if ( ! row ) {
				return;
			}

			// Typing in the key field means the author has taken it over; stop
			// tracking the label from then on.
			if ( event.target.matches( '[data-lf-key-input]' ) ) {
				event.target.dataset.locked = '1';
			}

			if ( event.target.matches( '[data-lf-label-input]' ) ) {
				var keyInput = row.querySelector( '[data-lf-key-input]' );

				// Keep mirroring the label until the key is locked — either by
				// a saved field (renaming one must not orphan its stored data)
				// or by the author editing the key by hand.
				if ( keyInput && ! keyInput.dataset.locked ) {
					keyInput.value = toKey( event.target.value );
				}
			}

			syncSummary( row );
		} );

		rows.addEventListener( 'change', function ( event ) {
			var row = event.target.closest( '[data-lf-row]' );

			if ( ! row ) {
				return;
			}

			if ( event.target.matches( '[data-lf-type-input]' ) ) {
				syncOptionsVisibility( row );
				syncRules( row );
				syncSummary( row );
			}

			if ( event.target.matches( '[data-lf-pattern-input]' ) ) {
				syncCustomPattern( row );
			}
		} );

		// Existing rows already have a key, which must not be regenerated.
		rows.querySelectorAll( '[data-lf-key-input]' ).forEach( function ( input ) {
			if ( input.value ) {
				input.dataset.locked = '1';
			}
		} );

		refreshEmptyState();
	}

	function boot() {
		document.querySelectorAll( '[data-lf-builder]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
