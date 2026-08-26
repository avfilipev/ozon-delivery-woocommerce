<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Tests\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// В тестах достаточно поведения "вернуть как есть" — реальная санитизация проверяется в WordPress.
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	public function test_get_fields_returns_five_expected_fields_in_order(): void {
		$ids = array_column( ( new Settings() )->get_fields(), 'id' );

		self::assertSame(
			array(
				Settings::FIELD_CLIENT_ID,
				Settings::FIELD_CLIENT_SECRET,
				Settings::FIELD_SCOPE,
				Settings::FIELD_SHIPMENT_METHOD_ID,
				Settings::FIELD_DRY_RUN,
			),
			$ids
		);
	}

	public function test_sanitize_applies_text_sanitizer_to_plain_fields(): void {
		$sanitized = ( new Settings() )->sanitize(
			array(
				Settings::FIELD_CLIENT_ID          => ' abc ',
				Settings::FIELD_SCOPE              => 'delivery-api.all',
				Settings::FIELD_SHIPMENT_METHOD_ID => '12345',
			),
			array()
		);

		self::assertSame( ' abc ', $sanitized[ Settings::FIELD_CLIENT_ID ] );
		self::assertSame( 'delivery-api.all', $sanitized[ Settings::FIELD_SCOPE ] );
		self::assertSame( '12345', $sanitized[ Settings::FIELD_SHIPMENT_METHOD_ID ] );
	}

	public function test_sanitize_keeps_existing_secret_when_posted_value_is_empty(): void {
		$sanitized = ( new Settings() )->sanitize(
			array( Settings::FIELD_CLIENT_SECRET => '' ),
			array( Settings::FIELD_CLIENT_SECRET => 'already-stored-secret' )
		);

		self::assertSame( 'already-stored-secret', $sanitized[ Settings::FIELD_CLIENT_SECRET ] );
	}

	public function test_sanitize_keeps_existing_secret_when_field_not_posted_at_all(): void {
		$sanitized = ( new Settings() )->sanitize(
			array(),
			array( Settings::FIELD_CLIENT_SECRET => 'already-stored-secret' )
		);

		self::assertSame( 'already-stored-secret', $sanitized[ Settings::FIELD_CLIENT_SECRET ] );
	}

	public function test_sanitize_replaces_secret_when_new_value_posted(): void {
		$sanitized = ( new Settings() )->sanitize(
			array( Settings::FIELD_CLIENT_SECRET => 'brand-new-secret' ),
			array( Settings::FIELD_CLIENT_SECRET => 'already-stored-secret' )
		);

		self::assertSame( 'brand-new-secret', $sanitized[ Settings::FIELD_CLIENT_SECRET ] );
	}

	public function test_sanitize_dry_run_is_yes_when_checkbox_present(): void {
		$sanitized = ( new Settings() )->sanitize(
			array( Settings::FIELD_DRY_RUN => 'yes' ),
			array()
		);

		self::assertSame( 'yes', $sanitized[ Settings::FIELD_DRY_RUN ] );
	}

	public function test_sanitize_dry_run_is_no_when_checkbox_absent(): void {
		$sanitized = ( new Settings() )->sanitize( array(), array() );

		self::assertSame( 'no', $sanitized[ Settings::FIELD_DRY_RUN ] );
	}

	public function test_mask_secret_for_display_returns_empty_string_for_empty_secret(): void {
		self::assertSame( '', ( new Settings() )->mask_secret_for_display( '' ) );
	}

	public function test_mask_secret_for_display_never_contains_the_real_secret(): void {
		$masked = ( new Settings() )->mask_secret_for_display( 'super-secret-value' );

		self::assertNotSame( '', $masked );
		self::assertStringNotContainsString( 'super-secret-value', $masked );
	}
}
