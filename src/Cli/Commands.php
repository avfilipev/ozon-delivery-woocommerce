<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Cli;

use Spoki\OzonDelivery\Admin\CatalogStatus;
use Spoki\OzonDelivery\Admin\HealthCheck;
use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Credentials;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Api\Transport;
use Spoki\OzonDelivery\Api\CookieJar;
use Spoki\OzonDelivery\Order\Creator;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Order\StatusSync;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\QuoteBuilder;
use Spoki\OzonDelivery\Support\Logger;
use WP_CLI;

/**
 * Команды WP-CLI: `wp ozon …`.
 *
 * Экономят десятки прогонов чекаута руками и, главное, дают записать живые
 * ответы Ozon в фикстуры — без них правило 11 неисполнимо.
 *
 * Обвязка WP-CLI, юнит-тестами не покрыта: логика живёт в вызываемых
 * классах, а FixtureWriter покрыт отдельно.
 */
final class Commands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'ozon', self::class );
	}

	/**
	 * Проверяет ключи и получает токен.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ozon token
	 *
	 * @param string[] $args
	 * @param array<string, string> $assoc_args
	 */
	public function token( array $args = array(), array $assoc_args = array() ): void {
		$credentials = Credentials::from_options();

		if ( ! $credentials->are_complete() ) {
			WP_CLI::error( 'Не заданы client_id и client_secret в настройках Ozon Доставки.' );
		}

		$logger    = new Logger();
		$transport = new Transport( new CookieJar(), $logger );
		$tokens    = new TokenStore( $transport, $credentials, $logger );

		if ( ! empty( $assoc_args['refresh'] ) ) {
			$tokens->forget();
		}

		try {
			$token = $tokens->token();
		} catch ( ApiException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// Сам токен не печатаем — правило 7.
		WP_CLI::success( sprintf( 'Токен получен, длина %d символов.', strlen( $token ) ) );

		$result = ( new HealthCheck( ClientFactory::create() ) )->run();

		if ( $result->ok ) {
			WP_CLI::success( $result->message );
		} else {
			WP_CLI::warning( $result->message );
		}
	}

	/**
	 * Работа с каталогом ПВЗ.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : sync — полный обход каталога, status — состояние.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ozon points sync
	 *     wp ozon points status
	 *
	 * @param string[] $args
	 * @param array<string, string> $assoc_args
	 */
	public function points( array $args, array $assoc_args = array() ): void {
		$action = $args[0] ?? 'status';

		$repository = new Repository();
		$sync       = new CatalogSync(
			new DeliveryPoints( ClientFactory::create() ),
			$repository,
			new Logger()
		);

		if ( 'status' === $action ) {
			WP_CLI::log( ( new CatalogStatus( $repository, $sync ) )->describe() );

			return;
		}

		if ( 'sync' !== $action ) {
			WP_CLI::error( sprintf( 'Неизвестное действие «%s». Доступны sync и status.', $action ) );
		}

		$sync->start();

		WP_CLI::log( 'Обход каталога начат…' );

		do {
			try {
				$state = $sync->run_step();
			} catch ( ApiException $e ) {
				WP_CLI::error( $e->getMessage() );
			}

			WP_CLI::log( sprintf( 'Обработано точек: %d', $state->processed ) );
		} while ( ! $state->finished );

		WP_CLI::success( sprintf( 'Каталог обновлён. Точек в базе: %d.', $repository->count() ) );
	}

	/**
	 * Предрасчёт доставки по заказу.
	 *
	 * ## OPTIONS
	 *
	 * <order_id>
	 * : Идентификатор заказа WooCommerce.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ozon checkout 123
	 *
	 * @param string[] $args
	 * @param array<string, string> $assoc_args
	 */
	public function checkout( array $args, array $assoc_args = array() ): void {
		$order = $this->order( $args[0] ?? '' );

		$point_id = Meta::point_id( $order );

		if ( null === $point_id ) {
			WP_CLI::error( 'В заказе не выбран пункт выдачи Ozon.' );
		}

		$builder = QuoteBuilder::create();
		$package = \Spoki\OzonDelivery\Order\OrderPackage::from_order( $order );

		$quote = $builder->quote(
			$package,
			(string) $order->get_billing_phone(),
			Destination::point( $point_id )
		);

		if ( ! $quote->available ) {
			WP_CLI::warning( $quote->message );

			return;
		}

		WP_CLI::log( sprintf( 'Доставка: %s', (string) $quote->delivery_cost?->amount ) );
		WP_CLI::log( sprintf( 'Страховка: %s', (string) $quote->insurance_cost?->amount ) );
		WP_CLI::log( sprintf( 'Итого: %s', (string) $quote->total()?->amount ) );
		WP_CLI::log( sprintf( 'Срок, дней: %s', (string) $quote->estimated_delivery_days ) );
		WP_CLI::success( 'Расчёт получен.' );
	}

	/**
	 * Передаёт заказ в Ozon.
	 *
	 * ## OPTIONS
	 *
	 * <order_id>
	 * : Идентификатор заказа WooCommerce.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ozon push 123
	 *
	 * @param string[] $args
	 * @param array<string, string> $assoc_args
	 */
	public function push( array $args, array $assoc_args = array() ): void {
		$order = $this->order( $args[0] ?? '' );

		if ( ClientFactory::is_dry_run() ) {
			WP_CLI::warning( 'Включён dry-run: заказ не уйдёт в Ozon.' );
		}

		$result = Creator::create()->push( $order );

		if ( ! $result->succeeded() ) {
			WP_CLI::error( $result->error_message() );
		}

		WP_CLI::success(
			sprintf( 'Заказ передан. Ozon: %s, отправление: %s.', $result->order_number, (string) $result->first_posting_number() )
		);
	}

	/**
	 * Обновляет статус отправления по заказу.
	 *
	 * ## OPTIONS
	 *
	 * <order_id>
	 * : Идентификатор заказа WooCommerce.
	 *
	 * @param string[] $args
	 * @param array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args = array() ): void {
		$order = $this->order( $args[0] ?? '' );

		$info = StatusSync::create()->sync_order( $order );

		if ( null === $info ) {
			WP_CLI::warning( 'Статус не обновлён: нет отправления, статус уже финальный или Ozon не ответил.' );

			return;
		}

		WP_CLI::success( sprintf( '%s (%s)', $info->status->label(), $info->status->value ) );
	}

	/**
	 * Произвольный запрос к API. Главное — запись живого ответа в фикстуру.
	 *
	 * Тело передаётся в --body, а не в --json: последний зарезервирован самим
	 * WP-CLI как синоним --format=json и до команды не доходит.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Путь метода, например /v1/posting/info
	 *
	 * [--body=<json>]
	 * : Тело запроса в JSON. По умолчанию пустой объект.
	 *
	 * [--save-fixture=<name>]
	 * : Записать ответ в tests/Fixtures/<name>. Секреты вычищаются.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ozon raw /v1/posting/info --body='{"posting_numbers":["1"]}' --save-fixture=posting-info
	 *
	 * @param string[] $args
	 * @param array<string, string> $assoc_args
	 */
	public function raw( array $args, array $assoc_args = array() ): void {
		$path = (string) ( $args[0] ?? '' );

		if ( '' === $path ) {
			WP_CLI::error( 'Укажите путь метода, например /v1/posting/info' );
		}

		$payload = json_decode( (string) ( $assoc_args['body'] ?? '{}' ), true );

		if ( ! is_array( $payload ) ) {
			WP_CLI::error( 'Тело запроса в --body не разобрать.' );
		}

		try {
			$response = ClientFactory::create()->post_raw( $path, $payload );
		} catch ( ApiException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::log( sprintf( 'HTTP %d, trace-id: %s', $response->status, $response->trace_id ) );
		WP_CLI::log( $response->body );

		$name = (string) ( $assoc_args['save-fixture'] ?? '' );

		if ( '' === $name ) {
			return;
		}

		$path_written = FixtureWriter::create()->write( $name, $response->body );

		WP_CLI::success( sprintf( 'Фикстура записана: %s', $path_written ) );
	}

	private function order( string $order_id ): object {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof \WC_Order ) {
			WP_CLI::error( sprintf( 'Заказ %s не найден.', $order_id ) );
		}

		return $order;
	}
}
