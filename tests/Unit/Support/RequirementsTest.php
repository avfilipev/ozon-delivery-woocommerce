<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Support;

use Spoki\OzonDelivery\Support\Requirements;
use Spoki\OzonDelivery\Tests\TestCase;

final class RequirementsTest extends TestCase {

	public function test_passes_when_all_versions_satisfy_minimums(): void {
		$errors = ( new Requirements() )->check( '8.1.0', '6.4.0', '8.2.0' );

		self::assertSame( array(), $errors );
	}

	public function test_passes_when_installed_versions_are_newer(): void {
		$errors = ( new Requirements() )->check( '8.3.5', '6.6.1', '9.1.0' );

		self::assertSame( array(), $errors );
	}

	public function test_fails_when_php_is_too_old(): void {
		$errors = ( new Requirements() )->check( '8.0.9', '6.4.0', '8.2.0' );

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'PHP', $errors[0] );
		self::assertStringContainsString( '8.1', $errors[0] );
	}

	public function test_fails_when_wordpress_is_too_old(): void {
		$errors = ( new Requirements() )->check( '8.1.0', '6.3.9', '8.2.0' );

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'WordPress', $errors[0] );
		self::assertStringContainsString( '6.4', $errors[0] );
	}

	public function test_fails_when_woocommerce_is_not_active(): void {
		$errors = ( new Requirements() )->check( '8.1.0', '6.4.0', null );

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'WooCommerce', $errors[0] );
	}

	public function test_fails_when_woocommerce_is_too_old(): void {
		$errors = ( new Requirements() )->check( '8.1.0', '6.4.0', '8.1.9' );

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'WooCommerce', $errors[0] );
		self::assertStringContainsString( '8.2', $errors[0] );
	}

	public function test_reports_all_failures_at_once(): void {
		$errors = ( new Requirements() )->check( '8.0.0', '6.0.0', null );

		self::assertCount( 3, $errors );
	}
}
