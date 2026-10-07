<?php
/**
 * Global Class Creator
 *
 * Creates Bricks global classes from HTML class names and CSS rules.
 *
 * PHP port of src/vue/utils/htmlToBricks/classCreator.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global class creator for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Class_Creator {
	/**
	 * Normalize class settings for comparison.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Settings array.
	 * @return array Normalized settings.
	 */
	private static function normalize_class_settings( $settings = [] ) {
		$normalized = $settings;

		foreach ( $normalized as $key => $value ) {
			if ( ! preg_match( '/^_cssCustom(?::.+)?$/', (string) $key ) || ! is_string( $value ) ) {
				continue;
			}

			$css                = preg_replace( '/\s+/', ' ', $value );
			$css                = preg_replace( '/\s*([{}:;(),>+~])\s*/', '$1', $css );
			$normalized[ $key ] = trim( $css );
		}

		return $normalized;
	}

	/**
	 * Get the configured base breakpoint key from conversion options.
	 *
	 * @since 2.4
	 *
	 * @param array $options Conversion options.
	 * @return string Base breakpoint key.
	 */
	private static function get_base_breakpoint_key( $options = [] ) {
		if ( isset( $options['base_breakpoint_key'] ) && is_string( $options['base_breakpoint_key'] ) ) {
			return trim( $options['base_breakpoint_key'] );
		}

		if ( isset( $options['baseBreakpointKey'] ) && is_string( $options['baseBreakpointKey'] ) ) {
			return trim( $options['baseBreakpointKey'] );
		}

		return '';
	}

	/**
	 * Store imported base CSS on the configured base breakpoint.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings            Settings array.
	 * @param string $base_breakpoint_key Base breakpoint key.
	 * @return array Remapped settings.
	 */
	private static function map_base_settings_to_breakpoint( $settings = [], $base_breakpoint_key = '' ) {
		if ( empty( $settings ) || empty( $base_breakpoint_key ) || $base_breakpoint_key === 'desktop' ) {
			return $settings;
		}

		$mapped = [];

		foreach ( $settings as $setting_key => $setting_value ) {
			$pseudo             = '';
			$setting_key_string = (string) $setting_key;

			if ( preg_match( '/:(?:hover|focus|active)$/i', $setting_key_string, $match ) ) {
				$pseudo = $match[0];
			}

			$key_without_pseudo = $pseudo ? substr( $setting_key_string, 0, -strlen( $pseudo ) ) : $setting_key_string;

			if ( strpos( $key_without_pseudo, ':' ) !== false ) {
				$mapped[ $setting_key ] = $setting_value;
				continue;
			}

			$mapped[ "{$key_without_pseudo}:{$base_breakpoint_key}{$pseudo}" ] = $setting_value;
		}

		return $mapped;
	}

	/**
	 * Merge selector settings, preserving fallback CSS order.
	 *
	 * @since 2.4
	 *
	 * @param array $target Target settings.
	 * @param array $source Source settings.
	 * @return array Merged settings.
	 */
	private static function merge_selector_settings( $target, $source ) {
		foreach ( $source as $key => $value ) {
			if ( $value === null ) {
				continue;
			}

			if ( strpos( $key, '_cssCustom' ) === 0 && ! empty( $target[ $key ] ) ) {
				$target[ $key ] = trim( $target[ $key ] . "\n" . $value );
				continue;
			}

			if (
				is_array( $value ) &&
				isset( $target[ $key ] ) &&
				is_array( $target[ $key ] )
			) {
				$target[ $key ] = array_merge( $target[ $key ], $value );
				continue;
			}

			$target[ $key ] = $value;
		}

		return $target;
	}

	/**
	 * Check if a selector starts with a root selector boundary.
	 *
	 * @since 2.4
	 *
	 * @param string $selector      Full selector.
	 * @param string $root_selector Root selector.
	 * @return bool
	 */
	private static function selector_starts_with_root( $selector, $root_selector ) {
		if ( strpos( $selector, $root_selector ) !== 0 ) {
			return false;
		}

		$root_length = strlen( $root_selector );
		$next_char   = isset( $selector[ $root_length ] ) ? $selector[ $root_length ] : '';

		return ! $next_char || ! preg_match( '/[a-zA-Z0-9_-]/', $next_char );
	}

	/**
	 * Normalize selector whitespace without changing authored combinator spacing.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return string Display selector.
	 */
	private static function display_selector( $selector = '' ) {
		return preg_replace( '/\s+/', ' ', trim( (string) $selector ) );
	}

	/**
	 * Format leading combinators for Bricks selector UI readability.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Relative selector.
	 * @return string Formatted selector.
	 */
	private static function format_leading_combinator( $selector ) {
		$trimmed = trim( (string) $selector );

		if ( ! preg_match( '/^[>+~]/', $trimmed ) ) {
			return $trimmed;
		}

		return $trimmed[0] . ' ' . ltrim( substr( $trimmed, 1 ) );
	}

	/**
	 * Convert a full selector into a Bricks selector relative to its root.
	 *
	 * @since 2.4
	 *
	 * @param string $selector      Full selector.
	 * @param string $root_selector Root selector.
	 * @return string Relative selector.
	 */
	private static function to_relative_selector( $selector, $root_selector ) {
		$normalized_selector = Html_To_Bricks_Css_Control_Mapper::normalize_selector( $selector );
		$normalized_root     = Html_To_Bricks_Css_Control_Mapper::normalize_selector( $root_selector );
		$display_selector    = self::display_selector( $selector );
		$display_root        = self::display_selector( $root_selector );

		if (
			! $normalized_selector ||
			! $normalized_root ||
			! self::selector_starts_with_root( $normalized_selector, $normalized_root )
		) {
			return '';
		}

		$suffix = self::selector_starts_with_root( $display_selector, $display_root )
			? substr( $display_selector, strlen( $display_root ) )
			: substr( $normalized_selector, strlen( $normalized_root ) );

		if ( ! $suffix ) {
			return '';
		}

		if ( preg_match( '/^\s+/', $suffix ) ) {
			return self::format_leading_combinator( $suffix );
		}

		$trimmed_suffix = trim( $suffix );

		if ( ! $trimmed_suffix ) {
			return '';
		}

		if ( preg_match( '/^[>+~]/', $trimmed_suffix ) ) {
			return self::format_leading_combinator( $trimmed_suffix );
		}

		if ( preg_match( '/^[.#\[\]:]/', $trimmed_suffix ) ) {
			return '&' . $trimmed_suffix;
		}

		return $trimmed_suffix;
	}

	/**
	 * Check if a CSS target belongs to the root itself, including root pseudos.
	 *
	 * @since 2.4
	 *
	 * @param array  $target        CSS target.
	 * @param string $root_selector Root selector.
	 * @return bool
	 */
	public static function is_root_selector_target( $target, $root_selector ) {
		$parts = Html_To_Bricks_Css_Control_Mapper::strip_trailing_pseudo(
			isset( $target['selector'] ) ? $target['selector'] : ''
		);

		return Html_To_Bricks_Css_Control_Mapper::normalize_selector( $parts['base'] ) ===
			Html_To_Bricks_Css_Control_Mapper::normalize_selector( $root_selector );
	}

	/**
	 * Convert non-root CSS targets into Bricks custom selector objects.
	 *
	 * @since 2.4
	 *
	 * @param string $root_selector Root selector.
	 * @param array  $targets       CSS targets.
	 * @param string $element_name  Element schema name.
	 * @param array  $options       Conversion options.
	 * @return array Bricks selector objects.
	 */
	public static function create_selector_objects_from_targets(
		$root_selector,
		$targets = [],
		$element_name = 'div',
		$options = []
	) {
		$selectors_by_key    = [];
		$base_breakpoint_key = self::get_base_breakpoint_key( $options );

		foreach ( $targets as $target ) {
			$relative_selector = self::to_relative_selector(
				isset( $target['selector'] ) ? $target['selector'] : '',
				$root_selector
			);

			if ( ! $relative_selector ) {
				continue;
			}

			$settings = Html_To_Bricks_Css_Control_Mapper::map_target_to_selector_settings(
				$element_name,
				$target
			);

			if ( empty( $settings ) ) {
				continue;
			}

			if ( ! isset( $selectors_by_key[ $relative_selector ] ) ) {
				$selectors_by_key[ $relative_selector ] = [
					'id'       => Html_To_Bricks_Element_Mapper::generate_id(),
					'selector' => $relative_selector,
					'settings' => [],
				];
			}

			$selectors_by_key[ $relative_selector ]['settings'] = self::merge_selector_settings(
				$selectors_by_key[ $relative_selector ]['settings'],
				$settings
			);
		}

		if ( ! empty( $base_breakpoint_key ) && $base_breakpoint_key !== 'desktop' ) {
			foreach ( $selectors_by_key as $key => $selector_object ) {
				$selectors_by_key[ $key ]['settings'] = self::map_base_settings_to_breakpoint(
					isset( $selector_object['settings'] ) ? $selector_object['settings'] : [],
					$base_breakpoint_key
				);
			}
		}

		return array_values( $selectors_by_key );
	}

	/**
	 * Whether an at-rule has an exact native breakpoint representation.
	 *
	 * @since 2.4.2
	 *
	 * @param array $target Parsed CSS target.
	 * @return bool
	 */
	private static function can_map_at_rule_target( $target ) {
		$at_rules = ! empty( $target['atRules'] ) && is_array( $target['atRules'] ) ? $target['atRules'] : [];

		if ( empty( $at_rules ) ) {
			return true;
		}

		if ( empty( $target['breakpoint'] ) || count( $at_rules ) !== 1 ) {
			return false;
		}

		$at_rule = $at_rules[0];
		return ( $at_rule['name'] ?? '' ) === 'media' &&
			preg_match( '/^\(?\s*max-width\s*:\s*\d+(?:\.\d+)?px\s*\)?$/i', trim( (string) ( $at_rule['params'] ?? '' ) ) );
	}

	/**
	 * Create a global class object.
	 *
	 * @since 2.4
	 *
	 * @param string $name      Class name.
	 * @param array  $css_rules CSS rules for the class.
	 * @param array  $options   Conversion options.
	 * @return array Global class object.
	 */
	public static function create_global_class( $name, $css_rules = [], $options = [] ) {
		$id                  = Html_To_Bricks_Element_Mapper::generate_id();
		$base_breakpoint_key = self::get_base_breakpoint_key( $options );

		$global_class = [
			'id'   => $id,
			'name' => $name,
		];

		$selector = '.' . $name;

		$selector_targets = isset( $css_rules['targets'] ) && is_array( $css_rules['targets'] ) ? $css_rules['targets'] : [];

		// Native rules have a fixed breakpoint order. Preserve the custom-CSS path
		// when the authored root rules cannot be emitted in that same order.
		$can_map_root_media = ! $base_breakpoint_key || $base_breakpoint_key === 'desktop';
		$base_width         = class_exists( '\\Bricks\\Breakpoints' ) && ! empty( Breakpoints::$breakpoints )
			? (float) Breakpoints::$base_width
			: 1279;
		$last_root_width    = INF;
		foreach ( $selector_targets as $target ) {
			$parts = Html_To_Bricks_Css_Control_Mapper::strip_trailing_pseudo( $target['selector'] ?? '' );
			if ( $parts['base'] !== $selector ) {
				continue;
			}
			// Logical spacing depends on writing mode and can overlap physical controls.
			foreach ( array_keys( $target['declarations'] ?? [] ) as $property ) {
				if ( preg_match( '/^(padding|margin)-(inline|block)/i', $property ) ) {
					$can_map_root_media = false;
				}
			}
			if ( empty( $target['atRules'] ) ) {
				$can_map_root_media = $can_map_root_media && $last_root_width === INF;
				continue;
			}
			if ( ! self::can_map_at_rule_target( $target ) ) {
				$can_map_root_media = false;
				continue;
			}
			preg_match( '/max-width\s*:\s*(\d+(?:\.\d+)?)px/i', (string) $target['atRules'][0]['params'], $width_match );
			$width              = (float) $width_match[1];
			$can_map_root_media = $can_map_root_media && $width < $base_width && $width <= $last_root_width;
			$last_root_width    = $width;
		}

		if ( ! empty( $selector_targets ) ) {
			$root_targets = [];

			foreach ( $selector_targets as $target ) {
				$parts = Html_To_Bricks_Css_Control_Mapper::strip_trailing_pseudo( isset( $target['selector'] ) ? $target['selector'] : '' );

				if ( $parts['base'] === $selector && ( empty( $target['atRules'] ) || $can_map_root_media ) ) {
					$root_targets[] = $target;
				}
			}
		} else {
			$root_targets = [];

			$base = isset( $css_rules['base'] ) ? $css_rules['base'] : [];

			if ( ! empty( $base ) ) {
				$root_targets[] = Html_To_Bricks_Css_Control_Mapper::build_root_target( $selector, $base );
			}

			$pseudo = isset( $css_rules['pseudo'] ) ? $css_rules['pseudo'] : [];

			foreach ( $pseudo as $pseudo_str => $declarations ) {
				$root_targets[] = Html_To_Bricks_Css_Control_Mapper::build_root_target( $selector . $pseudo_str, ! empty( $declarations ) ? $declarations : [] );
			}
		}

		$root_mapped = Html_To_Bricks_Css_Control_Mapper::map_targets_with_schema(
			// Global classes can be applied to any element, so map against inherited controls.
			'div',
			$selector,
			$root_targets,
			[]
		);

		// Splitting overlapping shorthands and unsupported declarations between
		// controls and custom CSS can reverse their authored cascade.
		if ( $can_map_root_media && ! empty( $root_mapped['unmappedTargets'] ) ) {
			$can_map_root_media = false;
			$root_targets       = array_values(
				array_filter(
					$root_targets,
					static function ( $target ) {
						return empty( $target['atRules'] );
					}
				)
			);
			$root_mapped        = Html_To_Bricks_Css_Control_Mapper::map_targets_with_schema( 'div', $selector, $root_targets, [] );
		}

		$non_root_targets        = [];
		$root_css_custom_targets = [];

		if ( ! empty( $selector_targets ) ) {
			foreach ( $selector_targets as $target ) {
				$parts = Html_To_Bricks_Css_Control_Mapper::strip_trailing_pseudo( isset( $target['selector'] ) ? $target['selector'] : '' );

				// Unresolved or compound at-rules must stay in custom CSS. Mapping them
				// to native selector controls would either promote them to the base
				// breakpoint or discard part of their condition.
				if ( ! self::can_map_at_rule_target( $target ) || ( $parts['base'] === $selector && ! empty( $target['atRules'] ) && ! $can_map_root_media ) ) {
					$root_css_custom_targets[] = $target;
					continue;
				}

				if ( $parts['base'] !== $selector ) {
					$non_root_targets[] = $target;
					continue;
				}
			}
		}

		$css_custom_settings = Html_To_Bricks_Css_Control_Mapper::build_css_custom_settings_from_targets(
			array_merge(
				isset( $root_mapped['unmappedTargets'] ) ? $root_mapped['unmappedTargets'] : [],
				$root_css_custom_targets
			),
			$selector
		);
		$selectors           = self::create_selector_objects_from_targets( $selector, $non_root_targets, 'div', $options );

		$has_mapped_settings = ! empty( $root_mapped['settings'] );
		$has_css_custom      = ! empty( $css_custom_settings );
		$has_selectors       = ! empty( $selectors );

		if ( $has_mapped_settings || $has_css_custom ) {
			$global_class['settings'] = $root_mapped['settings'];

			if ( $has_css_custom ) {
				$global_class['settings'] = array_merge( $global_class['settings'], $css_custom_settings );
			}

			$global_class['settings'] = self::map_base_settings_to_breakpoint(
				$global_class['settings'],
				$base_breakpoint_key
			);
		}

		if ( $has_selectors ) {
			$global_class['selectors'] = $selectors;
		}

		return $global_class;
	}

	/**
	 * Process class names and CSS rules to create global classes.
	 *
	 * @since 2.4
	 *
	 * @param array $class_names      Array of class names found in HTML.
	 * @param array $css_rules        Parsed CSS rules.
	 * @param array $existing_classes Existing global classes (for ID mapping).
	 * @param array $options          Conversion options.
	 * @return array Result with newClasses, conflictingClasses, classMap.
	 */
	public static function process_classes( $class_names, $css_rules, $existing_classes = [], $options = [] ) {
		$existing    = ! empty( $existing_classes ) ? $existing_classes : [];
		$class_names = array_values(
			array_filter(
				$class_names,
				function ( $class_name ) {
					return ! Html_To_Bricks_Element_Mapper::is_reserved_bricks_class( $class_name );
				}
			)
		);

		// Extract CSS rules for each class
		$class_rule_map = Html_To_Bricks_Css_Parser::extract_class_rules( $css_rules, $class_names );

		$new_classes         = [];
		$conflicting_classes = [];
		$class_map           = [];

		foreach ( $class_names as $class_name ) {
			// Check if class already exists in Bricks by name
			$existing_class = null;

			foreach ( $existing as $gc ) {
				if ( isset( $gc['name'] ) && $gc['name'] === $class_name ) {
					$existing_class = $gc;
					break;
				}
			}

			$css_for_class = isset( $class_rule_map[ $class_name ] ) ? $class_rule_map[ $class_name ] : [
				'base'   => [],
				'pseudo' => []
			];
			$has_css_rules = ! empty( $css_for_class['base'] ) ||
				! empty( $css_for_class['pseudo'] ) ||
				( isset( $css_for_class['targets'] ) && is_array( $css_for_class['targets'] ) && ! empty( $css_for_class['targets'] ) );

			// Keep local class reference by name if no CSS was provided for this class
			if ( $existing_class && ! $has_css_rules ) {
				$class_map[ $class_name ] = $existing_class['id'];
				continue;
			}

			// A semantic/local class without authored styles is not a global
			// design resource. Keep its literal class name so a later document
			// can define the shared global class without colliding with an empty
			// placeholder created by this conversion.
			if ( ! $existing_class && ! $has_css_rules ) {
				continue;
			}

			$global_class = self::create_global_class( $class_name, $css_for_class, $options );

			if ( $existing_class ) {
				$existing_is_empty     = empty( $existing_class['settings'] );
				$incoming_has_settings = ! empty( $global_class['settings'] );

				// Existing class is empty but incoming has styles: treat as conflict
				if ( $existing_is_empty && $incoming_has_settings ) {
					$conflicting_classes[]    = $global_class;
					$class_map[ $class_name ] = $global_class['id'];
					continue;
				}

				// Both empty: keep local mapping (no conflict)
				if ( $existing_is_empty ) {
					$class_map[ $class_name ] = $existing_class['id'];
					continue;
				}

				$existing_settings = wp_json_encode( self::normalize_class_settings( isset( $existing_class['settings'] ) ? $existing_class['settings'] : [] ) );
				$incoming_settings = wp_json_encode( self::normalize_class_settings( isset( $global_class['settings'] ) ? $global_class['settings'] : [] ) );

				// Keep local class ID when settings are identical
				if ( $existing_settings === $incoming_settings ) {
					$class_map[ $class_name ] = $existing_class['id'];
				} else {
					// Keep incoming class as conflict candidate
					$conflicting_classes[]    = $global_class;
					$class_map[ $class_name ] = $global_class['id'];
				}
			} else {
				// Create new class
				$new_classes[]            = $global_class;
				$class_map[ $class_name ] = $global_class['id'];
			}
		}

		return [
			'newClasses'         => $new_classes,
			'conflictingClasses' => $conflicting_classes,
			'classMap'           => $class_map,
		];
	}

	/**
	 * Apply class mapping to elements.
	 *
	 * Updates _cssClasses to _cssGlobalClasses with IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $elements  Array of Bricks elements.
	 * @param array $class_map Map of className -> globalClassId.
	 * @return array Updated elements.
	 */
	public static function apply_class_mapping( $elements, $class_map ) {
		$updated = [];

		foreach ( $elements as $element ) {
			$el = $element;

			if ( ! isset( $el['settings'] ) ) {
				$el['settings'] = [];
			}

			if ( ! empty( $el['settings']['_cssClasses'] ) ) {
				$class_names = array_filter( preg_split( '/\s+/', $el['settings']['_cssClasses'] ) );

				$global_class_ids = [];
				$unmapped_classes = [];

				foreach ( $class_names as $class_name ) {
					if ( Html_To_Bricks_Element_Mapper::is_reserved_bricks_class( $class_name ) ) {
						continue;
					}

					if ( isset( $class_map[ $class_name ] ) ) {
						$global_class_ids[] = $class_map[ $class_name ];
					} else {
						$unmapped_classes[] = $class_name;
					}
				}

				if ( ! empty( $global_class_ids ) ) {
					$el['settings']['_cssGlobalClasses'] = $global_class_ids;
				}

				if ( ! empty( $unmapped_classes ) ) {
					$el['settings']['_cssClasses'] = implode( ' ', $unmapped_classes );
				} else {
					unset( $el['settings']['_cssClasses'] );
				}
			}

			$updated[] = $el;
		}

		return $updated;
	}

	/**
	 * Collect all unique class names from elements.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Array of Bricks elements.
	 * @return array Array of unique class names.
	 */
	public static function collect_class_names( $elements ) {
		$class_set = [];

		foreach ( $elements as $element ) {
			if ( ! empty( $element['settings']['_cssClasses'] ) ) {
				$classes = array_filter( preg_split( '/\s+/', $element['settings']['_cssClasses'] ) );

				foreach ( $classes as $class_name ) {
					if ( Html_To_Bricks_Element_Mapper::is_reserved_bricks_class( $class_name ) ) {
						continue;
					}

					$class_set[ $class_name ] = true;
				}
			}
		}

		return array_keys( $class_set );
	}

	/**
	 * Find elements that have class names matching the given list.
	 *
	 * @since 2.4
	 *
	 * @param array $elements    Array of Bricks elements.
	 * @param array $class_names Array of class names to match.
	 * @return array Array of { elementId, matchingClasses }.
	 */
	public static function find_elements_with_classes( $elements, $class_names ) {
		$matches   = [];
		$class_set = array_flip( $class_names );

		foreach ( $elements as $element ) {
			if ( empty( $element['settings']['_cssClasses'] ) ) {
				continue;
			}

			$element_classes  = array_filter( preg_split( '/\s+/', $element['settings']['_cssClasses'] ) );
			$matching_classes = array_values(
				array_filter(
					$element_classes,
					function ( $c ) use ( $class_set ) {
						return isset( $class_set[ $c ] );
					}
				)
			);

			if ( ! empty( $matching_classes ) ) {
				$matches[] = [
					'elementId'       => $element['id'],
					'matchingClasses' => $matching_classes,
				];
			}
		}

		return $matches;
	}

	/**
	 * Collect class names from CSS rules.
	 *
	 * Extracts class names from selectors like ".button", ".btn:hover".
	 *
	 * @since 2.4
	 *
	 * @param array $rules Parsed CSS rules.
	 * @return array Array of unique class names.
	 */
	public static function collect_class_names_from_rules( $rules ) {
		$class_names = [];

		foreach ( $rules as $rule ) {
			if ( ! isset( $rule['selectors'] ) ) {
				continue;
			}

			foreach ( $rule['selectors'] as $selector ) {
				if ( preg_match_all( '/\.([a-zA-Z_-][\w-]*)/', $selector, $matches ) ) {
					foreach ( $matches[1] as $match ) {
						if ( Html_To_Bricks_Element_Mapper::is_reserved_bricks_class( $match ) ) {
							continue;
						}

						$class_names[ $match ] = true;
					}
				}
			}
		}

		return array_keys( $class_names );
	}
}
