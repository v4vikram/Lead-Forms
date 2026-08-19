/**
 * Lead Forms — the "Lead Form" block.
 *
 * Written against the wp.* globals rather than JSX so the plugin needs no
 * build step. The block is server-rendered: the post only stores the form ID.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var Placeholder = wp.components.Placeholder;
	var ExternalLink = wp.components.ExternalLink;
	var ServerSideRender = wp.serverSideRender;

	var data = window.leadFormsBlockData || {};
	var forms = data.forms || [];

	/**
	 * Options for the form picker, with an explicit empty choice.
	 *
	 * @return {Array} Select options.
	 */
	function formOptions() {
		return [ { value: 0, label: __( '— Select a form —', 'lead-forms' ) } ].concat( forms );
	}

	wp.blocks.registerBlockType( 'lead-forms/lead-form', {
		edit: function ( props ) {
			var formId = props.attributes.formId || 0;
			var blockProps = useBlockProps();

			var picker = el( SelectControl, {
				label: __( 'Form', 'lead-forms' ),
				value: formId,
				options: formOptions(),
				onChange: function ( value ) {
					props.setAttributes( { formId: parseInt( value, 10 ) || 0 } );
				},
				__nextHasNoMarginBottom: true,
			} );

			var inspector = el(
				InspectorControls,
				null,
				el( PanelBody, { title: __( 'Form settings', 'lead-forms' ) }, picker )
			);

			// Nothing chosen yet: show the picker inline so the block is
			// usable without opening the sidebar.
			if ( ! formId ) {
				return el(
					'div',
					blockProps,
					inspector,
					el(
						Placeholder,
						{
							icon: 'feedback',
							label: __( 'Lead Form', 'lead-forms' ),
							instructions: forms.length
								? __( 'Choose which form to show.', 'lead-forms' )
								: __( 'You have not created a form yet.', 'lead-forms' ),
						},
						forms.length
							? picker
							: el(
									ExternalLink,
									{ href: data.newUrl || '#' },
									__( 'Create your first form', 'lead-forms' )
							  )
					)
				);
			}

			return el(
				'div',
				blockProps,
				inspector,
				// Rendering server-side keeps the editor preview identical to
				// the front end, with no duplicated markup to maintain.
				ServerSideRender
					? el( ServerSideRender, {
							block: 'lead-forms/lead-form',
							attributes: { formId: formId },
					  } )
					: el( 'p', null, __( 'Form preview unavailable.', 'lead-forms' ) )
			);
		},

		// Dynamic block: no markup is saved to post content.
		save: function () {
			return null;
		},
	} );
} )( window.wp );
