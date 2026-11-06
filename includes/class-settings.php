<?php
/**
 * Réglages et capacité requise.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Settings {

	const OPTION = 'bcamc_settings';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function capability(): string {
		return (string) apply_filters( 'bcamc_capability', 'manage_options' );
	}

	public static function user_can(): bool {
		return current_user_can( self::capability() );
	}

	public static function defaults(): array {
		return array(
			'batch_size'        => 200,
			'min_age_days'      => 30,
			'include_documents' => false,
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		return array(
			'batch_size'        => max( 20, min( 2000, (int) ( $input['batch_size'] ?? $defaults['batch_size'] ) ) ),
			'min_age_days'      => max( 0, min( 3650, (int) ( $input['min_age_days'] ?? $defaults['min_age_days'] ) ) ),
			'include_documents' => ! empty( $input['include_documents'] ),
		);
	}

	public static function register(): void {
		register_setting(
			'bcamc_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}
}
