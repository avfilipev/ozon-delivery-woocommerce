<?php // phpcs:ignore WordPress.Files.FileName

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Константы формата выдачи $wpdb: ядро WordPress в юнит-тестах не загружается.
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Минимальный стаб WP_Error: Brain Monkey не поднимает ядро WordPress,
	 * а Transport обязан отличать сетевую ошибку от HTTP-ответа.
	 */
	class WP_Error {

		public function __construct(
			private string $code = '',
			private string $message = ''
		) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
