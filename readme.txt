=== AI Visibility Tracker ===
Contributors: israrminhas
Tags: ai visibility, aeo, chatgpt, llms.txt, ai search
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See if ChatGPT, Claude, Gemini, Perplexity and Grok mention your brand. Track AI traffic, fix AI readiness, find competitors. Free.

== Description ==

More and more customers ask AI assistants instead of searching Google. **Does AI recommend your business, or your competitors?**

AI Visibility Tracker asks the major AI engines the questions your customers ask, using live web search, and shows you:

* **AI visibility score (0-100):** answers that link to your site count fully, answers that only mention you count half.
* **Position:** where you rank among the brands each answer recommends.
* **Visibility by engine and by topic:** see where AI knows you, and where it doesn't.
* **Competitors:** which brands AI recommends instead of you. Big platforms like Reddit or Wikipedia are never counted as competitors.
* **Sources:** which websites AI cites, labeled as yours, a competitor's, social or other.
* **Your pages AI cites:** the posts and pages on your site that AI answers link to.
* **Opportunities:** prompts where AI left you out, who it recommended instead, and where to get featured.
* **Trends:** weekly or monthly automatic checks.

= AI traffic (no Google Analytics needed) =

* **Visitors from AI:** people who click through from ChatGPT, Perplexity, Gemini, Claude, Copilot, Grok and more, with the pages they land on.
* **AI crawlers:** which AI bots visit (GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot and others) and what they read.
* Privacy-friendly: only daily totals are stored. No cookies, IP addresses or personal data.

= AI readiness audit =

* Checks whether robots.txt blocks each AI crawler, and shows the exact rule.
* Checks search visibility, HTTPS, sitemap, llms.txt, and page basics AI relies on: noindex, title, meta description, canonical, schema, headings, and whether content is readable without JavaScript.
* **Automatic llms.txt:** optionally publish an llms.txt that summarizes your site for AI assistants.

= Supported AI engines =

* ChatGPT (OpenAI)
* Claude (Anthropic)
* Gemini (Google)
* Perplexity
* Grok (xAI)

= How it works =

1. Add an API key for one or more engines. You pay the provider directly, usually a few cents per check.
2. Generate prompts from your site's pages, products and posts, or write your own. Your brand name is never put in the prompts, so results are honest.
3. Click **Run now**. Every answer is saved with its sources, so you can read exactly what each AI said.

AI traffic and the readiness audit work without any API key.

Everything is free, with no feature limits and no account with us. All data stays in your WordPress database.

== External services ==

This plugin connects to the AI providers below, **only** for the engines you enable and **only** when you test a key, generate prompts, or run a check (manually, or on the schedule you choose). Nothing is sent before you add a key.

What is sent: your prompts (questions). When you generate prompts, also a short summary of your public site content (site name, tagline, page, product and post titles, homepage text) and your brand settings. Answers are also sent back to the first enabled provider to detect which brands they mention. Your API key is sent only to its own provider.

* **OpenAI API** (ChatGPT): api.openai.com. [Terms](https://openai.com/policies/terms-of-use/), [Privacy policy](https://openai.com/policies/privacy-policy/)
* **Anthropic API** (Claude): api.anthropic.com. [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* **Google Gemini API**: generativelanguage.googleapis.com. [Terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy)
* **Perplexity API**: api.perplexity.ai. [Terms](https://www.perplexity.ai/hub/legal/terms-of-service), [Privacy policy](https://www.perplexity.ai/hub/legal/privacy-policy)
* **xAI API** (Grok): api.x.ai. [Terms](https://x.ai/legal/terms-of-service), [Privacy policy](https://x.ai/legal/privacy-policy)

No data is sent to the plugin author. The readiness audit loads your own robots.txt and pages from your own site; AI traffic is counted on your own site.

== Installation ==

1. Install and activate the plugin.
2. Go to **AI Visibility → Settings**, turn on an engine, paste your API key and click **Test connection**.
3. Check your brand name, other names and website.
4. Go to **Prompts** and click **Generate prompts**.
5. Go to the **Dashboard** and click **Run now**.

== Frequently Asked Questions ==

= How much does it cost? =

The plugin is free. Each engine charges you for API usage on your own account. A check of 10 prompts on one engine typically costs a few cents to a few tens of cents, depending on the model.

= Are the results the same as in the ChatGPT app? =

Not exactly. The plugin uses each provider's API with web search. The consumer apps may use different models, settings and personalization. AI answers also vary between runs, so watch the trend over several checks instead of any single answer.

= Why does a check take a few minutes? =

Each answer involves a live web search. The plugin checks one answer at a time so it works on any host. Keep the page open for the fastest progress; otherwise it continues in the background with WP-Cron.

= Does AI traffic tracking use cookies? =

No. A tiny script checks whether a visitor arrived from an AI assistant and adds one to that day's total for the page. No cookies, IP addresses, user agents or personal data are stored, so no consent banner is needed for it. You can turn it off in Settings.

= Why are some AI crawler visits missing? =

Crawlers don't run JavaScript, so their visits are counted by WordPress itself. If a caching plugin or CDN serves a cached page, WordPress doesn't run and that visit isn't counted.

= Where is my API key stored? =

In your WordPress database, encrypted with a key derived from your site's secret salts. It is never shown in full again and never sent anywhere except to its own provider.

== Screenshots ==

1. Dashboard with visibility score, per-engine visibility, topics and trend.
2. Prompts grouped by topic, with the latest result on each engine.
3. The full AI answer with highlighted brand, detected brands and cited sources.
4. Opportunities: prompts where AI left you out and where to get featured.
5. AI traffic from assistants and crawlers.
6. AI readiness audit with robots.txt checks per AI crawler.
7. AI engine settings.

== Changelog ==

= 0.2.0 =
* Visibility score that rewards links over mentions.
* Topics for prompts, and visibility by topic.
* AI-suggested brand names.
* Opportunities page.
* AI traffic: visitors from AI assistants and AI crawler visits.
* AI readiness audit and automatic llms.txt.
* Your pages cited by AI.
* Platforms like Reddit and Wikipedia are no longer suggested as competitors; subdomains are grouped.

= 0.1.0 =
* First release.
