<?php
/**
 * Per-language values for the settings a shop owner types in.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the site's language list from whichever multilingual plugin is active and resolves a
 * stored value for a given locale.
 *
 * The plugin keeps the translations in its own settings rather than registering them with the
 * multilingual plugin's string translation screen. One field, one place to edit it: registering
 * the same string in two interfaces leaves nobody sure which one wins.
 */
class PGLP_I18n {

	/**
	 * Order meta holding the locale the customer was browsing in.
	 */
	const ORDER_LOCALE = '_pglp_locale';

	/**
	 * Cached language list.
	 *
	 * @var array|null
	 */
	private static $languages = null;

	/**
	 * The languages the site publishes in.
	 *
	 * @return array List of arrays with locale, code and name.
	 */
	public static function languages() {
		if ( null !== self::$languages ) {
			return self::$languages;
		}

		$languages = self::from_polylang();

		if ( empty( $languages ) ) {
			$languages = self::from_wpml();
		}

		if ( empty( $languages ) ) {
			$languages = self::from_translatepress();
		}

		if ( empty( $languages ) ) {
			$locale    = get_locale();
			$languages = array(
				array(
					'locale' => $locale,
					'code'   => substr( $locale, 0, 2 ),
					'name'   => self::locale_name( $locale ),
				),
			);
		}

		/**
		 * Filters the language list offered for translatable settings.
		 *
		 * @param array $languages List of arrays with locale, code and name.
		 */
		$languages = apply_filters( 'pglp_languages', $languages );

		if ( ! is_array( $languages ) || empty( $languages ) ) {
			$locale    = get_locale();
			$languages = array(
				array(
					'locale' => $locale,
					'code'   => substr( $locale, 0, 2 ),
					'name'   => self::locale_name( $locale ),
				),
			);
		}

		self::$languages = self::default_first( array_values( $languages ) );

		return self::$languages;
	}

	/**
	 * Moves the site's default language to the front of the list.
	 *
	 * Which language is the default has to come from the multilingual plugin, never from the
	 * order of its list: reordering languages in Polylang would otherwise silently change which
	 * value this plugin treats as the fallback.
	 *
	 * @param array $languages Language list.
	 * @return array
	 */
	private static function default_first( $languages ) {
		$default = self::site_default_locale();

		foreach ( $languages as $index => $language ) {
			if ( $language['locale'] === $default && $index > 0 ) {
				array_unshift( $languages, $language );
				unset( $languages[ $index + 1 ] );

				return array_values( $languages );
			}
		}

		return $languages;
	}

	/**
	 * The locale the active multilingual plugin considers the site default.
	 *
	 * @return string
	 */
	private static function site_default_locale() {
		if ( function_exists( 'pll_default_language' ) ) {
			$locale = pll_default_language( 'locale' );

			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, read here to learn the site default language.
		$wpml = apply_filters( 'wpml_default_language', null );

		if ( is_string( $wpml ) && '' !== $wpml && function_exists( 'icl_get_languages' ) ) {
			$list = icl_get_languages( 'skip_missing=0' );

			if ( is_array( $list ) && isset( $list[ $wpml ]['default_locale'] ) ) {
				return $list[ $wpml ]['default_locale'];
			}
		}

		$trp = get_option( 'trp_settings', array() );

		if ( is_array( $trp ) && ! empty( $trp['default-language'] ) ) {
			return (string) $trp['default-language'];
		}

		return get_locale();
	}

	/**
	 * Whether the site publishes in more than one language.
	 *
	 * @return bool
	 */
	public static function is_multilingual() {
		return count( self::languages() ) > 1;
	}

	/**
	 * The locale translated values fall back to.
	 *
	 * @return string
	 */
	public static function default_locale() {
		$languages = self::languages();

		return $languages[0]['locale'];
	}

	/**
	 * The locale a value should be resolved in.
	 *
	 * @param WC_Order|null $order Order, when the value is being shown for one.
	 * @return string
	 */
	public static function current_locale( $order = null ) {
		if ( $order instanceof WC_Order ) {
			$locale = self::order_locale( $order );

			if ( '' !== $locale ) {
				return $locale;
			}
		}

		return determine_locale();
	}

	/**
	 * The locale an order was placed in.
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty string when nothing recorded it.
	 */
	public static function order_locale( $order ) {
		$locale = (string) $order->get_meta( self::ORDER_LOCALE );

		if ( '' !== $locale ) {
			return $locale;
		}

		// WPML records the order language as a two letter code.
		$wpml = (string) $order->get_meta( 'wpml_language' );

		if ( '' !== $wpml ) {
			foreach ( self::languages() as $language ) {
				if ( $language['code'] === $wpml ) {
					return $language['locale'];
				}
			}
		}

		return '';
	}

	/**
	 * Picks the value for a locale, falling back to the default when nothing was translated.
	 *
	 * @param string $default      Value entered for the default language.
	 * @param array  $translations Values keyed by locale.
	 * @param string $locale       Wanted locale.
	 * @return string
	 */
	public static function resolve( $default, $translations, $locale = '' ) {
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return $default;
		}

		$locale = '' !== $locale ? $locale : determine_locale();

		if ( isset( $translations[ $locale ] ) && '' !== trim( (string) $translations[ $locale ] ) ) {
			return (string) $translations[ $locale ];
		}

		// uk and uk_UA are the same language written two ways, so they find each other. Two
		// regional variants are not: a Brazilian customer should not be shown European
		// Portuguese just because nobody translated pt_BR.
		$code       = substr( $locale, 0, 2 );
		$has_region = strlen( $locale ) > 2;

		foreach ( $translations as $key => $value ) {
			$key = (string) $key;

			if ( substr( $key, 0, 2 ) !== $code || '' === trim( (string) $value ) ) {
				continue;
			}

			if ( ! $has_region || strlen( $key ) <= 2 ) {
				return (string) $value;
			}
		}

		return $default;
	}

