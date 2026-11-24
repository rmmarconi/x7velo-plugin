<?php
/**
 * SEO/schema plugin detection adapter contract (spec §7.2).
 *
 * @package X7velo
 */

namespace X7velo\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * One adapter per plugin. Every answer comes from options/constants/local files, so it is the
 * same in wp-admin, REST, cron, CLI and on the front end. Adapters never read hook state.
 *
 * Only an active adapter is consulted; inactive adapters' other methods may return anything.
 */
interface Adapter {

	/**
	 * Stable ID, e.g. "yoast". Stored in audit details.
	 */
	public function id(): string;

	/**
	 * Product name shown to the owner ("Yoast SEO"). Not translated (brand name).
	 */
	public function label(): string;

	/**
	 * Whether the plugin is present and live (Rank Math: registration valid).
	 */
	public function is_active(): bool;

	/**
	 * Whether the plugin emits its own Product JSON-LD on product pages.
	 */
	public function emits_product(): bool;

	/**
	 * Whether the plugin removes WooCommerce core's Product JSON-LD when it emits its own.
	 */
	public function removes_woo_product(): bool;

	/**
	 * Whether the plugin emits an Organization node.
	 */
	public function emits_organization(): bool;

	/**
	 * The plugin's llms.txt, or null if it has none enabled.
	 *
	 * @return array{kind: string, path?: string}|null kind "physical" (file at `path`) or "dynamic".
	 */
	public function llms_source(): ?array;

	/**
	 * Whether the plugin generated this physical llms.txt (so file + plugin count as one source).
	 *
	 * @param string $path Absolute path of an existing llms.txt.
	 */
	public function owns_llms_file( string $path ): bool;

	/**
	 * The plugin's XML sitemap, or null if it provides none.
	 *
	 * @return array{path: string, includes_products: bool}|null `path` relative to home, e.g. "/sitemap_index.xml".
	 */
	public function sitemap(): ?array;

	/**
	 * The robots.txt lines the plugin adds on the front end that an admin-side in-process
	 * `robots_txt` call would miss (spec §6.2), or null.
	 *
	 * @return array{mode: string, content: string, marker: string, strip?: string}|null mode "append"
	 *         or "replace"; `marker`: a line that is present when the plugin's own callback already
	 *         ran (so the reconstruction is skipped), '' if none; `strip`: optional regex the plugin
	 *         removes from the existing output before appending.
	 */
	public function robots(): ?array;

	/**
	 * Admin path (relative to admin_url()) of the plugin's settings for an area, or ''.
	 *
	 * @param string $area "schema", "sitemap", "llms" or "robots".
	 */
	public function settings_path( string $area ): string;
}
