<?php
/**
 * SEOPress adapter tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection\Adapters;

use WP_UnitTestCase;
use X7velo\Detection\Adapters\Seopress;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;

/**
 * SEOPress free, options from the captured 10.4 fixture.
 */
final class SeopressAdapterTest extends WP_UnitTestCase {

	/**
	 * Adapter.
	 */
	private function adapter(): Seopress {
		return new Seopress( ( new FakeEnvironment() )->with( 'seopress' ) );
	}

	/**
	 * AC19: free emits no Product and has no llms.txt.
	 */
	public function test_free_does_not_emit_product(): void {
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		$this->assertTrue( $this->adapter()->is_active() );
		$this->assertFalse( $this->adapter()->emits_product() );
		$this->assertNull( $this->adapter()->llms_source(), 'toggle-llms is stored but free has no llms.txt.' );
		$this->assertFalse( $this->adapter()->emits_organization() );
	}

	/**
	 * Sitemap: both toggles + product include; noindex excludes.
	 */
	public function test_sitemap(): void {
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		$this->assertSame(
			array(
				'path'              => '/sitemaps.xml',
				'includes_products' => true,
			),
			$this->adapter()->sitemap()
		);

		$titles = get_option( 'seopress_titles_option_name' );
		$titles['seopress_titles_single_titles']['product']['noindex'] = '1';
		update_option( 'seopress_titles_option_name', $titles );
		$this->assertFalse( $this->adapter()->sitemap()['includes_products'] );

		$sitemap = get_option( 'seopress_xml_sitemap_option_name' );
		unset( $sitemap['seopress_xml_sitemap_post_types_list']['product'] );
		update_option( 'seopress_xml_sitemap_option_name', $sitemap );
		$this->assertFalse( $this->adapter()->sitemap()['includes_products'] );

		SeoFixtures::merge( 'seopress_toggle', array( 'toggle-xml-sitemap' => '0' ) );
		$this->assertNull( $this->adapter()->sitemap() );
	}

	/**
	 * Organization once a Knowledge Graph type is set.
	 */
	public function test_organization(): void {
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		update_option( 'seopress_social_option_name', array( 'seopress_social_knowledge_type' => 'Organization' ) );
		$this->assertTrue( $this->adapter()->emits_organization() );
	}
}
