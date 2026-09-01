<?php
/**
 * Rendering helpers for the shared admin layer.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the pieces the settings screen and the order panels are made of.
 */
class PGLP_UI {

	/**
	 * The plugin mark.
	 *
	 * @param int $size Pixel size.
	 */
	public static function glyph( $size = 18 ) {
		printf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">'
			. '<rect x="1.5" y="4" width="17" height="12" rx="2.5" stroke="currentColor" stroke-width="1.6"/>'
			. '<path d="M1.5 8h17" stroke="currentColor" stroke-width="1.6"/>'
			. '<path d="M5 12.5h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
			absint( $size )
		);
	}

	/**
	 * A rough "how long ago" phrase built from this plugin's own strings.
	 *
	 * The core helper human_time_diff() is translated with the site locale, so on a shop whose
	 * language the plugin has no catalogue for, the number lands inside an English sentence in
	 * another language. Keeping the whole phrase in one text domain avoids that mix.
	 *
	 * @param int $timestamp Unix timestamp in the past.
	 * @return string
	 */
	public static function time_ago( $timestamp ) {
		$seconds = max( 0, time() - (int) $timestamp );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'less than a minute', 'vitaliihura-checkout-for-liqpay' );
		}

		if ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );

			/* translators: %s: number of minutes. */
			return sprintf( _n( '%s minute', '%s minutes', $minutes, 'vitaliihura-checkout-for-liqpay' ), number_format_i18n( $minutes ) );
		}

		if ( $seconds < DAY_IN_SECONDS ) {
			$hours = (int) floor( $seconds / HOUR_IN_SECONDS );

			/* translators: %s: number of hours. */
			return sprintf( _n( '%s hour', '%s hours', $hours, 'vitaliihura-checkout-for-liqpay' ), number_format_i18n( $hours ) );
		}

		$days = (int) floor( $seconds / DAY_IN_SECONDS );

		/* translators: %s: number of days. */
		return sprintf( _n( '%s day', '%s days', $days, 'vitaliihura-checkout-for-liqpay' ), number_format_i18n( $days ) );
	}

	/**
	 * A section icon.
	 *
	 * @param string $name Icon name.
	 */
	public static function icon( $name ) {
		$paths = array(
			'availability' => '<circle cx="8" cy="8" r="6.2" stroke="currentColor" stroke-width="1.4"/><path d="M5.2 8.2l1.9 1.9 3.7-3.9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'keys'         => '<rect x="2" y="6.5" width="12" height="7.5" rx="1.6" stroke="currentColor" stroke-width="1.4"/><path d="M5 6.5V4.6a3 3 0 016 0v1.9" stroke="currentColor" stroke-width="1.4"/>',
			'payment'      => '<rect x="1.8" y="3.5" width="12.4" height="9" rx="1.6" stroke="currentColor" stroke-width="1.4"/><path d="M1.8 6.5h12.4" stroke="currentColor" stroke-width="1.4"/>',
			'statuses'     => '<path d="M3 8.5l3 3 7-7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			'cards'        => '<rect x="1.8" y="4" width="12.4" height="8" rx="1.6" stroke="currentColor" stroke-width="1.4"/><path d="M4.5 9.5h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
			'receipt'      => '<path d="M4 2.5h8v11l-2-1.2-2 1.2-2-1.2-2 1.2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>',
			'appearance'   => '<circle cx="8" cy="8" r="6.2" stroke="currentColor" stroke-width="1.4"/><path d="M8 1.8v12.4" stroke="currentColor" stroke-width="1.4"/>',
			'advanced'     => '<path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
		);

		$path = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['advanced'];

		echo '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">'
			. wp_kses( $path, self::svg_tags() )
			. '</svg>';
	}

	/**
	 * The SVG elements the icons are allowed to use.
	 *
	 * @return array
	 */
	private static function svg_tags() {
		$attributes = array(
			'd'                => true,
			'x'                => true,
			'y'                => true,
			'cx'               => true,
			'cy'               => true,
			'r'                => true,
			'rx'               => true,
			'width'            => true,
			'height'           => true,
			'fill'             => true,
			'stroke'           => true,
			'stroke-width'     => true,
			'stroke-linecap'   => true,
			'stroke-linejoin'  => true,
		);

		return array(
			'path'   => $attributes,
			'rect'   => $attributes,
			'circle' => $attributes,
		);
	}

	/**
	 * A status pill.
	 *
	 * @param string $label Text.
	 * @param string $type  ok, warn, err, idle or accent.
	 */
	public static function pill( $label, $type = 'idle' ) {
		$allowed = array( 'ok', 'warn', 'err', 'idle', 'accent' );
		$type    = in_array( $type, $allowed, true ) ? $type : 'idle';

		printf(
			'<span class="pc-pill pc-pill--%1$s">%2$s</span>',
			esc_attr( $type ),
			esc_html( $label )
		);
	}

	/**
	 * The compact page header used inside WooCommerce settings.
	 *
	 * @param string $title    Plugin name.
	 * @param string $subtitle One line of context.
	 * @param array  $pills    List of arrays with label and type.
	 */
	public static function hero( $title, $subtitle, $pills = array() ) {
		?>
		<div class="pc-hero pc-hero--compact">
			<span class="pc-hero__glyph"><?php self::glyph( 18 ); ?></span>
			<div class="pc-hero__id">
				<h1><?php echo esc_html( $title ); ?></h1>
				<p class="pc-hero__sub"><?php echo esc_html( $subtitle ); ?></p>
			</div>
			<?php foreach ( $pills as $pill ) : ?>
				<?php self::pill( $pill['label'], isset( $pill['type'] ) ? $pill['type'] : 'idle' ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The section index. The same markup becomes a card on wide screens and a chip row below
	 * 1200px, which is why there is only one of it.
	 *
	 * @param array $sections Section definitions.
	 */
	public static function index( $sections ) {
		?>
		<nav class="pc-index" aria-label="<?php esc_attr_e( 'Settings sections', 'vitaliihura-checkout-for-liqpay' ); ?>">
			<?php foreach ( $sections as $section ) : ?>
				<a href="#pglp-<?php echo esc_attr( $section['id'] ); ?>">
					<span class="pc-index__ico"><?php self::icon( $section['icon'] ); ?></span>
					<span>
						<b><?php echo esc_html( $section['title'] ); ?></b>
						<span><?php echo esc_html( $section['subtitle'] ); ?></span>
					</span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders one settings field.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway the field belongs to.
	 * @param string             $key     Field key.
	 * @param array              $field   Field definition.
	 * @param array              $all     Every field, so a dependency can be looked up.
	 */
	public static function field( $gateway, $key, $field, $all = array() ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		if ( in_array( $type, array( 'title', 'pc_hidden_map' ), true ) ) {
			return;
		}

		$id      = $gateway->get_field_key( $key );
		$value   = $gateway->get_option( $key );
		$depends = isset( $field['pc_depends'] ) ? $field['pc_depends'] : '';

		$classes = array( 'pc-field' );

		if ( '' !== $depends ) {
			$classes[] = 'pc-dependent';
		}

		printf( '<div class="%s"', esc_attr( implode( ' ', $classes ) ) );

		if ( '' !== $depends ) {
			$wanted = isset( $field['pc_depends_value'] ) ? $field['pc_depends_value'] : '';

			printf(
				' data-pc-depends="%s" data-pc-depends-value="%s"',
				esc_attr( $gateway->get_field_key( $depends ) ),
				esc_attr( $wanted )
			);

			// Decided here as well as in the script so the field does not flash into view on
			// every page load before the script runs.
			if ( ! self::dependency_met( $gateway, $depends, $wanted, $all ) ) {
				echo ' hidden="hidden"';
			}
		}

		echo '>';

		if ( 'checkbox' === $type ) {
			self::toggle( $id, $key, $field, $value );
		} else {
			// A group of checkboxes and a read-only value have nothing for a label to point at,
			// so they are labelled by reference instead of by "for".
			$labelled_by = in_array( $type, array( 'multiselect', 'pc_copy' ), true );

			if ( ! empty( $field['title'] ) ) {
				if ( $labelled_by ) {
					printf( '<span class="pc-label" id="%s-label">%s</span>', esc_attr( $id ), esc_html( $field['title'] ) );
				} else {
					printf( '<label class="pc-label" for="%s">%s</label>', esc_attr( $id ), esc_html( $field['title'] ) );
				}
			}

			self::control( $gateway, $id, $key, $field, $value, $type );
		}

		if ( ! empty( $field['description'] ) ) {
			printf( '<span class="pc-hint">%s</span>', wp_kses_post( $field['description'] ) );
		}

		echo '</div>';
	}

	/**
	 * Whether the field a dependent field waits for currently has the wanted value.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @param string             $depends Key of the field depended on.
	 * @param string             $wanted  Value that reveals the dependent field.
	 * @param array              $all     Every field definition.
	 * @return bool
	 */
	private static function dependency_met( $gateway, $depends, $wanted, $all ) {
		$value = $gateway->get_option( $depends );
		$type  = isset( $all[ $depends ]['type'] ) ? $all[ $depends ]['type'] : '';

		if ( 'checkbox' === $type ) {
			$value = 'yes' === $value ? '1' : '';
		}

		if ( '' === $wanted ) {
			return '' !== (string) $value && 'no' !== (string) $value;
		}

		return (string) $wanted === (string) $value;
	}

	/**
	 * Renders a checkbox as a switch.
	 *
	 * @param string $id    Field id.
	 * @param string $key   Field key.
	 * @param array  $field Field definition.
	 * @param string $value Stored value.
	 */
	private static function toggle( $id, $key, $field, $value ) {
		// The switch text says what it does, so an uppercase label above it would only repeat
		// the section heading.
		$label = ! empty( $field['label'] ) ? $field['label'] : $field['title'];

		printf(
			'<label class="pc-toggle" for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /><span class="pc-toggle__track"></span><span>%3$s</span></label>',
			esc_attr( $id ),
			checked( 'yes', $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the input itself.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @param string             $id      Field id.
	 * @param string             $key     Field key.
	 * @param array              $field   Field definition.
	 * @param mixed              $value   Stored value.
	 * @param string             $type    Field type.
	 */
	private static function control( $gateway, $id, $key, $field, $value, $type ) {
		$attributes = self::attributes( $field );
		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%1$s" rows="3" placeholder="%2$s"%3$s>%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $placeholder ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in attributes().
					$attributes,
					esc_textarea( $value )
				);
				break;

			case 'select':
				printf(
					'<select id="%1$s" name="%1$s"%2$s>',
					esc_attr( $id ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in attributes().
					$attributes
				);

				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $option_value ),
						(string) $option_value === (string) $value ? ' selected="selected"' : '',
						esc_html( $option_label )
					);
				}

				echo '</select>';
				break;

			case 'multiselect':
				// A native multi-select is hard to use with a mouse and worse on a phone, so the
				// options are checkboxes that read as one row of choices.
				$selected = array_map( 'strval', (array) $value );
				$locked   = isset( $field['pc_locked'] ) ? array_map( 'strval', (array) $field['pc_locked'] ) : array();
				$note     = isset( $field['pc_locked_note'] ) ? $field['pc_locked_note'] : '';

				printf( '<span class="pc-choices" role="group" aria-labelledby="%s-label">', esc_attr( $id ) );

				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					$is_locked = in_array( (string) $option_value, $locked, true );

					printf(
						'<label class="pc-choice%5$s"%6$s><input type="checkbox" name="%1$s[]" value="%2$s"%3$s%7$s /><span>%4$s</span></label>',
						esc_attr( $id ),
						esc_attr( $option_value ),
						! $is_locked && in_array( (string) $option_value, $selected, true ) ? ' checked="checked"' : '',
						esc_html( $option_label ),
						$is_locked ? ' pc-choice--locked' : '',
						$is_locked && '' !== $note ? ' title="' . esc_attr( $note ) . '"' : '',
						$is_locked ? ' disabled="disabled"' : ''
					);
				}

				echo '</span>';
				break;

			case 'pc_secret':
				printf(
					'<span class="pc-secret"><input type="password" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" autocomplete="off" spellcheck="false"%4$s />',
					esc_attr( $id ),
					esc_attr( $value ),
					esc_attr( $placeholder ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in attributes().
					$attributes
				);
				printf(
					'<button type="button" class="pc-btn pc-btn--small pc-secret__reveal" aria-pressed="false" aria-controls="%s">%s</button></span>',
					esc_attr( $id ),
					esc_html__( 'Show', 'vitaliihura-checkout-for-liqpay' )
				);
				break;

			case 'pc_copy':
				printf(
					'<span class="pc-copy" role="group" aria-labelledby="%s-label"><code>%s</code><button type="button" class="pc-btn pc-btn--small pc-copy__button">%s</button></span>',
					esc_attr( $id ),
					esc_html( $field['pc_value'] ),
					esc_html__( 'Copy', 'vitaliihura-checkout-for-liqpay' )
				);
				break;

			case 'pc_i18n_text':
			case 'pc_i18n_textarea':
				self::i18n_control( $gateway, $id, $key, $field, $value, 'pc_i18n_textarea' === $type );
				break;

			case 'password':
				printf(
					'<input type="password" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" autocomplete="off"%4$s />',
					esc_attr( $id ),
					esc_attr( $value ),
					esc_attr( $placeholder ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in attributes().
					$attributes
				);
				break;

			default:
				printf(
					'<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" placeholder="%4$s"%5$s />',
					esc_attr( 'number' === $type ? 'number' : 'text' ),
					esc_attr( $id ),
					esc_attr( $value ),
					esc_attr( $placeholder ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in attributes().
					$attributes
				);
				break;
		}
	}

	/**
	 * Renders a value that can differ per site language.
	 *
	 * The language switch only appears when the site actually has more than one language, so a
	 * single language shop sees an ordinary field.
	 *
	 * @param WC_Payment_Gateway $gateway  Gateway.
	 * @param string             $id       Field id of the default value.
	 * @param string             $key      Field key.
	 * @param array              $field    Field definition.
	 * @param string             $value    Default language value.
	 * @param bool               $textarea Whether to render a textarea.
	 */
	private static function i18n_control( $gateway, $id, $key, $field, $value, $textarea ) {
		$languages = PGLP_I18n::languages();
		$multi     = count( $languages ) > 1;
		$map       = $gateway->get_option( $key . '_i18n' );
		$map       = is_array( $map ) ? $map : array();
		$group     = $id;
		$default   = PGLP_I18n::default_locale();

		if ( $multi ) {
			printf( '<span class="pc-seg" role="group" data-pc-langs="%s" aria-label="%s">', esc_attr( $group ), esc_attr__( 'Value language', 'vitaliihura-checkout-for-liqpay' ) );

			foreach ( $languages as $index => $language ) {
				printf(
					'<button type="button" data-locale="%s" aria-pressed="%s">%s</button>',
					esc_attr( $language['locale'] ),
					0 === $index ? 'true' : 'false',
					esc_html( $language['name'] )
				);
			}

			echo '</span>';
		}

		foreach ( $languages as $index => $language ) {
			$is_default = $language['locale'] === $default;
			$name       = $is_default ? $id : $id . '_i18n[' . $language['locale'] . ']';
			$field_id   = $is_default ? $id : $id . '_' . sanitize_key( $language['locale'] );
			$current    = $is_default ? $value : ( isset( $map[ $language['locale'] ] ) ? $map[ $language['locale'] ] : '' );
			$hidden     = $multi && 0 !== $index;

			// An empty translation falls back to the default language, so that value
			// stands in as the placeholder and shows what the customer would get.
			$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

			if ( ! $is_default && '' !== $value ) {
				$placeholder = $value;
			}

			printf(
				'<span data-pc-lang-group="%s" data-locale="%s"%s>',
				esc_attr( $group ),
				esc_attr( $language['locale'] ),
				$hidden ? ' hidden="hidden"' : ''
			);

			if ( $textarea ) {
				printf(
					'<textarea id="%s" name="%s" rows="3" placeholder="%s">%s</textarea>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( $placeholder ),
					esc_textarea( $current )
				);
			} else {
				printf(
					'<input type="text" id="%s" name="%s" value="%s" placeholder="%s" />',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( $current ),
					esc_attr( $placeholder )
				);
			}

			echo '</span>';
		}
	}

	/**
	 * Builds the extra attributes a field asked for.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	private static function attributes( $field ) {
		if ( empty( $field['custom_attributes'] ) || ! is_array( $field['custom_attributes'] ) ) {
			return '';
		}

		$out = '';

		foreach ( $field['custom_attributes'] as $name => $value ) {
			$out .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return $out;
	}
}
