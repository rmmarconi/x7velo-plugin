# WordPress.org directory assets

This folder holds the images shown on the plugin's WordPress.org page (icon, banner, screenshots).
They are **not** part of the plugin zip: `plugin/.distignore` excludes `/.wordpress-org`, and
`infra/scripts/plugin-zip.sh` passes `.distignore` to rsync as exclude patterns (checked 2026-11-22).

After approval, these files are copied to the **top-level `assets/` folder of the SVN repository**
(next to `trunk/` and `tags/`, never inside them). See `docs/launch/wporg-submission-checklist.md`.

Rules: [How Your Plugin Assets Work](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
(checked 2026-11-22).

## Required and recommended files

| File | Size | Format | Max file size | Status | Owner |
|---|---|---|---|---|---|
| `icon-128x128.png` | 128 × 128 | png / jpg / gif | 1 MB | **To produce** | ux-designer |
| `icon-256x256.png` | 256 × 256 (high-DPI) | png / jpg / gif | 1 MB | **To produce** | ux-designer |
| `icon.svg` | vector | svg | 1 MB | Optional. If used, the PNGs above are still required as fallback | ux-designer |
| `banner-772x250.png` | 772 × 250 | png / jpg | 4 MB | **To produce** | ux-designer |
| `banner-1544x500.png` | 1544 × 500 (high-DPI) | png / jpg | 4 MB | **To produce**. Only works together with the 772 × 250 banner | ux-designer |
| `screenshot-1.png` … `screenshot-7.png` (+ `-pt_BR` variants) | 1280 × 900 | png / jpg, **lowercase names** | 10 MB each | **Done** 2026-11-23 (demo store, release zip; see below) | qa-engineer + ux-designer |

Notes:
- Names must match exactly. Other names or sizes are ignored by WordPress.org.
- Without an icon, WordPress.org generates one. Icons are not localized.
- Banners can have `-rtl` and locale variants (e.g. `banner-772x250-pt_BR.png`). Screenshots can have
  locale variants (`screenshot-1-pt_BR.png`). We ship `screenshot-N-pt_BR.png` for all seven.
- Banner/icon content: plain, factual, no claims of rankings or results (no "Get recommended by
  ChatGPT"). Avoid other companies' logos (OpenAI, Google, Perplexity, WooCommerce) unless their
  brand guidelines allow it.

## Screenshots: caption mapping

Captions live in `plugin/readme.txt` → `== Screenshots ==`. Line N is the caption for `screenshot-N.png`.
If you change the order here, change the readme too. Each image has a `screenshot-N-pt_BR.png` twin
(same state, pt_BR admin, Brazilian version of the demo store).

All 14 images were shot on 2026-11-23 from the release zip (`infra/scripts/plugin-zip.sh`, m1) on a
throwaway WordPress + WooCommerce 11.3.1 demo store, "Northwind Ceramics" at
`https://northwind-ceramics.test` (fictional; admin "Store Owner" / pt_BR "Dona da Loja"), viewport
1280 × 900, no other plugins, no update counts, no notices, store live. Metadata stripped (optipng
`-strip all`); only IHDR/IDAT/IEND chunks remain.

| # | Caption (from readme.txt) | What the image shows |
|---|---|---|
| 1 | Your score, what it means, and the top 3 things to fix. | Overview, first screenful: 77/100 "Partly readable", Top 3 fixes (llms.txt, product feed, returns policy) |
| 2 | Each check explains what we found, why it matters and how to fix it, with links to the products that need attention. | DATA-04 "Barcodes and brands" opened from a deep link (`&check=DATA-04`): what we found, why it matters, how to fix, 4 product rows with Edit links |
| 3 | The Fixes tab: everything x7velo can change for you. All fixes start off. | Fixes tab, every toggle off (JSON-LD, llms.txt, feed, start of Crawler rules) |
| 4 | Changes to crawler access always ask you to confirm first. | "Let AI shopping assistants read your store?" confirm dialog (Cancel focused) |
| 5 | Settings: your returns page, return window and delivery times. | Settings saved in a valid state: returns page chosen, 30-day window, delivery 2 to 5 days |
| 6 | After a fix, the check shows "Fixed by x7velo" with a button to turn it off. | Crawler access group after turning on llms.txt: CRAWL-03 "Passed · Fixed by x7velo" with Turn off |
| 7 | If your site has its own robots.txt file, x7velo shows the lines to copy instead of changing it. | Crawler rules card with a physical robots.txt (Googlebot/Bingbot only): "Your site has its own robots.txt file", allow lines + training-block lines with Copy buttons |

### Re-shooting

`plugin/tests/e2e/screenshots/` holds the harness (test-only, excluded from the zip by `/tests`):
`run.sh <x7velo.zip>` builds the wp-env site behind a local TLS proxy, seeds the store
(`seed-demo-store.php`, then `localize-pt_BR.php` for the pt_BR pass), drives `shots.mjs` in the
Playwright container, optimises/strips the PNGs into `out/` and destroys the site. Run it on the dev
VM from the repo root, e.g. `infra/scripts/plugin-zip.sh && plugin/tests/e2e/screenshots/run.sh dist/x7velo.zip`.
Open every image before copying it here: no IPs, real names, emails, "staging" or private hosts.
Never use the staging store (VM 143) for listing images.
