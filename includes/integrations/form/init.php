<?php
namespace Bricks\Integrations\Form;

use Bricks\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Init {
	protected $uploaded_files;
	protected $form_settings;
	protected $form_fields;
	protected $form_id;
	protected $post_id;
	protected $results;
	protected $update_post_executed = false;

	/**
	 * Rebuilt query chain kept alive while form actions render ACF dynamic data.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @var \Bricks\Query[]
	 */
	protected $query_context = [];

	protected static $submission_url_params = [];

	public function __construct() {
		add_action( 'wp_ajax_bricks_form_submit', [ $this, 'form_submit' ] );
		add_action( 'wp_ajax_nopriv_bricks_form_submit', [ $this, 'form_submit' ] );
	}

	/**
	 * Element Form: Submit
	 *
	 * @since 1.0
	 */
	public function form_submit() {
		// Return: Invalid form nonce
		if ( ! check_ajax_referer( 'bricks-nonce-form', 'nonce', false ) ) {
			wp_send_json_error(
				[
					'action'  => '',
					'code'    => 'invalid_nonce', // special code for invalid nonce (@since 1.9.6)
					'type'    => 'error',
					'message' => esc_html__( 'Invalid form token.', 'bricks' ),
				]
			);
		}

		$form_element_id   = isset( $_POST['formId'] ) ? sanitize_text_field( $_POST['formId'] ) : '';
		$form_component_id = isset( $_POST['componentId'] ) ? sanitize_text_field( $_POST['componentId'] ) : ''; // (@since 2.1)
		$post_id           = isset( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;

		/**
		 * Preserve the existing loop context. ACF row queries replace this with the
		 * containing page ID only after their signed context is restored.
		 *
		 * #86cazz5mc; @since 2.3.11
		 */
		$loop_post_id = isset( $_POST['loopId'] ) ? absint( $_POST['loopId'] ) : 0;

		/**
		 * Switch language based on the form submission data
		 *
		 * @since 2.2
		 */
		$locale = isset( $_POST['lang'] ) ? sanitize_text_field( $_POST['lang'] ) : '';
		if ( $locale ) {
			switch_to_locale( $locale );
		}

		/**
		 * Support for passing the current language code from the front end (e.g. Polylang, WPML) to get the correct element settings based on the current language. Especially if the element is inside a popup template.
		 * #86c94gr3q
		 *
		 * @since 2.3
		 */
		$language_code = isset( $_POST['langCode'] ) ? sanitize_key( $_POST['langCode'] ) : '';
		if ( $language_code ) {
			\Bricks\Database::set_page_data_language( $language_code );
		}

		$this->form_settings = \Bricks\Helpers::get_element_settings( $post_id, $form_element_id );
		$this->form_id       = $form_element_id;
		$this->post_id       = $post_id;

		// STEP: Resolve form settings in their rendered component context.
		if ( $form_component_id ) {
			$component_form_settings = $this->get_nested_form_settings( $post_id, $form_element_id, $form_component_id );

			if ( is_array( $component_form_settings ) ) {
				$this->form_settings = $component_form_settings;
			} else {
				// Gutenberg component blocks are not part of the Bricks element tree.
				$this->apply_component_properties( $post_id, $form_component_id, $form_element_id );
			}
		}

		// No form settings found: Try to get from component element (@since 1.12.2)
		if ( empty( $this->form_settings ) ) {
			$this->form_settings = $this->get_nested_form_settings( $post_id, $form_element_id );
		}

		// No form settings found: Try to get from component element (@since 1.12.2)
		if ( empty( $this->form_settings ) ) {
			$component_element = \Bricks\Helpers::get_component_element_by_id( $form_element_id );

			if ( isset( $component_element['settings'] ) ) {
				$this->form_settings = $component_element['settings'];
			}
		}

		/**
		 * Restore ACF row context before field names, validation messages,
		 * and actions render dynamic data. An absent or invalid context preserves the
		 * existing form submission path.
		 *
		 * #86cazz5mc; @since 2.3.11
		 */
		$this->set_query_context( $post_id, $form_element_id, $form_component_id );
		$loop_post_id = $this->query_context ? $post_id : $loop_post_id;

		// Return: No form action set
		if ( empty( $this->form_settings['actions'] ) ) {
			wp_send_json_error(
				[
					'code'    => 400,
					'action'  => '',
					'type'    => 'error',
					'message' => esc_html__( 'No action has been set for this form.', 'bricks' ),
				]
			);
		}

		// Form submission: Support DD tag 'url_parameter' (@since 1.11)
		self::$submission_url_params = []; // Clear on each form submission
		$url_params                  = ! empty( $_POST['urlParams'] ) ? JSON_decode( stripslashes( $_POST['urlParams'] ), true ) : [];

		if ( ! empty( $url_params ) ) {
			// Sanitize url parameters
			$esc_url_or_text = function( $value ) {
				return filter_var( $value, FILTER_VALIDATE_URL ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
			};

			// Sanitize url parameters
			self::$submission_url_params = array_map( $esc_url_or_text, $url_params );
		}

		/**
		 * STEP: Google reCAPTCHA v3 (invisible)
		 *
		 * Only verify if enabled in form settings and API key is set (#86c83pftd)
		 */
		$google_recaptcha_enabled = isset( $this->form_settings['enableRecaptcha'] ) && \Bricks\Database::get_setting( 'apiKeyGoogleRecaptcha', false );
		if ( $google_recaptcha_enabled ) {
			$recaptcha_secret_key = \Bricks\Database::get_setting( 'apiSecretKeyGoogleRecaptcha', false );
			$recaptcha_token      = ! empty( $_POST['recaptchaToken'] ) ? sanitize_text_field( $_POST['recaptchaToken'] ) : false;
			$recaptcha_verified   = false;

			// Verify token @see https://developers.google.com/recaptcha/docs/verify
			if ( $recaptcha_token && $recaptcha_secret_key ) {
				$url                = "https://www.google.com/recaptcha/api/siteverify?secret=$recaptcha_secret_key&response=$recaptcha_token";
				$recaptcha_response = \Bricks\Helpers::remote_get( $url );

				if ( ! is_wp_error( $recaptcha_response ) && wp_remote_retrieve_response_code( $recaptcha_response ) === 200 ) {
					$recaptcha = json_decode( wp_remote_retrieve_body( $recaptcha_response ) );

					/*
					 * Google reCAPTCHA v3 returns a score
					 *
					 * 1.0 is very likely a good interaction. 0.0 is very likely a bot.
					 *
					 * https://academy.bricksbuilder.io/article/form-element/#spam
					 */
					$score = apply_filters( 'bricks/form/recaptcha_score_threshold', 0.5 );

					// Action was set on the grecaptcha.execute (@see frontend.js)
					if ( $recaptcha->success && $recaptcha->score >= $score && $recaptcha->action == 'bricks_form_submit' ) {
						$recaptcha_verified = true;
					}
				}
			}

			if ( ! $recaptcha_verified ) {
				$error = 'reCAPTCHA: ' . esc_html__( 'Validation failed', 'bricks' );

				if ( ! empty( $recaptcha->{'error-codes'} ) ) {
					$error .= ' [' . implode( ',', $recaptcha->{'error-codes'} ) . ']';
				}

				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => $error,
					]
				);
			}
		}

		/**
		 * STEP: Verify visible hCaptcha
		 *
		 * Only verify if enabled in form settings and API key is set (#86c83pftd)
		 *
		 * @since 1.9.2
		 */
		$hcaptcha_enabled = isset( $this->form_settings['enableHCaptcha'] ) && \Bricks\Database::get_setting( 'apiKeyHCaptcha', false );
		if ( $hcaptcha_enabled ) {
			$hcaptcha_secret_key = \Bricks\Database::get_setting( 'apiSecretKeyHCaptcha' );
			$hcaptcha_response   = isset( $_POST['h-captcha-response'] ) ? sanitize_text_field( $_POST['h-captcha-response'] ) : false;
			$hcaptcha_verified   = false;

			// Verify token
			if ( $hcaptcha_response && $hcaptcha_secret_key ) {
				$url          = "https://hcaptcha.com/siteverify?secret=$hcaptcha_secret_key&response=$hcaptcha_response";
				$hcaptcha_res = \Bricks\Helpers::remote_get( $url );

				if ( ! is_wp_error( $hcaptcha_res ) && wp_remote_retrieve_response_code( $hcaptcha_res ) === 200 ) {
					$hcaptcha = json_decode( wp_remote_retrieve_body( $hcaptcha_res ) );

					// Check hCaptcha response (https://docs.hcaptcha.com/#verify-the-user-response-server-side)
					if ( $hcaptcha->success ) {
						$hcaptcha_verified = true;
					}
				}
			}

			if ( ! $hcaptcha_verified ) {
				$error = 'hCaptcha: ' . esc_html__( 'Validation failed', 'bricks' );

				if ( ! empty( $hcaptcha->{'error-codes'} ) ) {
					$error .= ' [' . implode( ',', $hcaptcha->{'error-codes'} ) . ']';
				}

				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => $error,
					]
				);
			}
		}

		/**
		 * STEP: Verify Turnstile captcha
		 *
		 * https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
		 *
		 * Only verify if enabled in form settings and API key is set (#86c83pftd)
		 *
		 * @since 1.9.2
		 */
		$turnstile_enabled = isset( $this->form_settings['enableTurnstile'] ) && \Bricks\Database::get_setting( 'apiKeyTurnstile', false );
		if ( $turnstile_enabled ) {
			$turnstile_secret_key = \Bricks\Database::get_setting( 'apiSecretKeyTurnstile' );
			$turnstile_response   = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( $_POST['cf-turnstile-response'] ) : false;
			$turnstile_data       = [];

			// Return error: Secret key set, but no response (@since 1.9.8)
			if ( $turnstile_secret_key && ! $turnstile_response ) {
				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => 'Turnstile: ' . esc_html__( 'Validation failed', 'bricks' ),
					]
				);
			}

			if ( $turnstile_secret_key && $turnstile_response ) {
				$url  = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
				$args = [
					'body' => [
						'secret'   => $turnstile_secret_key,
						'response' => $turnstile_response,
						// 'remoteip' => $_SERVER['REMOTE_ADDR'], // We can optionally send the user's IP address but it's not required
					],
				];

				$turnstile_verified = false;
				$response           = \Bricks\Helpers::remote_post( $url, $args );

				if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
					$turnstile_data     = json_decode( wp_remote_retrieve_body( $response ), true );
					$turnstile_verified = isset( $turnstile_data['success'] ) && $turnstile_data['success'] === true;
				}

				if ( ! $turnstile_verified ) {
					$error = 'Turnstile: ' . esc_html__( 'Validation failed', 'bricks' );

					if ( isset( $turnstile_data['error-codes'] ) && ! empty( $turnstile_data['error-codes'] ) ) {
						$error .= ' [' . implode( ',', $turnstile_data['error-codes'] ) . ']';
					}

					wp_send_json_error(
						[
							'code'    => 400,
							'action'  => '',
							'type'    => 'error',
							'message' => $error,
						]
					);
				}
			}
		}

		$this->form_fields = stripslashes_deep( $_POST );

		/**
		 * STEP: Check that each submitted field's ID is in the list of valid field IDs
		 *
		 * Initialize an empty array to keep track of processed field IDs
		 *
		 * @since 1.9.2
		 */
		$processed_ids = [];

		// Get valid field IDs from form_settings
		$valid_ids = [];

		foreach ( $this->form_settings['fields'] as $key => $field ) {
			if ( ! empty( $field['id'] ) ) {
				// Get & set 'id' from custom 'name' (e.g.: 'post-{post_id} to 'form-field-{{field_id}}')
				if ( ! empty( $field['name'] ) ) {
					$field_name = bricks_render_dynamic_data( $field['name'], $loop_post_id );

					// Update the parsed name back to the form_fields array so no need to render dynamic data again (@since 1.9.5)
					$this->form_settings['fields'][ $key ]['name'] = $field_name;

					if ( isset( $this->form_fields[ $field_name ] ) ) {
						$field_value                                      = $this->form_fields[ $field_name ];
						$this->form_fields[ "form-field-{$field['id']}" ] = $field_value;
					}
				}

				$valid_ids[] = $field['id'];
			}

			// If field is Honeypot, ensure that value is not set (@since 1.12.2)
			if ( isset( $field['isHoneypot'] ) ) {
				$field_id = $field['id'] ?? '';

				if ( isset( $this->form_fields[ "form-field-$field_id" ] ) && ! empty( $this->form_fields[ "form-field-$field_id" ] ) ) {

					// Log the error
					Helpers::maybe_log(
						"[Honeypot] Possible spam submission:
- Form Bricks Id: $form_element_id
- Field ID: $field_id
- Field Value: {$this->form_fields[ "form-field-$field_id" ]}
"
					);

					// Set honeypot result
					$honeypot_result = [
						'type'    => 'error',
						'message' => esc_html__( 'An error occurred, please try again later.', 'bricks' ),
					];

					// Allow users to customize the honeypot error message using filter
					$honeypot_result = apply_filters( 'bricks/element/form/honeypot/result', $honeypot_result, $field_id, $this );

					$this->set_result( $honeypot_result );

					// Finish the form submission
					$this->maybe_stop_processing(); // If error status is set...
					$this->finish(); // else...

					break;
				}
			}
		}

		/**
		 * Initialize an array for field IDs that we skip the form field check
		 *
		 * Password reset action: key, login
		 *
		 * @since 1.9.3
		 */
		$skip_check_for_field_ids = [];

		// Check if 'reset-password' is among the set actions for the form
		if ( in_array( 'reset-password', $this->form_settings['actions'], true ) ) {
			array_push( $skip_check_for_field_ids, 'key', 'login' );
		}

		// Check if 'login' is among the set actions for the form
		if ( in_array( 'login', $this->form_settings['actions'], true ) ) {
			array_push( $skip_check_for_field_ids, 'redirect_to' );
		}

		// Check if 'brx_pp_temp_id' is set and is a numeric value
		if ( isset( $_POST['brx_pp_temp_id'] ) && is_numeric( $_POST['brx_pp_temp_id'] ) ) {
			array_push( $skip_check_for_field_ids, 'brx_pp_temp_id' );
		}

		foreach ( array_keys( $this->form_fields ) as $key ) {
			// Check if submitted form field ID is valid
			if ( strpos( $key, 'form-field-' ) === 0 ) {
				$field_id = str_replace( 'form-field-', '', $key );

				// Skip: Field ID has already been processed (e.g.: HTML simply duplicated on the front end)
				if ( in_array( $field_id, $processed_ids, true ) ) {
					// Reject the submission as potentially malicious
					$this->set_error_messages( esc_html__( 'An error occurred, please try again later.', 'bricks' ) );
					$this->maybe_stop_processing();
				}

				// Add field ID to list of processed IDs
				$processed_ids[] = $field_id;

				if ( ! in_array( $field_id, $valid_ids, true ) && ! in_array( $field_id, $skip_check_for_field_ids, true ) ) {
					// Reject the submission as potentially malicious
					$this->set_error_messages( esc_html__( 'An error occurred, please try again later.', 'bricks' ) );
					$this->maybe_stop_processing();
				}
			}
		}

		// STEP: Check field max-length, and stop processing if any field value exceeds the max-length (@since 1.11.1)
		$this->check_max_length();

		// STEP: Handle files
		$this->uploaded_files = $this->handle_files();

		// STEP: Validate form submission via filter
		$validation_errors = [];

		$validation_errors = apply_filters( 'bricks/form/validate', $validation_errors, $this );

		// STEP: Validate required fields
		$validation_errors = $this->validate_required_fields( $validation_errors );

		// STEP: Validate email fields
		$validation_errors = $this->validate_email_fields( $validation_errors );

		// STEP: Validate password confirmation
		$validation_errors = $this->validate_password_confirmation( $validation_errors );

		// STEP: Validate submitted form
		if ( is_array( $validation_errors ) && count( $validation_errors ) ) {
			// Set validation error messages
			$this->set_error_messages( $validation_errors );

			// Halts execution if an action reported an error (to run validator before running the form action)
			$this->maybe_stop_processing();
		}

		/**
		 * STEP: Handle file uploads early if create-post or update-post actions are present
		 *
		 * These actions need attachment IDs to save to ACF/Meta Box fields,
		 * so files must be uploaded to media library before actions run.
		 *
		 * @since 2.2
		 */
		$this->maybe_handle_files_early();

		// STEP: Run selected form submit 'actions'
		$available_actions = self::get_available_actions();

		// Always set 'redirect' as last action if enabled (@since 2.1)
		if ( in_array( 'redirect', $this->form_settings['actions'], true ) ) {
			// Remove 'redirect' from actions
			$this->form_settings['actions'] = array_diff( $this->form_settings['actions'], [ 'redirect' ] );
			// Add 'redirect' as last action
			$this->form_settings['actions'][] = 'redirect';
		}

		foreach ( $this->form_settings['actions'] as $form_action ) {
			if ( ! $this->should_run_action( $form_action ) ) {
				continue;
			}

			// Skip if not a valid built-in action and no custom action handler exists (@since 1.12.2)
			if ( ! array_key_exists( $form_action, $available_actions ) && ! has_action( "bricks/form/action/{$form_action}" ) ) {
				continue;
			}

			$action_class = 'Bricks\Integrations\Form\Actions\\' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $form_action ) ) );

			// If class exists, run the action
			if ( class_exists( $action_class ) ) {
				$action = new $action_class( $form_action );

				if ( ! method_exists( $action_class, 'run' ) ) {
					continue;
				}

				$action->run( $this );
			}
			// Handle custom actions registered via controls filter (@since 1.12.2)
			else {
				/**
				 * Fire custom form action
				 *
				 * @param \Bricks\Integrations\Form\Init $form Current form instance
				 *
				 * @since 1.12.2
				 */
				do_action( "bricks/form/action/{$form_action}", $this );
			}

			if ( $form_action === 'update-post' ) {
				$this->update_post_executed = true;
			}

			// Halts execution if an action reported an error
			$this->maybe_stop_processing();
		}

		// All fine, success
		$this->finish();
	}

	/**
	 * Restore the submitted ACF query row for dynamic data rendering.
	 *
	 * Each query and row is resolved again from stored Bricks data, from the
	 * outermost loop to the ACF loop containing the submitted form.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @param int    $post_id Post ID containing the submitted form.
	 * @param string $form_element_id Submitted form element ID.
	 * @param string $form_component_id Submitted component instance ID.
	 *
	 * @return void
	 */
	private function set_query_context( $post_id, $form_element_id, $form_component_id = '' ) {
		// Older/cached forms do not submit this payload and continue through the legacy path.
		if (
			! isset( $_POST['formQueryContext'], $_POST['formQueryNonce'] ) ||
			! is_string( $_POST['formQueryContext'] ) ||
			! is_string( $_POST['formQueryNonce'] )
		) {
			return;
		}

		$submitted_context = json_decode( wp_unslash( $_POST['formQueryContext'] ), true );
		$query_nonce       = sanitize_text_field( wp_unslash( $_POST['formQueryNonce'] ) );

		if ( empty( $submitted_context ) || ! is_array( $submitted_context ) ) {
			return;
		}

		$query_context = [];

		// Canonicalize every signed value before rebuilding the nonce action.
		foreach ( $submitted_context as $context ) {
			if (
				! is_array( $context ) ||
				! isset( $context['queryId'], $context['queryIndex'] ) ||
				! is_scalar( $context['queryId'] ) ||
				! is_scalar( $context['queryIndex'] )
			) {
				return;
			}

			$query_element_id = sanitize_text_field( $context['queryId'] );
			$query_loop_index = sanitize_text_field( $context['queryIndex'] );

			if ( ! $query_element_id || $query_loop_index === '' || ! ctype_digit( $query_loop_index ) ) {
				return;
			}

			$query_context[] = [
				'queryId'    => $query_element_id,
				'queryIndex' => $query_loop_index,
			];
		}

		$query_nonce_action = 'bricks-form-query-' . wp_json_encode(
			[ (string) $post_id, $query_context, (string) $form_element_id, (string) $form_component_id ]
		);

		// Prevent clients from substituting another post, query row, form, or component context.
		if ( ! wp_verify_nonce( $query_nonce, $query_nonce_action ) ) {
			return;
		}

		// Nested ACF providers resolve their results from the active parent query.
		\Bricks\Database::$page_data['preview_or_post_id'] = $post_id;

		foreach ( $query_context as $context ) {
			// Re-read every query from saved Bricks data; no query settings come from the request.
			$query_data = Helpers::get_element_data( $post_id, $context['queryId'] );

			if (
				empty( $query_data['element']['settings']['hasLoop'] ) ||
				empty( $query_data['element']['settings']['query'] )
			) {
				$this->destroy_query_context();
				return;
			}

			$query = new \Bricks\Query( $query_data['element'] );

			if ( ! $this->set_query_context_row( $query, (int) $context['queryIndex'] ) ) {
				$query->destroy();
				$this->destroy_query_context();
				return;
			}

			$this->query_context[] = $query;
		}

		$active_query = end( $this->query_context );

		// The signed context is only supported when its innermost row is an ACF array value.
		if (
			! $active_query ||
			strpos( $active_query->object_type, 'acf_' ) !== 0 ||
			is_a( $active_query->loop_object, 'WP_Post' ) ||
			is_a( $active_query->loop_object, 'WP_Term' ) ||
			is_a( $active_query->loop_object, 'WP_User' )
		) {
			$this->destroy_query_context();
		}
	}

	/**
	 * Select a saved query row without rendering its elements.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @param \Bricks\Query $query Query instance to position.
	 * @param int           $query_loop_index Rendered loop index.
	 *
	 * @return boolean
	 */
	private function set_query_context_row( $query, $query_loop_index ) {
		$query_result = $query->object_type === 'post' && is_a( $query->query_result, 'WP_Query' ) ? $query->query_result->posts : $query->query_result;

		if ( ! is_array( $query_result ) ) {
			return false;
		}

		// Respect pagination, offsets, and result filters when mapping the rendered row index.
		$result_position = $query_loop_index - (int) $query->init_loop_index();
		$result_keys     = array_keys( $query_result );

		if ( $result_position < 0 || ! isset( $result_keys[ $result_position ] ) ) {
			return false;
		}

		$result_key        = $result_keys[ $result_position ];
		$loop_object       = $query_result[ $result_key ];
		$query->loop_index = $query_loop_index;
		$query->is_looping = true;

		if ( $query->object_type === 'post' ) {
			global $post;

			$post = get_post( $loop_object );

			if ( ! $post ) {
				return false;
			}

			setup_postdata( $post );

			$query->loop_object = $post;
		} elseif ( in_array( $query->object_type, [ 'term', 'user' ], true ) ) {
			$query->loop_object = $loop_object;
		} else {
			$query->loop_object = apply_filters( 'bricks/query/loop_object', $loop_object, $result_key, $query );
		}

		return true;
	}

	/**
	 * Remove partially rebuilt queries after invalid or stale context data.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @return void
	 */
	private function destroy_query_context() {
		foreach ( $this->query_context as $query ) {
			$query->destroy();
		}

		$this->query_context = [];

		// A partially restored post query may have changed the global post.
		wp_reset_postdata();
	}

	/**
	 * If there are any errors, stop execution
	 *
	 * @return void
	 */
	private function maybe_stop_processing() {
		$errors = ! empty( $this->results['error'] ) && is_array( $this->results['error'] ) ? $this->results['error'] : [];

		// type 'danger' used before 1.7.1
		if ( ! count( $errors ) && ! empty( $this->results['danger'] ) && is_array( $this->results['danger'] ) ) {
			$errors = $this->results['danger'];
		}

		if ( ! count( $errors ) ) {
			return;
		}

		// Get last error
		$error = array_pop( $errors );

		// Remove uploaded files, if exist
		$this->remove_files();

		// Leave
		wp_send_json_error( $error );
	}

	private function finish() {
		$form_settings = $this->form_settings;

		// Handle uploaded files after finishing all actions (@since 1.9.2)
		$this->handle_uploaded_files();

		// Basic response
		$response = [
			'type'    => 'success',
			'message' => isset( $form_settings['successMessage'] ) ? $this->render_data( $form_settings['successMessage'] ) : esc_html__( 'Success', 'bricks' ),
		];

		// Action 'updatePost': Set reset to false (@since 2.1)
		if ( $this->update_post_executed ) {
			$response['reset'] = false;
		}

		if ( empty( $this->results ) ) {
			wp_send_json_success( $response );
		}

		// Check for redirects
		if ( ! empty( $this->results['redirect'] ) ) {
			$redirect                    = array_pop( $this->results['redirect'] );
			$post_id                     = ! empty( $_POST['loopId'] ) ? absint( $_POST['loopId'] ) : get_the_ID(); // Get query loop post ID to parse dynamic data (@since 1.11.1)
			$response['redirectTo']      = ! empty( $redirect['redirectTo'] ) ? bricks_render_dynamic_data( $redirect['redirectTo'], $post_id ) : '';
			$response['redirectTimeout'] = $redirect['redirectTimeout'] ?? 0;
		}

		// Check for 'info' messages (e.g. Mailchimp pending message)
		if ( ! empty( $this->results['info'] ) ) {
			foreach ( $this->results['info'] as $info ) {
				if ( ! empty( $info['message'] ) ) {
					$response['info'][] = $info['message'];
				}
			}
		}

		// Check for 'success' messages (e.g. custom bricks/form/validate) (@since 1.7.1)
		if ( ! empty( $this->results['success'] ) ) {
			foreach ( $this->results['success'] as $success ) {
				if ( ! empty( $success['message'] ) ) {
					$response['message'] = $success['message'];
				}

				// Check for 'refreshPage' flag (@since 1.11.1)
				if ( isset( $success['refreshPage'] ) ) {
					$response['refreshPage'] = $success['refreshPage'];
				}
			}
		}

		// NOTE: Undocumented
		$response = apply_filters( 'bricks/form/response', $response, $this );

		// Evaluate results
		wp_send_json_success( $response );
	}

	/**
	 * Set action result
	 *
	 * type: success OR danger
	 *
	 * @param array $result
	 * @return void
	 */
	public function set_result( $result ) {
		$type                     = isset( $result['type'] ) ? $result['type'] : 'success';
		$this->results[ $type ][] = $result;
	}

	/**
	 * Getters
	 */
	public function get_settings() {
		return $this->form_settings;
	}

	public function get_fields() {
		return $this->form_fields;
	}

	public function get_id() {
		return $this->form_id;
	}

	public function get_post_id() {
		return $this->post_id;
	}

	public function get_uploaded_files() {
		return $this->uploaded_files;
	}

	public function get_results() {
		return $this->results;
	}

	/**
	 * Get server-verified existing file IDs submitted for a file field.
	 *
	 * Hidden existing-file inputs are client-controlled, so only IDs that
	 * still exist in the mapped post meta are allowed.
	 *
	 * @param string $field_id         Field ID.
	 * @param int    $post_id          Post ID.
	 * @param array  $submitted_fields Submitted form fields.
	 *
	 * @return array
	 *
	 * @since 2.3.9
	 */
	public function get_verified_existing_file_ids( $field_id, $post_id = 0, $submitted_fields = [] ) {
		if ( empty( $field_id ) ) {
			return [];
		}

		$normalize_attachment_ids = function( $values ) use ( &$normalize_attachment_ids ) {
			$attachment_ids = [];
			$values         = is_array( $values ) ? $values : [ $values ];

			foreach ( $values as $value ) {
				if ( is_array( $value ) ) {
					if ( isset( $value['ID'] ) || isset( $value['id'] ) ) {
						$value = $value['ID'] ?? $value['id'] ?? 0;
					} else {
						$attachment_ids = array_merge( $attachment_ids, $normalize_attachment_ids( $value ) );
						continue;
					}
				}

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				foreach ( explode( ',', (string) $value ) as $attachment_id ) {
					$attachment_id = absint( trim( $attachment_id ) );

					if ( $attachment_id ) {
						$attachment_ids[] = $attachment_id;
					}
				}
			}

			return array_values( array_unique( $attachment_ids ) );
		};

		if ( empty( $submitted_fields ) ) {
			$submitted_fields = $this->get_fields();
		}

		$submitted_attachment_ids = $normalize_attachment_ids( $submitted_fields['bricks_form_existing_files'][ $field_id ] ?? [] );
		$form_settings            = $this->get_settings();
		$update_post_meta         = $form_settings['updatePostMeta'] ?? [];

		if ( ! $post_id ) {
			$actions = $form_settings['actions'] ?? [];
			$post_id = $form_settings['updatePostId'] ?? '';

			if ( ! $post_id && is_array( $actions ) && in_array( 'update-post', $actions, true ) ) {
				$post_id = ! empty( $submitted_fields['loopId'] ) ? $submitted_fields['loopId'] : $this->get_post_id();
			}
		}

		$post_id = is_scalar( $post_id ) ? absint( $post_id ) : 0;

		if ( empty( $submitted_attachment_ids ) || ! $post_id || ! is_array( $update_post_meta ) ) {
			return [];
		}

		foreach ( $update_post_meta as $post_meta ) {
			if ( empty( $post_meta['metaKey'] ) || ! is_scalar( $post_meta['metaKey'] ) || empty( $post_meta['metaValue'] ) || $post_meta['metaValue'] !== $field_id ) {
				continue;
			}

			$allowed_attachment_ids = $normalize_attachment_ids( get_post_meta( $post_id, (string) $post_meta['metaKey'], false ) );

			return array_values( array_intersect( $submitted_attachment_ids, $allowed_attachment_ids ) );
		}

		return [];
	}

	/**
	 * Helper function to check for max-length of form fields
	 *
	 * @since 1.11.1
	 */
	private function check_max_length() {
		$form_settings = $this->form_settings;
		$form_fields   = $this->form_fields;

		// Check for max-length of fields
		foreach ( $form_settings['fields'] as $field ) {
			$field_id = $field['id'] ?? '';

			// Skip if field ID is empty (should not happen)
			if ( empty( $field_id ) ) {
				continue;
			}

			$field_value = $form_fields[ "form-field-{$field_id}" ] ?? '';

			// Get max length: Empty if not defined, or the value from the field settings
			$max_length = isset( $field['maxLength'] ) ? $field['maxLength'] : false;

			// Ensure max_length is an positive integer
			if ( ! is_numeric( $max_length ) || $max_length < 0 ) {
				$max_length = false;
			}

			if ( $max_length === false && $field['type'] === 'email' ) {
				// If no value is set for email, enforce 320 (RCF 3696 - https://datatracker.ietf.org/doc/html/rfc3696.html)
				$max_length = 320;
			}

			// Check for max-length
			if ( $max_length !== false && strlen( $field_value ) > $max_length ) {
				// If field label is not set, use the field ID
				$field_label = $field['label'] ?? $field_id;
				$this->set_error_messages( sprintf( esc_html__( 'The field "%s" exceeds the allowed maximum length of %2$d characters.', 'bricks' ), $field_label, $max_length ) );
				$this->maybe_stop_processing();
			}
		}
	}

	/**
	 * Helper function to convert a comma-separated list of file extensions to an array of MIME types
	 *
	 * @param string $extensions Comma-separated list of file extensions.
	 * @return array Array of corresponding MIME types.
	 *
	 * @since 1.9.3
	 */
	public function extensions_to_mime_types( $extensions ) {
		$all_mime_types     = get_allowed_mime_types(); // Retrieve list of allowed mime types and file extensions
		$extensions_array   = array_map( 'trim', explode( ',', $extensions ) ); // Convert the comma-separated string to an array
		$allowed_mime_types = [];

		foreach ( $extensions_array as $extension ) {
			// Loop through the array to find the MIME type for each extension. (e.g. 'jpg' => 'image/jpeg' & 'pdf' => 'application/pdf')
			foreach ( $all_mime_types as $ext_pattern => $mime ) {
				if ( preg_match( "!^($ext_pattern)$!i", $extension ) ) {
					$allowed_mime_types[] = $mime;
					break;
				}
			}
		}

		// 'finfo' often detect .m4a files as 'video/mp4' even if they only contain audio.
		// We add this as a fallback to ensure valid M4A uploads aren't rejected by strict MIME checks. (@since 2.3.2)
		$normalized_extensions = array_map( 'strtolower', $extensions_array );
		if ( $extensions_array && in_array( 'm4a', $normalized_extensions, true ) ) {
			$allowed_mime_types[] = 'video/mp4';
		}

		return $allowed_mime_types;
	}

	/**
	 * Helper function to convert unsupported MIME types to supported MIME types (e.g. 'audio/x-wav' to 'audio/wav')
	 *
	 * @param string $mime_type MIME type to convert
	 * @return string Converted MIME type
	 *
	 * @since 1.10
	 */
	private function convert_to_supported_mime_type( $mime_type ) {
		// List of unsupported MIME types
		$unsupported_mime_types = [
			'audio/x-wav' => 'audio/wav',
			'audio/x-m4a' => 'audio/mpeg',
			'image/x-eps' => 'application/postscript',
		];

		// Convert unsupported MIME types to supported MIME types
		return $unsupported_mime_types[ $mime_type ] ?? $mime_type;
	}

	/**
	 * Handle with any files uploaded with form
	 */
	public function handle_files() {
		if ( empty( $_FILES ) ) {
			return [];
		}

		// https://developer.wordpress.org/reference/functions/wp_handle_upload/
		$overrides = [ 'action' => 'bricks_form_submit' ];

		$uploaded_files = [];

		$all_mime_types = get_allowed_mime_types();

		// Each form may have more than one input file type, each may have multiple files
		foreach ( $_FILES as $input_name => $files ) {
			if ( empty( $files['name'] ) ) {
				continue;
			}

			// Retrieve allowed mime types for this input field from form_settings (@since 1.9.3)
			$field_id                     = str_replace( 'form-field-', '', $input_name );
			$allowed_mime_types_for_field = $all_mime_types; // Default to default mime types if no mime types are set in the form field settings

			foreach ( $this->form_settings['fields'] as $field ) {
				// Maybe custom field name in used
				if ( ( $field['id'] === $field_id ) ||
					( ! empty( $field['name'] ) && $field['name'] === $field_id )
				) {
					// Retrieve allowed file extensions if any are set
					if ( ! empty( $field['fileUploadAllowedTypes'] ) ) {
						$allowed_file_extensions = $field['fileUploadAllowedTypes'] ?? '';

						// We need to render dynamic data in the allowed file extensions field (@since 2.1.3)
						$allowed_file_extensions = $this->render_data( $allowed_file_extensions );

						// Convert the extensions to mime types
						$allowed_mime_types_for_field = $this->extensions_to_mime_types( $allowed_file_extensions );
					}

					break;
				}
			}

			foreach ( $files['name'] as $key => $value ) {
				$finfo          = finfo_open( FILEINFO_MIME_TYPE );
				$real_mime_type = finfo_file( $finfo, $files['tmp_name'][ $key ] );

				finfo_close( $finfo );

				// Convert unsupported MIME types to supported MIME types
				$real_mime_type = $this->convert_to_supported_mime_type( $real_mime_type );

				// Check mime type (@since 1.9.3)
				if ( ! in_array( $real_mime_type, $allowed_mime_types_for_field, true ) ) {
					$this->set_error_messages( esc_html__( 'Uploaded file type is not allowed.', 'bricks' ) );
					$this->maybe_stop_processing();
					continue;
				}

				if ( empty( $files['name'][ $key ] ) || $files['error'][ $key ] !== UPLOAD_ERR_OK ) {
					continue;
				}

				$file = [
					'name'     => $files['name'][ $key ],
					'type'     => $files['type'][ $key ],
					'tmp_name' => $files['tmp_name'][ $key ],
					'error'    => $files['error'][ $key ],
					'size'     => $files['size'][ $key ],
				];

				// Temporarily upload file to 'uploads' folder to sent as email attachment, etc. (no sizes are generated)
				$uploaded = wp_handle_upload( $file, $overrides );

				// Upload success: Uploaded to 'uploads' folder
				if ( $uploaded && ! isset( $uploaded['error'] ) ) {
					/**
					 * STEP: Save uploaded file in custom directory (if set in form field setting)
					 *
					 * @since 1.9.2
					 */

					// Get file settings
					$save_file      = false;
					$field_id       = str_replace( 'form-field-', '', $input_name );
					$fields         = $this->form_settings['fields'] ?? [];
					$directory_name = false;

					foreach ( $fields as $field ) {
						if ( ( $field['id'] === $field_id ) ||
							( ! empty( $field['name'] ) && $field['name'] === $field_id )
						) {
							$save_file = $field['fileUploadStorage'] ?? false;

							if ( $save_file === 'directory' && ! empty( $field['fileUploadStorageDirectory'] ) ) {
								$directory_name = $this->render_data( $field['fileUploadStorageDirectory'] );
							}
						}
					}

					// Get directory path (e.g.: uploads/{directory_name})
					if ( $save_file === 'directory' ) {
						$directory_name = sanitize_file_name( $directory_name );
						$wp_upload_dir  = wp_upload_dir();
						$directory_path = $wp_upload_dir['basedir'] . "/$directory_name";
						$original_path  = $directory_path;

						// Apply Bricks filter for custom path (https://academy.bricksbuilder.io/article/filter-bricks-form-file_directory/)
						$directory_path = apply_filters( 'bricks/form/file_directory', $directory_path, $this, $input_name );

						// Directory path changed via filter above: Set $save_file to 'filter'
						if ( $directory_path !== $original_path ) {
							$save_file = 'filter';
						}

						// Create custom directory if needed
						if ( $directory_path && ! file_exists( $directory_path ) ) {
							wp_mkdir_p( $directory_path );
						}

						// Copy uploaded file to custom directory & remove the file if copy success from 'uploads' folder
						if ( $directory_path && is_dir( $directory_path ) && is_writable( $directory_path ) ) {
							$new_file_name = wp_unique_filename( $directory_path, $file['name'] );
							$new_path      = "$directory_path/$new_file_name";
							$copy          = copy( $uploaded['file'], $new_path );

							if ( $copy ) {
								// Remove the file if copy success
								@unlink( $uploaded['file'] );

								// Update file path to the new path (use in email attachment or handle_uploaded_files())
								$uploaded['file'] = $new_path;
							}
						}
					}

					// Add type, name to the uploaded file
					$uploaded['type'] = $file['type'];
					$uploaded['name'] = $file['name'];

					// For use in form submissions table (attachment, directory, filter)
					$uploaded['location'] = $save_file;

					$uploaded_files[ $input_name ][] = $uploaded;
				}
			}
		}

		return $uploaded_files;
	}

	/**
	 * Remove (default), keep uploaded or move files to media library (as attachment)
	 *
	 * @param string $mode 'early' = Only process attachments for create-post/update-post actions
	 *                     'final' = Process all remaining files after actions complete (@since 2.2)
	 *
	 * @since 1.9.2
	 */
	public function handle_uploaded_files( $mode = 'final' ) {
		$uploaded_files = $this->get_uploaded_files();

		if ( empty( $uploaded_files ) ) {
			return;
		}

		// Loop through uploaded files
		foreach ( $uploaded_files as $input_name => $files ) {
			// Get file settings
			$save_file = false;
			$field_id  = str_replace( 'form-field-', '', $input_name );
			$fields    = $this->form_settings['fields'] ?? [];

			foreach ( $fields as $field ) {
				if ( ( $field['id'] === $field_id ) ||
					( ! empty( $field['name'] ) && $field['name'] === $field_id )
				) {
					$save_file = $field['fileUploadStorage'] ?? 'no';
				}
			}

			// Early mode: Only process files that should be saved as attachments (@since 2.2)
			if ( $mode === 'early' && $save_file !== 'attachment' ) {
				continue;
			}

			switch ( $save_file ) {
				case 'attachment':
					// Move uploaded files to media library as attachment
					foreach ( $files as $index => $file ) {
						// Skip if already processed in early mode (@since 2.2)
						if ( isset( $file['attachment_id'] ) ) {
							continue;
						}

						$attachment = [
							'post_mime_type' => $file['type'],
							'post_title'     => sanitize_file_name( $file['name'] ),
							'post_content'   => '',
							'post_status'    => 'inherit',
						];

						// Insert file as attachment
						$attachment_id = wp_insert_attachment( $attachment, $file['file'] );

						if ( $attachment_id ) {
							// Add attachment metadata from file
							$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file['file'] );
							wp_update_attachment_metadata( $attachment_id, $attachment_data );

							// Store attachment ID in early mode for create-post/update-post actions (@since 2.2)
							if ( $mode === 'early' ) {
								$this->uploaded_files[ $input_name ][ $index ]['attachment_id'] = $attachment_id;
							}
						}
					}
					break;

				case 'directory':
					// Move uploaded file to custom directory
					break;

				default:
					// Default: Remove uploaded files (only in final mode @since 2.2)
					if ( $mode === 'final' ) {
						foreach ( $files as $file ) {
							@unlink( $file['file'] );
						}
					}
					break;
			}
		}
	}

	/**
	 * Handle file uploads early if an action needs attachment IDs.
	 *
	 * Create post, update post, and webhook actions need media-library data before
	 * they run.
	 *
	 * @since 2.2
	 */
	private function maybe_handle_files_early() {
		$needs_early_upload = false;
		$actions            = $this->form_settings['actions'] ?? [];

		foreach ( [ 'create-post', 'update-post', 'webhook' ] as $action ) {
			if ( in_array( $action, $actions, true ) && $this->should_run_action( $action ) ) {
				$needs_early_upload = true;
				break;
			}
		}

		if ( ! $needs_early_upload ) {
			return;
		}

		// Process files in early mode (only attachments)
		$this->handle_uploaded_files( 'early' );
	}

	/**
	 * Check whether a form action meets all of its configured conditions.
	 *
	 * Multiple condition rows for the same action are combined with AND logic so
	 * adding a row cannot broaden when an action runs.
	 *
	 * @since 2.4
	 *
	 * @param string $form_action Form action key.
	 * @return bool
	 */
	private function should_run_action( $form_action ) {
		$action_conditions = $this->form_settings['actionConditions'] ?? [];

		if ( ! is_array( $action_conditions ) ) {
			return true;
		}

		foreach ( $action_conditions as $action_condition ) {
			if ( ! is_array( $action_condition ) || ( $action_condition['action'] ?? '' ) !== $form_action ) {
				continue;
			}

			$conditions = $action_condition['conditions'] ?? [];

			if ( ! is_array( $conditions ) || ! count( $conditions ) ) {
				return false;
			}

			$relation    = ( $action_condition['conditionsRelation'] ?? 'and' ) === 'or' ? 'or' : 'and';
			$row_matches = $relation === 'and';

			foreach ( $conditions as $condition ) {
				$condition_matches = $this->is_action_condition_met( $condition );

				if ( $condition_matches === null ) {
					return false;
				}

				$row_matches = $relation === 'or'
					? $row_matches || $condition_matches
					: $row_matches && $condition_matches;
			}

			if ( ! $row_matches ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether an individual form action condition matches submitted data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $condition Action condition settings.
	 * @return bool|null Null if the condition is incomplete or invalid.
	 */
	private function is_action_condition_met( $condition ) {
		if ( ! is_array( $condition ) ) {
			return null;
		}

		$field_id = $condition['fieldId'] ?? '';
		$compare  = $condition['compare'] ?? '';

		if ( ! is_string( $field_id ) ) {
			return null;
		}

		if ( ! in_array( $compare, [ '==', '!=', 'contains', 'not_contains', 'empty', 'not_empty' ], true ) ) {
			return null;
		}

		$field = $this->get_action_condition_field( $field_id );

		if ( $field === null ) {
			return null;
		}

		$field_value = $this->get_action_condition_field_value( $field );

		if ( $compare === 'empty' ) {
			return $this->is_action_condition_value_empty( $field_value );
		}

		if ( $compare === 'not_empty' ) {
			return ! $this->is_action_condition_value_empty( $field_value );
		}

		$condition_value = $condition['value'] ?? '';

		if ( ! is_scalar( $condition_value ) || $condition_value === '' ) {
			return null;
		}

		$condition_value = (string) $this->render_data( (string) $condition_value );

		if ( $condition_value === '' ) {
			return null;
		}

		$field_values = is_array( $field_value ) ? $field_value : [ $field_value ];
		$matches      = false;
		$contains     = in_array( $compare, [ 'contains', 'not_contains' ], true );

		foreach ( $field_values as $value ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$value = (string) $value;

			if ( $contains ? strpos( $value, $condition_value ) !== false : $value === $condition_value ) {
				$matches = true;
				break;
			}
		}

		return in_array( $compare, [ '!=', 'not_contains' ], true ) ? ! $matches : $matches;
	}

	/**
	 * Get a submittable form field's settings for an action condition.
	 *
	 * @since 2.4
	 *
	 * @param string $field_id Form field ID.
	 * @return array|null
	 */
	private function get_action_condition_field( $field_id ) {
		$fields = $this->form_settings['fields'] ?? [];

		if ( ! is_array( $fields ) ) {
			return null;
		}

		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ( $field['id'] ?? '' ) === $field_id ) {
				return ( $field['type'] ?? '' ) === 'html' ? null : $field;
			}
		}

		return null;
	}

	/**
	 * Get a submitted field value for an action condition.
	 *
	 * File inputs are absent from POST data, so compare new uploads and verified
	 * files retained by an Update post form.
	 *
	 * @since 2.4
	 *
	 * @param array $field Form field settings.
	 * @return mixed
	 */
	private function get_action_condition_field_value( $field ) {
		$field_id = $field['id'] ?? '';

		if ( ( $field['type'] ?? '' ) !== 'file' ) {
			return $this->get_field_value( $field_id );
		}

		$input_name     = ! empty( $field['name'] ) ? $field['name'] : "form-field-{$field_id}";
		$uploaded_files = $this->get_uploaded_files();
		$files          = is_array( $uploaded_files ) && ! empty( $uploaded_files[ $input_name ] ) ? $uploaded_files[ $input_name ] : [];
		$filenames      = [];

		foreach ( $files as $file ) {
			if ( is_array( $file ) && isset( $file['name'] ) && is_scalar( $file['name'] ) ) {
				$filenames[] = (string) $file['name'];
			}
		}

		foreach ( $this->get_verified_existing_file_ids( $field_id ) as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			$file_name = $file_path ? wp_basename( $file_path ) : get_the_title( $attachment_id );

			// A verified attachment ID still represents a non-empty file if its metadata is incomplete.
			$filenames[] = $file_name ? $file_name : (string) $attachment_id;
		}

		return $filenames;
	}

	/**
	 * Check whether a submitted form value is empty without treating zero as empty.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Submitted field value.
	 * @return bool
	 */
	private function is_action_condition_value_empty( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->is_action_condition_value_empty( $item ) ) {
					return false;
				}
			}

			return true;
		}

		return $value === null || $value === '';
	}

	/**
	 * Eventually remove uploaded files
	 */
	public function remove_files() {
		$uploaded_files = $this->get_uploaded_files();

		if ( empty( $uploaded_files ) ) {
			return;
		}

		// Remove uploaded files
		foreach ( $uploaded_files as $input_name => $files ) {
			foreach ( $files as $file ) {
				@unlink( $file['file'] );
			}
		}
	}

	/**
	 * Replace any {{field_id}} by the submitted form field content and after renders dynamic data
	 *
	 * @param string $content
	 */
	public function render_data( $content, $has_file_properties = false ) {
		// \w: Matches any word character (alphanumeric & underscore).
		// Only matches low-ascii characters (no accented or non-roman characters).
		// Equivalent to [A-Za-z0-9_]
		// https://regexr.com/

		if ( $has_file_properties ) {
			preg_match_all( '/{{(\w+)(?::(\w+))?}}/', $content, $matches, PREG_SET_ORDER );

			if ( ! empty( $matches ) ) {
				foreach ( $matches as $match ) {
					// Format: '{{zjkcdw}}' or '{{zjkcdw:url}}'
					$tag      = $match[0];
					$field_id = $match[1];
					$property = $match[2] ?? '';

					if ( $property ) {
						$value = $this->get_file_field_property( $field_id, $property );
					} else {
						$value = $this->get_field_value( $field_id );
						$value = ! empty( $value ) && is_array( $value ) ? implode( ', ', $value ) : $value;
					}

					$content = str_replace( $tag, $value, $content );
				}
			}
		} else {
			preg_match_all( '/{{(\w+)}}/', $content, $matches );

			if ( ! empty( $matches[0] ) ) {
				foreach ( $matches[1] as $key => $field_id ) {
					// Format: '{{zjkcdw}}' // Dynamic email data format
					$tag = $matches[0][ $key ];

					$value = $this->get_field_value( $field_id );

					$value = ! empty( $value ) && is_array( $value ) ? implode( ', ', $value ) : $value;

					$content = str_replace( $tag, $value, $content );
				}
			}
		}

		$fields = $this->get_fields();

		/**
		 * ACF rows need the containing page as the post context while their rebuilt
		 * Query supplies the row context. All other submissions keep the legacy loopId.
		 *
		 * #86cazz5mc; @since 2.3.11
		 */
		$loop_post_id = $this->query_context ? $this->post_id : ( isset( $fields['loopId'] ) ? absint( $fields['loopId'] ) : 0 );

		// Render dynamic data
		$content = bricks_render_dynamic_data( $content, $loop_post_id );

		return $content;
	}

	/**
	 * Get value of individual form field by field ID
	 *
	 * @param string $field_id Field ID.
	 */
	public function get_field_value( $field_id = '' ) {
		$form_fields = $this->get_fields();

		// NOTE: Undocumented {{referrer_url}}
		if ( $field_id === 'referrer_url' && isset( $_POST['referrer'] ) ) {
			return esc_url( $_POST['referrer'] );
		}

		if ( empty( $field_id ) || ! array_key_exists( "form-field-{$field_id}", $form_fields ) ) {
			return '';
		}

		return $form_fields[ "form-field-{$field_id}" ];
	}

	/**
	 * Get property of file field by field ID
	 *
	 * @param string $field_id Field ID.
	 * @param string $property Property name (name, type, size, id, url).
	 *
	 * @return string
	 * @since 2.2
	 */
	public function get_file_field_property( $field_id, $property ) {
		$uploaded_files = $this->get_uploaded_files();

		// Find input name
		$input_name = "form-field-$field_id";

		if ( ! empty( $this->form_settings['fields'] ) ) {
			foreach ( $this->form_settings['fields'] as $field ) {
				if ( $field['id'] === $field_id && ! empty( $field['name'] ) ) {
					$input_name = $field['name'];
					break;
				}
			}
		}

		if ( empty( $uploaded_files[ $input_name ] ) ) {
			return '';
		}

		$files  = $uploaded_files[ $input_name ];
		$values = [];

		foreach ( $files as $file ) {
			switch ( $property ) {
				case 'name':
					$values[] = $file['name'];
					break;
				case 'type':
					$values[] = $file['type'];
					break;
				case 'size':
					$values[] = $file['size'];
					break;
				case 'id':
					if ( isset( $file['attachment_id'] ) ) {
						$values[] = $file['attachment_id'];
					}
					break;
				case 'url':
					if ( isset( $file['attachment_id'] ) ) {
						$values[] = wp_get_attachment_url( $file['attachment_id'] );
					} elseif ( isset( $file['file'] ) ) {
						$upload_dir = wp_upload_dir();
						if ( strpos( $file['file'], $upload_dir['basedir'] ) === 0 ) {
							$values[] = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file['file'] );
						}
					}
					break;
			}
		}

		return implode( ', ', $values );
	}

	/**
	 * Available actions after form submission
	 *
	 * @return array
	 */
	public static function get_available_actions() {
		$actions = [
			'custom'         => esc_html__( 'Custom', 'bricks' ),
			'email'          => esc_html__( 'Email', 'bricks' ),
			'webhook'        => esc_html__( 'Webhook', 'bricks' ),
			'redirect'       => esc_html__( 'Redirect', 'bricks' ),
			'mailchimp'      => 'Mailchimp',
			'sendgrid'       => 'SendGrid',
			'login'          => esc_html__( 'User login', 'bricks' ),
			'registration'   => esc_html__( 'User registration', 'bricks' ),
			'lost-password'  => esc_html__( 'Lost password', 'bricks' ),
			'reset-password' => esc_html__( 'Reset password', 'bricks' ),
			'create-post'    => esc_html__( 'Create post', 'bricks' ), // @since 2.1
			'update-post'    => esc_html__( 'Update post', 'bricks' ), // @since 2.1
		];

		if ( \Bricks\Database::get_setting( 'passwordProtectionEnabled', false ) ) {
			$actions['unlock-password-protection'] = esc_html__( 'Unlock password protection', 'bricks' );
		}

		// Save form submission (@since 1.9.2)
		if ( \Bricks\Database::get_setting( 'saveFormSubmissions', false ) ) {
			$actions['save-submission'] = esc_html__( 'Save submission', 'bricks' );
		}

		return $actions;
	}

	/**
	 * Set form submit error messages
	 *
	 * @param array $error_messages
	 *
	 * @since 1.7.1
	 */
	public function set_error_messages( $error_messages ) {
		if ( empty( $error_messages ) ) {
			return;
		}

		if ( is_string( $error_messages ) ) {
			$error_messages = [ $error_messages ];
		}

		// One error: Return error message as string
		if ( count( $error_messages ) === 1 ) {
			$this->set_result(
				[
					'type'    => 'error',
					'message' => $error_messages,
				]
			);

			return;
		}

		// More than one error: Return error messages as unordered list
		$message = '<ul>';

		// Combine $error_messages into a single string
		foreach ( $error_messages as $error_message ) {
			$message .= "<li>{$error_message}</li>";
		}

		$message .= '</ul>';

		$this->set_result(
			[
				'type'    => 'error',
				'message' => $message,
			]
		);
	}

	/**
	 * Validate required fields
	 *
	 * @param array|string $custom_validation_errors Custom validation errors adding via filter 'bricks_form_validation_errors'.
	 *
	 * @return array
	 *
	 * @since 1.7.1
	 */
	public function validate_required_fields( $custom_validation_errors = [] ) {
		$submitted_fields     = $this->get_fields();
		$uploaded_files       = $this->get_uploaded_files();
		$form_settings        = $this->get_settings();
		$form_settings_fields = ! empty( $form_settings['fields'] ) ? $form_settings['fields'] : [];

		$errors = [];

		foreach ( $form_settings_fields as $form_settings_field ) {
			// Skip if field is not required
			if ( empty( $form_settings_field['required'] ) ) {
				continue;
			}

			// Skip if field type is 'html' or 'hidden' (@since 1.12)
			if ( in_array( $form_settings_field['type'], [ 'html', 'hidden' ], true ) ) {
				continue;
			}

			// Skip if field is Honeypot (@since 1.12.2)
			if ( isset( $form_settings_field['isHoneypot'] ) ) {
				continue;
			}

			$error = false;

			// File field: Check if file is uploaded
			if ( $form_settings_field['type'] === 'file' ) {
				$field_id   = $form_settings_field['id'];
				$field_name = "form-field-$field_id"; // default field name

				// Check for and use custom field name
				if ( ! empty( $form_settings_field['name'] ) ) {
					$field_name = $form_settings_field['name'];
				}

				$existing_files = $this->get_verified_existing_file_ids( $field_id );

				if ( empty( $uploaded_files[ $field_name ] ) && empty( $existing_files ) ) {
					$error = true;
				}
			}

			// All other field types
			else {
				if (
					! isset( $submitted_fields[ "form-field-{$form_settings_field['id']}" ] ) ||
					$submitted_fields[ "form-field-{$form_settings_field['id']}" ] === ''
				) {
					$error = true;
				}
			}

			if ( $error ) {
				// Field is required & empty: Add error message
				$field_label = ! empty( $form_settings_field['label'] ) ? $form_settings_field['label'] : $form_settings_field['type'];

				$errors[] = esc_html__( 'Required', 'bricks' ) . ': ' . esc_html( $field_label );
			}
		}

		// Custom validation error is a string: Convert to array
		if ( $custom_validation_errors && is_string( $custom_validation_errors ) ) {
			$custom_validation_errors = [ $custom_validation_errors ];
		}

		// Filter out empty error strings
		if ( is_array( $custom_validation_errors ) && count( $custom_validation_errors ) ) {
			$custom_validation_errors = array_filter( $custom_validation_errors );

			$errors = array_merge( $errors, $custom_validation_errors );
		}

		// Return: Array of validation errors (each error as a string, representing a single error message)
		return $errors;
	}

	/**
	 * Validate password confirmation
	 *
	 * Checks if password fields that require confirmation have matching passwords.
	 *
	 * @param array $custom_validation_errors
	 * @return array
	 *
	 * @since 2.4
	 */
	public function validate_password_confirmation( $custom_validation_errors = [] ) {
		$submitted_fields     = $this->get_fields();
		$form_settings_fields = ! empty( $this->form_settings['fields'] ) ? $this->form_settings['fields'] : [];

		$errors = is_array( $custom_validation_errors ) ? $custom_validation_errors : [];

		foreach ( $form_settings_fields as $form_settings_field ) {
			// Only check password fields with confirmation enabled
			if ( $form_settings_field['type'] !== 'password' || empty( $form_settings_field['passwordIsConfirmation'] ) ) {
				continue;
			}

			// Get the main password field ID
			$main_field_id = ! empty( $form_settings_field['passwordMainFieldId'] ) ? $form_settings_field['passwordMainFieldId'] : '';

			if ( empty( $main_field_id ) ) {
				continue;
			}

			// Get main password field value from submitted data
			$main_field_name = "form-field-{$main_field_id}";
			$main_password   = isset( $submitted_fields[ $main_field_name ] ) ? $submitted_fields[ $main_field_name ] : '';

			// Get confirmation password field value
			$confirmation_field_name = ! empty( $form_settings_field['name'] ) ? $form_settings_field['name'] : "form-field-{$form_settings_field['id']}";
			$confirmation_password   = isset( $submitted_fields[ $confirmation_field_name ] ) ? $submitted_fields[ $confirmation_field_name ] : '';

			// Keep optional password pairs valid when both fields are empty.
			if ( $main_password === '' && $confirmation_password === '' ) {
				continue;
			}

			// Check if passwords match
			if ( $main_password !== $confirmation_password ) {
				$error_message = ! empty( $form_settings_field['passwordConfirmationErrorMessage'] ) ?
				esc_html( $form_settings_field['passwordConfirmationErrorMessage'] ) :
				esc_html__( 'Passwords do not match', 'bricks' );

				$errors[] = $error_message;
			}
		}

		return $errors;
	}

	/**
	 * Validate email fields.
	 *
	 * @param array|string $custom_validation_errors Custom validation errors added via the 'bricks/form/validate' filter.
	 * @return array
	 *
	 * @since 2.3.10
	 */
	public function validate_email_fields( $custom_validation_errors = [] ) {
		$submitted_fields     = $this->get_fields();
		$form_settings        = $this->get_settings();
		$form_settings_fields = ! empty( $form_settings['fields'] ) ? $form_settings['fields'] : [];
		$errors               = is_array( $custom_validation_errors ) ? $custom_validation_errors : [];

		if ( is_string( $custom_validation_errors ) && $custom_validation_errors !== '' ) {
			$errors[] = $custom_validation_errors;
		}

		foreach ( $form_settings_fields as $form_settings_field ) {
			if ( ( $form_settings_field['type'] ?? '' ) !== 'email' || empty( $form_settings_field['id'] ) ) {
				continue;
			}

			$field_name  = "form-field-{$form_settings_field['id']}";
			$field_value = $submitted_fields[ $field_name ] ?? '';

			// Empty optional fields are valid. Required fields are handled separately.
			if ( $field_value === '' ) {
				continue;
			}

			if ( self::is_valid_email( $field_value ) ) {
				continue;
			}

			$errors[] = ! empty( $form_settings_field['errorMessage'] )
				? esc_html( $form_settings_field['errorMessage'] )
				: esc_html__( 'Email address is not valid.', 'bricks' );
		}

		return $errors;
	}

	/**
	 * Check whether an email address has valid syntax and local-part dot placement.
	 *
	 * WordPress allows leading, trailing, and consecutive dots in the local part,
	 * so those RFC-invalid placements need an explicit check.
	 *
	 * @param mixed $email Email address to validate.
	 * @return boolean
	 *
	 * @since 2.3.10
	 */
	public static function is_valid_email( $email ) {
		if ( ! is_string( $email ) || ! is_email( $email ) ) {
			return false;
		}

		$local_part = explode( '@', $email, 2 )[0];

		return $local_part[0] !== '.' && substr( $local_part, -1 ) !== '.' && strpos( $local_part, '..' ) === false;
	}

	/**
	 * Get a specific frontend URL parameter
	 * - Used by {url_parameter} DD
	 *
	 * @param string $parameter
	 * @since 1.11
	 */
	public static function get_submission_url_param( $parameter ) {
		return self::$submission_url_params[ $parameter ] ?? '';
	}

	/**
	 * Get ACF field key from meta key
	 *
	 * Supports nested fields inside groups, repeaters, and flexible content.
	 *
	 * @since 2.1
	 */
	public static function get_acf_field_key_from_meta_key( $meta_key = '', $post_id = '', $post_type = '' ) {
		$acf_field_key = '';

		// Return empty if ACF is not active
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $acf_field_key;
		}

		// Get field key from field groups associated with the post
		$group_query_args = [];

		if ( ! empty( $post_id ) ) {
			$group_query_args['post_id'] = $post_id;
		}

		if ( ! empty( $post_type ) ) {
			$group_query_args['post_type'] = $post_type;
		}

		$groups = acf_get_field_groups( $group_query_args );

		foreach ( $groups as $group ) {
			$fields = ! empty( $group['key'] ) ? acf_get_fields( $group['key'] ) : [];

			// STEP 1: Try simple direct match first (most common case - top-level fields)
			foreach ( $fields as $field ) {
				if ( ! empty( $field['name'] ) && $field['name'] === $meta_key && ! empty( $field['key'] ) ) {
					return $field['key'];
				}
			}

			// STEP 2: If not found and meta_key contains underscore, search nested fields
			if ( strpos( $meta_key, '_' ) !== false ) {
				$acf_field_key = self::search_acf_fields_for_meta_key( $fields, $meta_key );

				if ( $acf_field_key ) {
					return $acf_field_key;
				}
			}
		}

		return $acf_field_key;
	}

	/**
	 * Recursively search ACF fields for a matching meta key
	 *
	 * Handles nested fields in groups, repeaters, and flexible content.
	 *
	 * @param array  $fields   Array of ACF fields to search
	 * @param string $meta_key The meta key to find
	 * @param string $prefix   Meta key prefix from parent fields (for nested fields)
	 *
	 * @return string Field key if found, empty string otherwise
	 *
	 * @since 2.2
	 */
	private static function search_acf_fields_for_meta_key( $fields, $meta_key, $prefix = '' ) {
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return '';
		}

		foreach ( $fields as $field ) {
			$field_name = $field['name'] ?? '';

			if ( empty( $field_name ) ) {
				continue;
			}

			// Build the full meta key for this field (including parent prefixes)
			$full_meta_key = $prefix ? $prefix . '_' . $field_name : $field_name;

			// Check if this field matches the meta key
			if ( $full_meta_key === $meta_key && ! empty( $field['key'] ) ) {
				return $field['key'];
			}

			// Only recurse if the meta_key starts with the current field's full path
			// This optimization avoids searching branches that can't possibly match
			if ( strpos( $meta_key, $full_meta_key . '_' ) !== 0 ) {
				continue;
			}

			// Search in sub_fields (groups, repeaters, etc.)
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$found = self::search_acf_fields_for_meta_key( $field['sub_fields'], $meta_key, $full_meta_key );

				if ( $found ) {
					return $found;
				}
			}

			// Search in flexible content layouts
			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$found = self::search_acf_fields_for_meta_key( $layout['sub_fields'], $meta_key, $full_meta_key );

						if ( $found ) {
							return $found;
						}
					}
				}
			}
		}

		return '';
	}

	/**
	 * Get Meta Box field key from meta key
	 *
	 * @since 2.1
	 */
	public static function get_meta_box_field_key_from_meta_key( $meta_key, $post_id ) {
		// Return empty if Meta Box is not active
		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return '';
		}

		// Check if meta key exists in Meta Box fields
		$groups = rwmb_get_object_fields( $post_id );
		if ( isset( $groups[ $meta_key ] ) ) {
			return $meta_key;
		}

		return '';
	}

	/**
	 * Apply component property values to form settings
	 *
	 * When a form is inside a component, retrieve the component instance
	 * and apply property values to form settings
	 *
	 * @param int    $post_id           Post ID
	 * @param string $form_component_id Component instance ID
	 * @param string $form_element_id   Form element ID
	 *
	 * @since 2.2
	 */
	private function apply_component_properties( $post_id, $form_component_id, $form_element_id ) {
		$component_id     = $form_component_id;
		$instance_element = false;

		// Resolve by the submitted component instance first.
		$instance_data    = \Bricks\Helpers::get_element_data( $post_id, $form_component_id );
		$instance_element = $instance_data['element'] ?? false;

		// Component-root forms submit the form element ID as their instance context.
		if ( ! $instance_element || ! isset( $instance_element['cid'] ) ) {
			$instance_data    = \Bricks\Helpers::get_element_data( $post_id, $form_element_id );
			$instance_element = $instance_data['element'] ?? false;
		}

		if ( ( ! $instance_element || ! isset( $instance_element['cid'] ) ) && strpos( $form_component_id, '-' ) !== false ) {
			$slot_instance_id      = explode( '-', $form_component_id, 2 )[0] ?? '';
			$slot_instance_data    = \Bricks\Helpers::get_element_data( $post_id, $slot_instance_id );
			$slot_instance_element = $slot_instance_data['element'] ?? false;

			if ( $slot_instance_element && isset( $slot_instance_element['cid'] ) ) {
				$instance_element = $slot_instance_element;
			}
		}

		if ( $instance_element && isset( $instance_element['cid'] ) ) {
			$component_id = $instance_element['cid'];

			if ( ! isset( $instance_element['instanceId'] ) && strpos( $form_component_id, '-' ) !== false ) {
				// Nested component render IDs include the owner instance, which parent property bindings need during AJAX.
				$component_id_parts             = explode( '-', $form_component_id, 2 );
				$instance_element['instanceId'] = $component_id_parts[1] ?? '';
			}
		}

		// If not found in Bricks content, try Gutenberg blocks
		if ( ! $instance_element || ! isset( $instance_element['cid'] ) ) {
			// Extract component ID (Gutenberg uses format: component_id-uuid)
			$component_id = strpos( $form_component_id, '-' ) !== false ? explode( '-', $form_component_id )[0] : $form_component_id;

			// For Gutenberg, search using the ID that contains the UUID
			// - Form IS component: form_element_id has UUID, form_component_id doesn't
			// - Form INSIDE component: form_component_id has UUID
			$search_id = strpos( $form_component_id, '-' ) !== false ? $form_component_id : $form_element_id;

			$block_properties = $this->get_gutenberg_block_properties( $post_id, $component_id, $search_id );
			$component        = \Bricks\Helpers::get_component_by_cid( $component_id );

			if ( ! $component || empty( $component['elements'] ) ) {
				return;
			}

			// Create instance element for get_component_instance()
			$instance_element = [
				'id'         => $form_component_id,
				'cid'        => $component_id,
				'properties' => $block_properties,
			];
		}

		// Apply properties to component elements
		$component_instance = \Bricks\Helpers::get_component_instance( $instance_element );

		if ( empty( $component_instance['elements'] ) ) {
			return;
		}

		// Determine target element ID
		// - Bricks: form_element_id = component_id means form IS the component
		// - Gutenberg: form_element_id starts with component_id- means form IS the component
		$is_form_the_component = ( $form_element_id === $component_id ) || ( strpos( $form_element_id, $component_id . '-' ) === 0 );
		$target_id             = $is_form_the_component ? $component_id : $form_element_id;

		// Find and apply form settings with properties
		foreach ( $component_instance['elements'] as $element ) {
			if ( $element['id'] === $target_id && isset( $element['settings'] ) ) {
				$this->form_settings = $element['settings'];
				break;
			}
		}
	}

	/**
	 * Get form settings from expanded nested component data.
	 *
	 * A form component nested inside another component is not stored as a top-level
	 * page element, so Helpers::get_element_settings() can't resolve it during AJAX
	 * form submission. Expand component data for the current page/templates and look
	 * up the rendered form element there.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $form_element_id   Form element ID.
	 * @param string $form_component_id Optional rendered component context ID.
	 *
	 * @return array|false
	 *
	 * @since 2.3
	 */
	private function get_nested_form_settings( $post_id, $form_element_id, $form_component_id = '' ) {
		if ( ! $post_id || ! $form_element_id ) {
			return false;
		}

		if ( bricks_is_ajax_call() || bricks_is_rest_call() ) {
			\Bricks\Database::set_active_templates( $post_id );
		}

		$element_sets = [];
		$areas        = [ 'content', 'header', 'footer' ];

		foreach ( $areas as $area ) {
			$template_id = \Bricks\Database::$active_templates[ $area ] ?? 0;

			if ( ! $template_id ) {
				continue;
			}

			$elements = \Bricks\Database::get_data( $template_id, $area );

			if ( ! empty( $elements ) && is_array( $elements ) ) {
				$element_sets[] = $elements;
			}
		}

		$current_post_elements = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );

		if ( ! empty( $current_post_elements ) && is_array( $current_post_elements ) ) {
			$element_sets[] = $current_post_elements;
		}

		// Render IDs can combine the direct and owner instances for nested or slotted components.
		$submitted_context_ids = [ (string) $form_component_id ];

		if ( strpos( (string) $form_component_id, '-' ) !== false ) {
			$submitted_context_ids = array_merge( $submitted_context_ids, explode( '-', (string) $form_component_id ) );
		}

		$submitted_context_ids = array_values( array_unique( array_filter( $submitted_context_ids ) ) );

		foreach ( $element_sets as $elements ) {
			$expanded_elements = \Bricks\Database::get_component_data( $elements );

			if ( empty( $expanded_elements ) || ! is_array( $expanded_elements ) ) {
				continue;
			}

			foreach ( $expanded_elements as $element ) {
				if ( (string) ( $element['id'] ?? '' ) !== (string) $form_element_id || ( $element['name'] ?? '' ) !== 'form' ) {
					continue;
				}

				if ( $form_component_id ) {
					$element_context_ids = [
						(string) ( $element['id'] ?? '' ),
						(string) ( $element['cid'] ?? '' ),
						(string) ( $element['instanceId'] ?? '' ),
						(string) ( $element['renderInstanceId'] ?? '' ),
						(string) ( $element['rootInstanceId'] ?? '' ),
						(string) ( $element['slotInstanceId'] ?? '' ),
					];

					$element_context_ids = array_values( array_unique( array_filter( $element_context_ids ) ) );

					if ( empty( array_intersect( $submitted_context_ids, $element_context_ids ) ) ) {
						continue;
					}
				}

				$component_instance_settings = ! empty( $element['cid'] ) ? \Bricks\Helpers::get_component_instance( $element, 'settings' ) : false;

				if ( is_array( $component_instance_settings ) ) {
					return $component_instance_settings;
				}

				if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
					return $element['settings'];
				}
			}
		}

		return false;
	}

	/**
	 * Get Gutenberg block properties from post content
	 *
	 * @param int    $post_id           Post ID
	 * @param string $component_id      Component template ID
	 * @param string $form_element_id   Form element ID (may contain UUID)
	 *
	 * @return array Block properties
	 *
	 * @since 2.2
	 */
	private function get_gutenberg_block_properties( $post_id, $component_id, $form_element_id ) {
		$post = get_post( $post_id );

		if ( ! $post || empty( $post->post_content ) || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$blocks = parse_blocks( $post->post_content );

		return $this->find_block_properties_recursive( $blocks, $component_id, $form_element_id );
	}

	/**
	 * Recursively search for block properties in parsed blocks
	 *
	 * @param array  $blocks            Parsed blocks array
	 * @param string $component_id      Component template ID
	 * @param string $form_element_id   Form element ID (may contain UUID)
	 *
	 * @return array Block properties
	 *
	 * @since 2.2
	 */
	private function find_block_properties_recursive( $blocks, $component_id, $form_element_id ) {
		foreach ( $blocks as $block ) {
			// Check if this is a matching Bricks component block
			$block_component_id = Helpers::get_component_id_from_block( $block );

			if ( $block_component_id === $component_id ) {
				$attrs    = $block['attrs'] ?? [];
				$block_id = $attrs['blockId'] ?? '';

				// Match by blockId (check if form_element_id contains the blockId)
				if ( ! $block_id || strpos( $form_element_id, $block_id ) !== false ) {
					return $attrs['properties'] ?? [];
				}
			}

			// Recursively search inner blocks
			if ( ! empty( $block['innerBlocks'] ) ) {
				$properties = $this->find_block_properties_recursive( $block['innerBlocks'], $component_id, $form_element_id );

				if ( ! empty( $properties ) ) {
					return $properties;
				}
			}
		}

		return [];
	}
}
