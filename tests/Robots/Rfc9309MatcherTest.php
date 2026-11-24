<?php
/**
 * RFC 9309 parser/evaluator tests (pure, table-driven).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Robots;

use PHPUnit\Framework\TestCase;
use X7velo\Robots\PathMatcher;
use X7velo\Robots\RobotsTxt;

/**
 * Group selection, merging, longest match, wildcards and parsing edge cases.
 */
final class Rfc9309MatcherTest extends TestCase {

	/**
	 * Cases: [robots.txt, token, path, expected allowed].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
	 */
	public function provide_cases(): array {
		$wp_woo = "User-agent: *\nDisallow: /wp-content/uploads/wc-logs/\nDisallow: /*?add-to-cart=\nDisallow: /*?*add-to-cart=\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://shop.example/wp-sitemap.xml\n";

		return array(
			// No rules / no groups.
			'empty file allows everything'               => array( '', 'GPTBot', '/', true ),
			'only comments'                              => array( "# nothing\n# here", 'GPTBot', '/x', true ),
			'no matching group and no star'              => array( "User-agent: GPTBot\nDisallow: /", 'OAI-SearchBot', '/', true ),
			'rules before any user-agent are ignored'    => array( "Disallow: /\nUser-agent: *\nAllow: /", 'Googlebot', '/', true ),

			// Star group.
			'star disallow all'                          => array( "User-agent: *\nDisallow: /", 'OAI-SearchBot', '/', false ),
			'star disallow all blocks deep path'         => array( "User-agent: *\nDisallow: /", 'OAI-SearchBot', '/product/a/', false ),
			'empty disallow allows'                      => array( "User-agent: *\nDisallow:", 'Bingbot', '/', true ),
			'star prefix match'                          => array( "User-agent: *\nDisallow: /private", 'Bingbot', '/private/x', false ),
			'star prefix non-match'                      => array( "User-agent: *\nDisallow: /private", 'Bingbot', '/public', true ),
			'prefix matches longer segment'              => array( "User-agent: *\nDisallow: /shop", 'Bingbot', '/shopping', false ),

			// Specific group wins over star (no inheritance from *).
			'named group overrides star'                 => array( "User-agent: *\nDisallow: /\n\nUser-agent: OAI-SearchBot\nAllow: /", 'OAI-SearchBot', '/', true ),
			'named group overrides star for others too'  => array( "User-agent: *\nDisallow: /\n\nUser-agent: OAI-SearchBot\nAllow: /", 'PerplexityBot', '/', false ),
			'named empty group does not inherit star'    => array( "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow:", 'Googlebot', '/', true ),
			'named group blocks while star allows'       => array( "User-agent: *\nAllow: /\n\nUser-agent: GPTBot\nDisallow: /", 'GPTBot', '/', false ),

			// Case-insensitive tokens (AC31) and token extraction.
			'lowercase user-agent value'                 => array( "user-agent: perplexitybot\ndisallow: /", 'PerplexityBot', '/', false ),
			'uppercase token in file'                    => array( "USER-AGENT: OAI-SEARCHBOT\nDISALLOW: /", 'OAI-SearchBot', '/', false ),
			'version suffix stripped'                    => array( "User-agent: Googlebot/2.1\nDisallow: /", 'Googlebot', '/', false ),
			'token must match whole, not prefix'         => array( "User-agent: Applebot\nDisallow: /", 'Applebot-Extended', '/', true ),
			'longer token does not match shorter'        => array( "User-agent: Applebot-Extended\nDisallow: /", 'Applebot', '/', true ),
			'underscore token'                           => array( "User-agent: __usage_ai-training\nDisallow: /", '__usage_ai-training', '/', false ),

			// Group building and merging.
			'multiple user-agents share a group'         => array( "User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /", 'ClaudeBot', '/', false ),
			'blank line between agents keeps the group'  => array( "User-agent: GPTBot\n\nUser-agent: ClaudeBot\nDisallow: /", 'GPTBot', '/', false ),
			'agent after rule starts a new group'        => array( "User-agent: GPTBot\nDisallow: /\nUser-agent: ClaudeBot\nAllow: /", 'ClaudeBot', '/', true ),
			'groups naming same token are merged'        => array( "User-agent: Bingbot\nDisallow: /a\n\nUser-agent: Bingbot\nDisallow: /b", 'Bingbot', '/b/x', false ),
			'merged groups keep first group rules'       => array( "User-agent: Bingbot\nDisallow: /a\n\nUser-agent: Bingbot\nDisallow: /b", 'Bingbot', '/a/x', false ),
			'star groups are merged'                     => array( "User-agent: *\nDisallow: /a\n\nUser-agent: *\nDisallow: /", 'Bingbot', '/', false ),
			'yoast empty star group merges harmlessly'   => array( $wp_woo . "\n# START YOAST BLOCK\nUser-agent: *\nDisallow:\n\nSitemap: https://shop.example/sitemap_index.xml\n# END YOAST BLOCK", 'OAI-SearchBot', '/', true ),
			'unknown records do not end a group'         => array( "User-agent: GPTBot\nCrawl-delay: 10\nUser-agent: ClaudeBot\nDisallow: /", 'GPTBot', '/', false ),
			'sitemap line inside group does not end it'  => array( "User-agent: GPTBot\nSitemap: https://x/s.xml\nDisallow: /", 'GPTBot', '/', false ),

			// Longest match and Allow-wins ties.
			'longer allow beats shorter disallow'        => array( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php", 'Bingbot', '/wp-admin/admin-ajax.php', true ),
			'shorter allow loses to longer disallow'     => array( "User-agent: *\nAllow: /\nDisallow: /cart/", 'Bingbot', '/cart/', false ),
			'tie: allow wins'                            => array( "User-agent: *\nDisallow: /page\nAllow: /page", 'Bingbot', '/page', true ),
			'tie: allow wins regardless of order'        => array( "User-agent: *\nAllow: /page\nDisallow: /page", 'Bingbot', '/page', true ),
			'allow root with disallow all: tie -> allow' => array( "User-agent: *\nDisallow: /\nAllow: /", 'Bingbot', '/x', true ),
			'disallow all except product'                => array( "User-agent: *\nDisallow: /\nAllow: /product/", 'Bingbot', '/product/a/', true ),
			'disallow all except product (root)'         => array( "User-agent: *\nDisallow: /\nAllow: /product/", 'Bingbot', '/', false ),

			// Wildcards and end anchors.
			'wildcard query add-to-cart'                 => array( $wp_woo, 'Bingbot', '/shop/?add-to-cart=12', false ),
			'wildcard query add-to-cart second param'    => array( $wp_woo, 'Bingbot', '/shop/?a=1&add-to-cart=12', false ),
			'woo rules allow product'                    => array( $wp_woo, 'Bingbot', '/product/a/', true ),
			'woo rules allow root'                       => array( $wp_woo, 'Bingbot', '/', true ),
			'dollar anchors end'                         => array( "User-agent: *\nDisallow: /*.pdf$", 'Bingbot', '/a/b.pdf', false ),
			'dollar anchor not matching longer'          => array( "User-agent: *\nDisallow: /*.pdf$", 'Bingbot', '/a/b.pdf?x=1', true ),
			'root dollar blocks only root'               => array( "User-agent: *\nDisallow: /$", 'Bingbot', '/', false ),
			'root dollar allows other paths'             => array( "User-agent: *\nDisallow: /$", 'Bingbot', '/shop/', true ),
			'slash-star equals disallow all'             => array( "User-agent: *\nDisallow: /*", 'Bingbot', '/anything', false ),
			'multiple stars collapse'                    => array( "User-agent: *\nDisallow: /a***b", 'Bingbot', '/a-x-b', false ),
			'regex metacharacters are literal'           => array( "User-agent: *\nDisallow: /a.b(c)+", 'Bingbot', '/aXb(c)+', true ),
			'regex metacharacters literal match'         => array( "User-agent: *\nDisallow: /a.b(c)+", 'Bingbot', '/a.b(c)+', false ),
			'case-sensitive paths'                       => array( "User-agent: *\nDisallow: /Shop", 'Bingbot', '/shop', true ),
			'pattern without leading slash'              => array( "User-agent: *\nDisallow: private", 'Bingbot', '/private', false ),

			// Percent-encoding.
			'utf8 path vs encoded pattern'               => array( "User-agent: *\nDisallow: /caf%C3%A9", 'Bingbot', '/café', false ),
			'encoded path vs utf8 pattern'               => array( "User-agent: *\nDisallow: /café", 'Bingbot', '/caf%c3%a9', false ),
			'encoded unreserved char is decoded'         => array( "User-agent: *\nDisallow: /%7Euser", 'Bingbot', '/~user', false ),

			// Syntax tolerance.
			'CRLF line endings'                          => array( "User-agent: *\r\nDisallow: /\r\n", 'Bingbot', '/', false ),
			'CR line endings'                            => array( "User-agent: *\rDisallow: /\r", 'Bingbot', '/', false ),
			'UTF-8 BOM'                                  => array( "\xEF\xBB\xBFUser-agent: *\nDisallow: /", 'Bingbot', '/', false ),
			'inline comments'                            => array( "User-agent: * # all\nDisallow: / # everything", 'Bingbot', '/', false ),
			'whitespace around key and colon'            => array( "  User-agent  :  *  \n  Disallow  :  /  ", 'Bingbot', '/', false ),
			'line without colon is ignored'              => array( "User-agent *\nDisallow /", 'Bingbot', '/', true ),
			'robots.txt itself always allowed'           => array( "User-agent: *\nDisallow: /", 'Bingbot', '/robots.txt', true ),
		);
	}

	/**
	 * Table-driven evaluation.
	 *
	 * @dataProvider provide_cases
	 *
	 * @param string $robots   robots.txt body.
	 * @param string $token    Product token.
	 * @param string $path     Path.
	 * @param bool   $expected Whether allowed.
	 */
	public function test_evaluation( string $robots, string $token, string $path, bool $expected ): void {
		$this->assertSame( $expected, RobotsTxt::parse( $robots )->is_allowed( $token, $path ) );
	}

	/**
	 * AC31: owner-named detection is case-insensitive; `*` never counts as naming.
	 */
	public function test_user_agent_case_insensitive(): void {
		$robots = RobotsTxt::parse( "user-agent: perplexitybot\ndisallow: /\n\nUser-agent: *\nDisallow:" );
		$this->assertTrue( $robots->names_agent( 'PerplexityBot' ) );
		$this->assertTrue( $robots->names_agent( 'PERPLEXITYBOT' ) );
		$this->assertFalse( $robots->names_agent( 'OAI-SearchBot' ) );
		$this->assertFalse( $robots->names_agent( '*' ) );
	}

	/**
	 * Sitemap lines are collected globally, in order.
	 */
	public function test_sitemaps_collected(): void {
		$robots = RobotsTxt::parse( "Sitemap: https://a/s1.xml\nUser-agent: *\nDisallow:\nSitemap:   https://a/s2.xml  \nsitemap: https://a/s3.xml\nSitemap:" );
		$this->assertSame( array( 'https://a/s1.xml', 'https://a/s2.xml', 'https://a/s3.xml' ), $robots->sitemaps() );
	}

	/**
	 * The rules_for() method returns null when nothing applies.
	 */
	public function test_rules_for(): void {
		$this->assertNull( RobotsTxt::parse( "User-agent: GPTBot\nDisallow: /" )->rules_for( 'Bingbot' ) );
		$this->assertCount( 2, RobotsTxt::parse( "User-agent: *\nDisallow: /a\nAllow: /b" )->rules_for( 'Bingbot' ) );
	}

	/**
	 * Very large inputs are truncated at MAX_BYTES (rules after the cap are ignored).
	 */
	public function test_size_cap(): void {
		$robots = "User-agent: *\nDisallow: /a\n" . str_repeat( "# filler\n", 70000 ) . "Disallow: /\n";
		$this->assertTrue( RobotsTxt::parse( $robots )->is_allowed( 'Bingbot', '/b' ) );
	}

	/**
	 * Path normalisation helpers.
	 */
	public function test_normalize(): void {
		$this->assertSame( '/caf%C3%A9', PathMatcher::normalize( '/café' ) );
		$this->assertSame( '/caf%C3%A9', PathMatcher::normalize( '/caf%c3%a9' ) );
		$this->assertSame( '/~a', PathMatcher::normalize( '/%7ea' ) );
		$this->assertSame( '/a%2Fb', PathMatcher::normalize( '/a%2fb' ) );
		$this->assertSame( '/a%20b', PathMatcher::normalize( '/a b' ) );
		$this->assertSame( '/a*b', PathMatcher::normalize( '/a**b' ) );
		$this->assertSame( '/%', PathMatcher::normalize( '/%' ) );
		$this->assertSame( '/x', PathMatcher::normalize_pattern( 'x' ) );
		$this->assertSame( '*x', PathMatcher::normalize_pattern( '*x' ) );
	}
}
