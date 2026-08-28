<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Install\Migrations;

/**
 * Локальный каталог пунктов выдачи.
 *
 * Ozon отдаёт каталог только курсорным обходом и без адресов, поэтому точки
 * хранятся у себя. Фильтрация по ограничениям делается прямо в SQL: тащить
 * заведомо неподходящие точки в check-availability — значит дёргать API
 * впустую.
 *
 * Все значения уходят через $wpdb->prepare(). В SQL интерполируется только
 * имя таблицы — оно берётся из Migrations::points_table() и складывается из
 * префикса WordPress и константы плагина, пользовательских данных там нет.
 */

// Прямые запросы к своей таблице — суть этого класса; кэширование делает
// вызывающий код, а не репозиторий.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
final class Repository {

	private function table(): string {
		return Migrations::points_table();
	}

	public function save( DeliveryPoint $point ): bool {
		global $wpdb;

		$row = $point->to_row();

		$row['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->replace( $this->table(), $row );
	}

	/**
	 * @param DeliveryPoint[] $points
	 *
	 * @return int Сколько точек записано.
	 */
	public function save_many( array $points ): int {
		$saved = 0;

		foreach ( $points as $point ) {
			if ( $this->save( $point ) ) {
				++$saved;
			}
		}

		return $saved;
	}

	public function find( int $delivery_point_id ): ?DeliveryPoint {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE delivery_point_id = %d LIMIT 1',
				$delivery_point_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return null;
		}

		return DeliveryPoint::from_row( (array) $rows[0] );
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() );
	}

	public function count_active(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE is_active = 1' );
	}

	/**
	 * Когда каталог обновлялся в последний раз.
	 */
	public function last_synced_at(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( 'SELECT MAX(updated_at) FROM ' . $this->table() );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * @return DeliveryPoint[]
	 */
	public function search( PointQuery $query ): array {
		global $wpdb;

		// Закрытые точки не показываются никогда.
		$where = array( 'is_active = 1' );
		$args  = array();

		$this->add_location_conditions( $query, $where, $args );
		$this->add_parcel_conditions( $query, $where, $args );
		$this->add_price_conditions( $query, $where, $args );

		if ( null !== $query->shipment_method_id ) {
			// Пустой список методов означает «неизвестно» — такие точки не
			// отсекаем, отфильтрует Ozon.
			$where[] = "( shipment_method_ids = '' OR shipment_method_ids IS NULL OR FIND_IN_SET(%d, shipment_method_ids) )";
			$args[]  = $query->shipment_method_id;
		}

		// Точки самого города идут первыми: иначе полсотни мест в списке
		// займут пригороды, отсортированные по алфавиту.
		$order = 'city ASC,';

		if ( null !== $query->city && '' !== $query->city ) {
			$order  = '( city = %s ) DESC, city ASC,';
			$args[] = $query->city;
		}

		$sql = 'SELECT * FROM ' . $this->table()
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY ' . $order . ' name ASC LIMIT %d';

		$args[] = max( 1, $query->limit );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( $row ): DeliveryPoint => DeliveryPoint::from_row( (array) $row ),
			$rows
		);
	}

	/**
	 * Удаляет точки, которых не было в последнем обходе каталога: Ozon их
	 * больше не отдаёт, показывать их покупателю нельзя.
	 *
	 * @return int Сколько точек удалено.
	 */
	public function delete_stale( string $synced_since ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $this->table() . ' WHERE updated_at < %s',
				$synced_since
			)
		);
	}

	public function delete_all(): int {
		global $wpdb;

		return (int) $wpdb->query( 'DELETE FROM ' . $this->table() );
	}

	/**
	 * Экранирует то, что LIKE считает подстановкой.
	 */
	private function like_escape( string $value ): string {
		global $wpdb;

		return $wpdb->esc_like( $value );
	}

	/**
	 * @param string[]          $where
	 * @param array<int, mixed> $args
	 */
	private function add_location_conditions( PointQuery $query, array &$where, array &$args ): void {
		if ( null !== $query->city && '' !== $query->city ) {
			// Совпадения по адресу — не прихоть. Новая Москва выглядит как
			// «Россия, Москва, Марушкинское, Большое Покровское, Лесная
			// улица, 16д»: город точки здесь действительно посёлок, и разбор
			// не ошибается. Но покупатель ищет «Москву», а таких точек в
			// боевом каталоге 499 в Москве и 315 в Петербурге — их не
			// находил никто. Совпадение ищется по целому сегменту адреса,
			// иначе «Москва» поймает ещё и улицу Москвина.
			//
			// LIKE с ведущим процентом индексом не пользуется: это полный
			// проход по таблице, на боевом каталоге из 45 363 строк — около
			// 80 мс. Поиск запускается нажатием кнопки, не на каждый ввод
			// символа, поэтому цена приемлемая.
			$where[] = '( city = %s OR full_address LIKE %s )';
			$args[]  = $query->city;
			$args[]  = '%, ' . $this->like_escape( $query->city ) . ',%';
		}

		if ( $query->has_bounding_box() ) {
			$where[] = 'latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f';
			$args[]  = $query->min_latitude;
			$args[]  = $query->max_latitude;
			$args[]  = $query->min_longitude;
			$args[]  = $query->max_longitude;
		}
	}

	/**
	 * NULL в ограничении означает «предела нет», поэтому каждое условие
	 * пропускает точки, у которых поле не заполнено.
	 *
	 * @param string[]          $where
	 * @param array<int, mixed> $args
	 */
	private function add_parcel_conditions( PointQuery $query, array &$where, array &$args ): void {
		if ( null === $query->parcel ) {
			return;
		}

		$parcel = $query->parcel;

		$where[] = '( min_weight_g IS NULL OR min_weight_g <= %d )';
		$args[]  = $parcel->weight_g;

		$where[] = '( max_weight_g IS NULL OR max_weight_g >= %d )';
		$args[]  = $parcel->weight_g;

		$sides = array(
			'max_length_mm' => $parcel->length_mm,
			'max_width_mm'  => $parcel->width_mm,
			'max_height_mm' => $parcel->height_mm,
		);

		foreach ( $sides as $column => $value ) {
			$where[] = sprintf( '( %s IS NULL OR %s >= %%d )', $column, $column );
			$args[]  = $value;
		}
	}

	/**
	 * Ограничения в другой валюте не сравниваются — как и в Restrictions.
	 *
	 * @param string[]          $where
	 * @param array<int, mixed> $args
	 */
	private function add_price_conditions( PointQuery $query, array &$where, array &$args ): void {
		if ( null === $query->declared_value ) {
			return;
		}

		$minor    = $query->declared_value->minor_units();
		$currency = $query->declared_value->currency_code;

		$where[] = '( min_price_minor IS NULL OR price_currency <> %s OR min_price_minor <= %d )';
		$args[]  = $currency;
		$args[]  = $minor;

		$where[] = '( max_price_minor IS NULL OR price_currency <> %s OR max_price_minor >= %d )';
		$args[]  = $currency;
		$args[]  = $minor;
	}
}
