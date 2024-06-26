/**
 * @output wp-admin/js/widgets/text-widgets.js
 */

/* global tinymce, switchEditors */
/* eslint consistent-this: [ "error", "control" ] */

/**
 * @namespace wp.textWidgets
 */
wp.textWidgets = ( function( $ ) {
	'use strict';

	var component = {
		dismissedPointers: [],
		idBases: [ 'text' ]
	};

	component.TextWidgetControl = Backbone.View.extend(/** @lends wp.textWidgets.TextWidgetControl.prototype */{

		/**
		 * View events.
		 *
		 * @type {Object}
		 */
		events: {},

		/**
		 * Text widget control.
		 *
		 * @constructs wp.textWidgets.TextWidgetControl
		 * @augments   Backbone.View
		 * @abstract
		 *
		 * @param {Object} options - Options.
		 * @param {jQuery} options.el - Control field container element.
		 * @param {jQuery} options.syncContainer - Container element where fields are synced for the server.
		 *
		 * @return {void}
		 */
		initialize: function initialize( options ) {
			var control = this;

			if ( ! options.el ) {
				throw new Error( 'Missing options.el' );
			}
			if ( ! options.syncContainer ) {
				throw new Error( 'Missing options.syncContainer' );
			}

			Backbone.View.prototype.initialize.call( control, options );
			control.syncContainer = options.syncContainer;

			control.el.classList.add( 'text-widget-fields' );
			control.$el.html( wp.template( 'widget-text-control-fields' ) );

			control.customHtmlWidgetPointer = control.$el.find( '.wp-pointer.custom-html-widget-pointer' );
			if ( control.customHtmlWidgetPointer.length ) {
				control.customHtmlWidgetPointer[0].querySelector( '.close' ).addEventListener( 'click', function( event ) {
					event.preventDefault();
					control.customHtmlWidgetPointer[0].style.display = 'none';
					document.getElementById( control.fields.text.id + '-html' ).focus();
					control.dismissPointers( [ 'text_widget_custom_html' ] );
				});
			}

			control.pasteHtmlPointer = control.$el.find( '.wp-pointer.paste-html-pointer' );
			if ( control.pasteHtmlPointer.length ) {
				control.pasteHtmlPointer[0].querySelector( '.close' ).addEventListener( 'click', function( event ) {
					event.preventDefault();
					control.pasteHtmlPointer[0].style.display = 'none';
					control.editor.focus();
					control.dismissPointers( [ 'text_widget_custom_html', 'text_widget_paste_html' ] );
				});
			}

			control.fields = {
				title: control.el.querySelector( '.title' ),
				text: control.el.querySelector( '.text' )
			};

			// Sync input fields to hidden sync fields which actually get sent to the server
			_.each( control.fields, function( fieldInput, fieldName ) {
				var widgetId = control.syncContainer.closest( '.widget' ).id,
					syncInput = control.syncContainer.querySelector( '.' + fieldName + '.sync-input' );

				// Re-create syncInput after destruction by each widget-updated event
				document.addEventListener( 'widget-updated', function( e ) {
					if ( e.detail.widget.id === widgetId ) {
						syncInput = e.detail.widget.querySelector( '.' + fieldName + '.sync-input' );
					}
				} );

				fieldInput.addEventListener( 'input', updateSyncField );
				fieldInput.addEventListener( 'change', updateSyncField );

				function updateSyncField() {
					if ( syncInput.value !== fieldInput.value ) {
						syncInput.value = fieldInput.value;

						// Trigger change event
						syncInput.closest( 'li' ).classList.add( 'widget-dirty' );
						syncInput.closest( '.widget' ).dispatchEvent( new Event( 'change' ) );
					}
				}

				// Note that syncInput cannot be re-used because it will be destroyed with each widget-updated event
				fieldInput.value = control.syncContainer.querySelector( '.' + fieldName + '.sync-input' ).value;
			} );
		},

		/**
		 * Dismiss pointers for Custom HTML widget.
		 *
		 * @since 4.8.1
		 *
		 * @param {Array} pointers Pointer IDs to dismiss.
		 * @return {void}
		 */
		dismissPointers: function dismissPointers( pointers ) {
			_.each( pointers, function( pointer ) {
				wp.ajax.post( 'dismiss-wp-pointer', {
					pointer: pointer
				});
				component.dismissedPointers.push( pointer );
			});
		},

		/**
		 * Open available widgets panel.
		 *
		 * @since 4.8.1
		 * @return {void}
		 */
		openAvailableWidgetsPanel: function openAvailableWidgetsPanel() {
			var sidebarControl;
			wp.customize.section.each( function( section ) {
				if ( section.extended( wp.customize.Widgets.SidebarSection ) && section.expanded() ) {
					sidebarControl = wp.customize.control( 'sidebars_widgets[' + section.params.sidebarId + ']' );
				}
			});
			if ( ! sidebarControl ) {
				return;
			}
			setTimeout( function() { // Timeout to prevent click event from causing panel to immediately collapse.
				wp.customize.Widgets.availableWidgetsPanel.open( sidebarControl );
				wp.customize.Widgets.availableWidgetsPanel.$search.val( 'HTML' ).trigger( 'keyup' );
			});
		},

		/**
		 * Update input fields from the sync fields.
		 *
		 * This function is called at the widget-updated and widget-synced events.
		 * A field will only be updated if it is not currently focused, to avoid
		 * overwriting content that the user is entering.
		 *
		 * @return {void}
		 */
		updateFields: function updateFields() {
			var control = this, syncInput;

			if ( control.fields.title !== document.activeElement ) {
				syncInput = control.syncContainer.querySelector( '.title.sync-input' );
				control.fields.title.value = syncInput.value;
			}

			syncInput = control.syncContainer.querySelector( '.text.sync-input' );
			if ( isVisible( control.fields.text ) ) {
				if ( control.fields.text !== document.activeElement ) {
					control.fields.text.value = syncInput.value;
				}
			} else if ( control.editor && ! control.editorFocused && syncInput.value !== control.fields.text.value ) {
				control.editor.setContent( wp.oldEditor.autop( syncInput.value ) );
			}
		},

		/**
		 * Initialize editor.
		 *
		 * @return {void}
		 */
		initializeEditor: function initializeEditor() {
			var control = this, changeDebounceDelay = 1000, id, textarea, triggerChangeIfDirty, restoreTextMode = false, needsTextareaChangeTrigger = false, previousValue;
			textarea = control.fields.text;
			id = textarea.id;
			previousValue = textarea.value;

			/**
			 * Trigger change if dirty.
			 *
			 * @return {void}
			 */
			triggerChangeIfDirty = function() {
				var updateWidgetBuffer = 300; // See wp.customize.Widgets.WidgetControl._setupUpdateUI() which uses 250ms for updateWidgetDebounced.
				if ( control.editor.isDirty() ) {

					/*
					 * Account for race condition in customizer where user clicks Save & Publish while
					 * focus was just previously given to the editor. Since updates to the editor
					 * are debounced at 1 second and since widget input changes are only synced to
					 * settings after 250ms, the customizer needs to be put into the processing
					 * state during the time between the change event is triggered and updateWidget
					 * logic starts. Note that the debounced update-widget request should be able
					 * to be removed with the removal of the update-widget request entirely once
					 * widgets are able to mutate their own instance props directly in JS without
					 * having to make server round-trips to call the respective WP_Widget::update()
					 * callbacks. See <https://core.trac.wordpress.org/ticket/33507>.
					 */
					if ( wp.customize && wp.customize.state ) {
						wp.customize.state( 'processing' ).set( wp.customize.state( 'processing' ).get() + 1 );
						_.delay( function() {
							wp.customize.state( 'processing' ).set( wp.customize.state( 'processing' ).get() - 1 );
						}, updateWidgetBuffer );
					}

					if ( ! control.editor.isHidden() ) {
						control.editor.save();
					}
				}

				// Trigger change on textarea when it has changed so the widget can enter a dirty state.
				if ( needsTextareaChangeTrigger && previousValue !== textarea.value ) {
					textarea.dispatchEvent( new Event( 'change' ) );
					needsTextareaChangeTrigger = false;
					previousValue = textarea.value;
				}
			};

			// Just-in-time force-update the hidden input fields.
			control.syncContainer.closest( '.widget' ).querySelector( '[name=savewidget]' ).addEventListener( 'click', function onClickSaveButton() {
				triggerChangeIfDirty();
			});

			/**
			 * Build (or re-build) the visual editor.
			 *
			 * @return {void}
			 */
			function buildEditor() {
				var editor, onInit, showPointerElement;

				// Abort building if the textarea is gone, likely due to the widget having been deleted entirely.
				if ( ! document.getElementById( id ) ) {
					return;
				}

				// The user has disabled TinyMCE.
				if ( typeof window.tinymce === 'undefined' ) {
					wp.oldEditor.initialize( id, {
						quicktags: true,
						mediaButtons: true
					});

					return;
				}

				// Destroy any existing editor so that it can be re-initialized after a widget-updated event.
				if ( tinymce.get( id ) ) {
					restoreTextMode = tinymce.get( id ).isHidden();
					wp.oldEditor.remove( id );
				}

				// Add or enable the `wpview` plugin.
				$( document ).one( 'wp-before-tinymce-init.text-widget-init', function( event, init ) {
					// If somebody has removed all plugins, they must have a good reason.
					// Keep it that way.
					if ( ! init.plugins ) {
						return;
					} else if ( ! /\bwpview\b/.test( init.plugins ) ) {
						init.plugins += ',wpview';
					}
				} );

				wp.oldEditor.initialize( id, {
					tinymce: {
						wpautop: true
					},
					quicktags: true,
					mediaButtons: true
				} );

				/**
				 * Show a pointer, focus on dismiss, and speak the contents for a11y.
				 *
				 * @param {jQuery} pointerElement Pointer element.
				 * @return {void}
				 */
				showPointerElement = function( pointerElement ) {
					pointerElement.show();
					pointerElement.find( '.close' ).trigger( 'focus' );
					wp.a11y.speak( pointerElement.find( 'h3, p' ).map( function() {
						return $( this ).text();
					} ).get().join( '\n\n' ) );
				};

				editor = window.tinymce.get( id );
				if ( ! editor ) {
					throw new Error( 'Failed to initialize editor' );
				}
				onInit = function() {

					// When a widget is moved in the DOM the dynamically-created TinyMCE iframe will be destroyed and has to be re-built.
					$( editor.getWin() ).on( 'pagehide', function() {
						_.defer( buildEditor );
					});

					// If a prior mce instance was replaced, and it was in text mode, toggle to text mode.
					if ( restoreTextMode ) {
						switchEditors.go( id, 'html' );
					}

					// Show the pointer.
					$( '#' + id + '-html' ).on( 'click', function() {
						control.pasteHtmlPointer.hide(); // Hide the HTML pasting pointer.

						if ( -1 !== component.dismissedPointers.indexOf( 'text_widget_custom_html' ) ) {
							return;
						}
						showPointerElement( control.customHtmlWidgetPointer );
					});

					// Hide the pointer when switching tabs.
					$( '#' + id + '-tmce' ).on( 'click', function() {
						control.customHtmlWidgetPointer.hide();
					});

					// Show pointer when pasting HTML.
					editor.on( 'pastepreprocess', function( event ) {
						var content = event.content;
						if ( -1 !== component.dismissedPointers.indexOf( 'text_widget_paste_html' ) || ! content || ! /&lt;\w+.*?&gt;/.test( content ) ) {
							return;
						}

						// Show the pointer after a slight delay so the user sees what they pasted.
						_.delay( function() {
							showPointerElement( control.pasteHtmlPointer );
						}, 250 );
					});
				};

				if ( editor.initialized ) {
					onInit();
				} else {
					editor.on( 'init', onInit );
				}

				control.editorFocused = false;

				editor.on( 'focus', function onEditorFocus() {
					control.editorFocused = true;
				});
				editor.on( 'paste', function onEditorPaste() {
					editor.setDirty( true ); // Because pasting doesn't currently set the dirty state.
					triggerChangeIfDirty();
				});
				editor.on( 'NodeChange', function onNodeChange() {
					needsTextareaChangeTrigger = true;
				});
				editor.on( 'NodeChange', _.debounce( triggerChangeIfDirty, changeDebounceDelay ) );
				editor.on( 'blur hide', function onEditorBlur() {
					control.editorFocused = false;
					triggerChangeIfDirty();
				});

				control.editor = editor;
			}

			buildEditor();
		}
	});

	/**
	 * Mapping of widget ID to instances of TextWidgetControl subclasses.
	 *
	 * @memberOf wp.textWidgets
	 *
	 * @type {Object.<string, wp.textWidgets.TextWidgetControl>}
	 */
	component.widgetControls = {};

	/**
	 * Handle widget being added or initialized for the first time at the widget-added event.
	 *
	 * @memberOf wp.textWidgets
	 *
	 * @param {Custom.Event} event - Event.
	 * @param widgetContainer - Widget container element.
	 *
	 * @return {void}
	 */
	component.handleWidgetAdded = function handleWidgetAdded( event ) {
		var idBase, widgetControl, widgetId, fieldContainer, syncContainer,
			widgetContainer = event.detail.widget,
			animatedCheckDelay = 200;

		idBase = widgetContainer.querySelector( '.id_base' ).value;
		if ( -1 === component.idBases.indexOf( idBase ) ) {
			return;
		}

		// Prevent initializing already-added widgets.
		widgetId = widgetContainer.querySelector( '.widget-id' ).value;
		if ( component.widgetControls[ widgetId ] ) {
			return;
		}

		// Bypass using TinyMCE when widget is in legacy mode.
		if ( ! widgetContainer.querySelector( '.visual' ) ) {
			return;
		}

		/*
		 * Create a container element for the widget control fields.
		 * This is inserted into the DOM immediately before the .widget-content
		 * element because the contents of this element are essentially "managed"
		 * by PHP, where each widget update cause the entire element to be emptied
		 * and replaced with the rendered output of WP_Widget::form() which is
		 * sent back in Ajax request made to save/update the widget instance.
		 * To prevent a "flash of replaced DOM elements and re-initialized JS
		 * components", the JS template is rendered outside of the normal form
		 * container.
		 */
		fieldContainer = document.createElement( 'div' );
		syncContainer = widgetContainer.querySelector( '.widget-content' );
		syncContainer.before( fieldContainer );

		widgetControl = new component.TextWidgetControl({
			el: fieldContainer,
			syncContainer: syncContainer
		} );

		component.widgetControls[ widgetId ] = widgetControl;

		/*
		 * Render the widget once the widget parent's container finishes animating,
		 * as the widget-added event fires with a slideDown of the container.
		 * This ensures that the textarea is visible and an iframe can be embedded
		 * with TinyMCE being able to set contenteditable on it.
		 */
		function renderWhenAnimationDone() {
			if ( ! widgetContainer.querySelector( 'details' ).hasAttribute( 'open' ) ) {
				setTimeout( renderWhenAnimationDone, animatedCheckDelay );
			} else {
				widgetControl.initializeEditor();
			}
		}
		renderWhenAnimationDone();
	};

	/**
	 * Setup widget in accessibility mode.
	 *
	 * @memberOf wp.textWidgets
	 *
	 * @return {void}
	 */
	component.setupAccessibleMode = function setupAccessibleMode() {
		var widgetForm, idBase, widgetControl, fieldContainer, syncContainer;

		widgetForm = document.querySelector( '.editwidget > form' );
		if ( widgetForm == null ) { // also catches undefined
			return;
		}

		idBase = widgetForm.querySelector( '.id_base' ).value;
		if ( -1 === component.idBases.indexOf( idBase ) ) {
			return;
		}

		// Bypass using TinyMCE when widget is in legacy mode.
		if ( ! widgetForm.querySelector( '.visual' ).value ) {
			return;
		}

		fieldContainer = document.createElement( 'div' );
		syncContainer = widgetForm.querySelector( '.widget-inside' );
		syncContainer.before( fieldContainer );

		widgetControl = new component.TextWidgetControl({
			el: fieldContainer,
			syncContainer: syncContainer
		} );

		widgetControl.initializeEditor();
	};

	/**
	 * Sync widget instance data sanitized from server back onto widget model.
	 *
	 * This gets called via the 'widget-updated' event when saving a widget from
	 * the widgets admin screen and also via the 'widget-synced' event when making
	 * a change to a widget in the customizer.
	 *
	 * @memberOf wp.textWidgets
	 *
	 * @param {Custom.Event} event - Event.
	 * @param widgetContainer - Widget container element.
	 *
	 * @return {void}
	 */
	component.handleWidgetUpdated = function handleWidgetUpdated( event ) {
		var idBase, widgetId, widgetControl,
			widgetContainer = event.detail.widget;

		idBase = widgetContainer.querySelector( '.id_base' ).value;
		if ( -1 === component.idBases.indexOf( idBase ) ) {
			return;
		}

		widgetId = widgetContainer.querySelector( '.widget-id' ).value;
		widgetControl = component.widgetControls[ widgetId ];
		if ( ! widgetControl ) {
			return;
		}

		widgetControl.updateFields();
	};

	/**
	 * Initialize functionality.
	 *
	 * This function exists to prevent the JS file from having to boot itself.
	 * When WordPress enqueues this script, it should have an inline script
	 * attached which calls wp.textWidgets.init().
	 *
	 * @memberOf wp.textWidgets
	 *
	 * @return {void}
	 */
	component.init = function init() {
		document.addEventListener( 'widget-added', component.handleWidgetAdded );
		document.addEventListener( 'widget-synced', component.handleWidgetUpdated );
		document.addEventListener( 'widget-updated', component.handleWidgetUpdated );

		/*
		 * Manually trigger widget-added events for media widgets on the admin
		 * screen once they are expanded. The widget-added event is not triggered
		 * for each pre-existing widget on the widgets admin screen like it is
		 * on the customizer. Likewise, the customizer only triggers widget-added
		 * when the widget is expanded to just-in-time construct the widget form
		 * when it is actually going to be displayed. So the following implements
		 * the same for the widgets admin screen, to invoke the widget-added
		 * handler when a pre-existing media widget is expanded.
		 */
		$( function initializeExistingWidgetContainers() {
			var widgetContainerWraps, widgetContainers = [];
			if ( 'widgets' !== window.pagenow ) {
				return;
			}

			widgetContainerWraps = document.querySelectorAll( '.widgets-holder-wrap:not(#available-widgets)' );
			widgetContainerWraps.forEach( function( wrap ) {
				wrap.querySelectorAll( 'li.widget' ).forEach( function( widget ) {
					widgetContainers.push( widget );
				} );
			} );

			widgetContainers.forEach( function( widgetContainer ) {
				widgetContainer.querySelector( 'details' ).addEventListener( 'toggle', function toggleWidgetExpanded() {
					document.dispatchEvent( new CustomEvent( 'widget-added', {
						detail: { widget: widgetContainer }
					} ) );
				}, { once: true } );
			} );

			// Accessibility mode.
			component.setupAccessibleMode();
		});
	};

	return component;

	/*
	 * Helper function copied from jQuery
	 */
	function isVisible( elem ) {
		return !!( elem.offsetWidth || elem.offsetHeight || elem.getClientRects().length );
	}
})( jQuery );