	/**
	 * Language list from Polylang.
	 *
	 * @return array
	 */
	private static function from_polylang() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}

		$locales = pll_languages_list( array( 'fields' => 'locale' ) );
		$slugs   = pll_languages_list( array( 'fields' => 'slug' ) );
		$names   = pll_languages_list( array( 'fields' => 'name' ) );

		if ( ! is_array( $locales ) || empty( $locales ) ) {
			return array();
		}

		$languages = array();

		foreach ( array_values( $locales ) as $index => $locale ) {
			$languages[] = array(
				'locale' => $locale,
				'code'   => isset( $slugs[ $index ] ) ? $slugs[ $index ] : substr( $locale, 0, 2 ),
				'name'   => isset( $names[ $index ] ) ? $names[ $index ] : self::locale_name( $locale ),
			);
		}

		return $languages;
	}

	/**
	 * Language list from WPML.
	 *
	 * @return array
	 */
	private static function from_wpml() {
		if ( ! function_exists( 'icl_get_languages' ) ) {
			return array();
		}

		$list = icl_get_languages( 'skip_missing=0' );

		if ( ! is_array( $list ) || empty( $list ) ) {
			return array();
		}

		$languages = array();

		foreach ( $list as $code => $language ) {
			$locale = isset( $language['default_locale'] ) ? $language['default_locale'] : $code;
			$name   = isset( $language['translated_name'] ) ? $language['translated_name'] : '';

			if ( '' === $name && isset( $language['native_name'] ) ) {
				$name = $language['native_name'];
			}

			$languages[] = array(
				'locale' => $locale,
				'code'   => (string) $code,
				'name'   => '' !== $name ? $name : self::locale_name( $locale ),
			);
		}

		return $languages;
	}

	/**
	 * Language list from TranslatePress.
	 *
	 * @return array
	 */
	private static function from_translatepress() {
		$settings = get_option( 'trp_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['translation-languages'] ) ) {
			return array();
		}

		$default   = isset( $settings['default-language'] ) ? $settings['default-language'] : '';
		$locales   = (array) $settings['translation-languages'];
		$languages = array();

		foreach ( $locales as $locale ) {
			$entry = array(
				'locale' => $locale,
				'code'   => substr( $locale, 0, 2 ),
				'name'   => self::locale_name( $locale ),
			);

			if ( $locale === $default ) {
				array_unshift( $languages, $entry );
			} else {
				$languages[] = $entry;
			}
		}

		return $languages;
	}

	/**
	 * A readable name for a locale, without asking the translations API.
	 *
	 * @param string $locale Locale code.
	 * @return string
	 */
	public static function locale_name( $locale ) {
		$names = array(
			'uk'    => 'Українська',
			'ru'    => 'Русский',
			'en'    => 'English',
			'pl'    => 'Polski',
			'de'    => 'Deutsch',
			'fr'    => 'Français',
			'es'    => 'Español',
			'it'    => 'Italiano',
			'cs'    => 'Čeština',
			'sk'    => 'Slovenčina',
			'ro'    => 'Română',
			'lt'    => 'Lietuvių',
			'lv'    => 'Latviešu',
			'et'    => 'Eesti',
			'bg'    => 'Български',
			'hu'    => 'Magyar',
			'tr'    => 'Türkçe',
			'pt'    => 'Português',
			'nl'    => 'Nederlands',
			'sv'    => 'Svenska',
			'he'    => 'עברית',
			'ka'    => 'ქართული',
		);

		$code = strtolower( substr( (string) $locale, 0, 2 ) );

		return isset( $names[ $code ] ) ? $names[ $code ] : (string) $locale;
	}

	/**
	 * The language LiqPay should render its payment page in.
	 *
	 * LiqPay offers Ukrainian and English only.
	 *
	 * @param string $locale Locale to map.
	 * @return string
	 */
	public static function liqpay_language( $locale ) {
		return 0 === strpos( (string) $locale, 'uk' ) ? 'uk' : 'en';
	}
}
