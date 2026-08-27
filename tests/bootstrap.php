<?php // phpcs:ignore WordPress.Files.FileName

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

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
