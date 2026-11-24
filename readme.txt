=== x7velo ===
Contributors: rmmarconi
Tags: woocommerce, ai, llms.txt, structured data, robots.txt
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shoppers ask AI what to buy. See how easily AI assistants can read your store or site, and fix common problems. Free, private, no account.

== Description ==

More and more people ask ChatGPT, Perplexity, Claude or Gemini what to buy and where to buy it. Those assistants can only suggest stores they can read.

x7velo shows you, in plain language, what's in the way, and fixes several common problems with a click. No code, no files to edit, no technical terms to learn.

= Why it matters =

A store can be hard for AI assistants to read without the owner ever noticing: a setting left on by mistake, crawler rules that keep assistants out, product pages missing prices, brands or a returns policy. When an assistant answers "where can I buy a linen shirt under $60?", it works from what it can read. x7velo helps make sure your store isn't left out for reasons you can fix yourself.

= What you get =

* **Your score right away.** A score from 0 to 100 that shows how easily AI assistants can read your store, plus the top 3 things to fix first.
* **A to-do list in plain language.** Every problem explains what we found, why it matters and what to do next, with links to the exact settings screen or products that need attention.
* **One-click fixes.** Publish a store summary for AI, a product list for shopping assistants, clearer product details (returns and delivery times) and crawler rules that let AI search assistants in. Every fix starts off, and you can turn it off again at any time.
* **Works with your SEO plugin.** x7velo sees what Yoast SEO, Rank Math, All in One SEO or SEOPress already handle, counts it as done and stays out of the way, so nothing gets added twice.
* **Private.** Runs entirely on your own site. No account, no sign-up, no tracking, and nothing is sent anywhere.
* **Free.** Every check and every fix.

The score measures how readable your store is for AI assistants, which is the part you can control. What any assistant chooses to recommend is up to that assistant.

= How it works =

1. **Install and open it.** Go to WooCommerce → AI Readiness (or Tools → AI Readiness on sites without WooCommerce). The first check starts on its own and only reads your data.
2. **Read your score.** See what's working, what isn't, and the top 3 things to do first.
3. **Fix what you want.** Turn on a fix with one click, or follow the short steps. Run the check again to see your new score.

= Works on any WordPress site =

No store? x7velo still checks whether AI assistants can find and read your site: search visibility, secure (https) address, readable page links, crawler rules, sitemap and site summary. It can publish a short summary of your main pages and recent posts for AI tools.

Add WooCommerce later and the store checks and fixes appear on their own. Your choices are kept.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New, search for "x7velo", then click Install Now and Activate.
2. Open WooCommerce → AI Readiness. Without WooCommerce, open Tools → AI Readiness.
3. Your first score appears in a moment. Start with the top 3 fixes.

WooCommerce (9.6 or later) is optional. With it, you get the store checks and fixes.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. On any WordPress site, x7velo runs the 6 visibility and crawler checks, and your score is based on those. With WooCommerce active you get all 18 checks, including product information, product data and store trust, plus the product fixes. If you add WooCommerce later, the store checks and fixes appear the next time you open AI Readiness, and your choices are kept.

= What does the score mean? =

0–49: hard for AI assistants to read. 50–79: partly readable. 80–100: easy to read. It only counts checks that apply to your site.

= Does it slow my site? =

Your visitors never wait for it. The audit runs only when you open AI Readiness for the first time or click "Run audit again". On product pages, x7velo does one small check to see which plugin adds product details. The site summary and product list are only built when someone requests them, and only if you turned them on.

= Does it phone home or track me? =

No. x7velo makes no requests to x7velo.com or any other service. No tracking, no analytics, no usage reports. See the Privacy section below.

= Will it conflict with Yoast SEO, Rank Math, All in One SEO or SEOPress? =

It is built to avoid that. x7velo keeps its own version off for anything they already handle, such as product details (structured data), sitemap or llms.txt, and pauses it if they start handling it later. It never changes their settings. We tested the free versions of all four; paid add-ons were not fully tested. If something appears twice, please tell us in the support forum.

= Does it change my robots.txt file? =

It never creates, edits or deletes a robots.txt file. When you turn on the crawler fix, it adds a clearly marked block to the robots.txt that WordPress generates. Your existing rules stay as they are. Turning the fix off removes the block.

If your site has its own robots.txt file on the server, x7velo shows you the exact lines to copy instead. It never overrides your "Discourage search engines" setting.

= What is llms.txt? =

A plain-text summary of your site at yoursite.com/llms.txt, meant for AI tools. It is a proposed convention, not an official standard, and assistants are not required to read it. On a store, x7velo's version lists your store name, main pages, categories and up to 200 of your newest products. On other sites, it lists your main pages and recent posts. It only uses what a logged-out visitor sees, never copies full post text, and leaves out password-protected content and posts, pages or products your SEO plugin hides from search engines. If your SEO plugin can make one, x7velo shows you how to turn it on instead.

= Can I block AI training bots? =

Yes, optionally. "Also block AI training bots" asks bots that collect content to train AI models (such as GPTBot, ClaudeBot and CCBot) not to read your site. It's off by default, doesn't change your score and doesn't block AI search or shopping assistants. Google-Extended has its own checkbox, because blocking it can reduce your visibility in the Gemini app; Google Search is not affected. Robots.txt is a request, not a lock: most well-known bots follow it, some may not.

= What happens if I deactivate or delete it? =

Deactivating stops everything x7velo adds to your site and keeps your settings. Deleting also removes its settings and saved audit results.

= Does it work on multisite? =

Yes, per site: each site has its own score, fixes and settings. Multisite networks were not specifically tested in this first release. If WordPress is installed in a subfolder (like example.com/shop), the crawler checks and fixes don't apply.

= Where is the source code? =

The full source, including the uncompiled JavaScript for the admin screen, is on GitHub: https://github.com/rmmarconi/x7velo-plugin

== Screenshots ==

1. Your score, what it means, and the top 3 things to fix.
2. Each check explains what we found, why it matters and how to fix it, with links to the products that need attention.
3. The Fixes tab: everything x7velo can change for you. All fixes start off.
4. Changes to crawler access always ask you to confirm first.
5. Settings: your returns page, return window and delivery times.
6. After a fix, the check shows "Fixed by x7velo" with a button to turn it off.
7. If your site has its own robots.txt file, x7velo shows the lines to copy instead of changing it.

== Changelog ==

= 1.0.0 =
* First public release.
* Audit with 18 checks and a score from 0 to 100, under WooCommerce → AI Readiness.
* Works on any WordPress site (Tools → AI Readiness); store checks and fixes turn on with WooCommerce.
* Fixes you can turn on: returns policy and delivery times in product details, llms.txt, product feed, and crawler rules for AI search assistants with optional AI training bot blocking.
* Detects Yoast SEO, Rank Math, All in One SEO and SEOPress and avoids duplicating their output.
* Available in English and Brazilian Portuguese.

== Upgrade Notice ==

= 1.0.0 =
First public release.

== Privacy ==

x7velo does not track you or your visitors.

* **No external requests.** It never contacts x7velo.com or any other server, and loads no files from other sites.
* **No telemetry.** No usage data, analytics or error reports.
* **What it stores.** Your x7velo settings, the latest audit result and a few status flags, in your own WordPress database. The audit result holds counts, pass/fail results and up to 5 product IDs per finding. Never customer or order data.
* **What becomes public, only if you turn it on.** The llms.txt file, product feed and crawler rules are public, like the rest of your site. They only contain what your site already shows visitors, such as product names, prices and links.
