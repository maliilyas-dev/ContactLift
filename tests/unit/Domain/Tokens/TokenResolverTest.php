<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Tokens;

use ContactLift\Domain\Tokens\TokenResolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers AT10: an unknown token and an absent value must never leave raw
 * template tokens or broken punctuation in the resolved message.
 */
final class TokenResolverTest extends TestCase {

	private TokenResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new TokenResolver();
	}

	public function test_replaces_known_tokens(): void {
		$result = $this->resolver->resolve(
			'Hello, I have a question about {page_title}. {page_url}',
			array(
				'page_title' => 'Pricing',
				'page_url'   => 'https://example.com/pricing',
			)
		);

		self::assertSame( 'Hello, I have a question about Pricing. https://example.com/pricing', $result );
	}

	public function test_absent_product_sku_leaves_no_braces_or_broken_punctuation(): void {
		$result = $this->resolver->resolve(
			'Hello, I am interested in {product_name}. {variation} {product_url}',
			array(
				'product_name' => 'Blue Mug',
				'product_url'  => 'https://example.com/blue-mug',
				// variation intentionally absent, mirroring "no SKU/variation" state.
			)
		);

		self::assertStringNotContainsString( '{', $result );
		self::assertStringNotContainsString( '}', $result );
		self::assertSame( 'Hello, I am interested in Blue Mug. https://example.com/blue-mug', $result );
	}

	public function test_unknown_token_is_dropped(): void {
		$result = $this->resolver->resolve( 'Hi {not_a_real_token} there', array() );

		self::assertSame( 'Hi there', $result );
	}
}
