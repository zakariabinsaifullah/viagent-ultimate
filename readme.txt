=== Viagent Production ===
Contributors: binsaifullah
Tags: mcp, ai, ai agent, chatgpt, claude
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Claude, ChatGPT, Cursor and other AI agents with a secure MCP server. Guided setup, safe permissions and one-click undo.

== Description ==

**Viagent Production turns your WordPress site into a secure MCP server, so AI agents like Claude, ChatGPT, Cursor, Codex and Gemini can work on your content for you.** Ask your AI to write blog posts, update pages, upload images, fix SEO, moderate comments or handle WooCommerce orders — in plain language, from the AI app you already use.

MCP (Model Context Protocol) is the open standard AI apps use to connect to other software. Viagent Production adds a native MCP server to WordPress — no external relay, no extra service, no coding.

= Connect an AI app in about a minute =

Viagent Production is built for site owners, not just developers:

* **Guided connect wizard** – pick your AI app, choose what it may do, and follow steps written for that exact app.
* **One-click sign-in for Claude.ai and ChatGPT** – paste your connector URL, sign in to WordPress, click Approve. No keys to copy.
* **One-click install for Cursor and VS Code**, and copy-ready setup for every other app.
* **A ready-made first message** for each app, so you know exactly what to say to your AI.
* **Live connection status** – see which apps are connected, and get help finishing a setup that hasn't connected yet.

= Works with your favourite AI apps =

Claude (Claude.ai, Claude Desktop and Claude Code), ChatGPT, Cursor, VS Code with GitHub Copilot, Windsurf, OpenAI Codex, Gemini CLI, Google Antigravity, OpenCode, OpenClaw, Command Code — and any other app that supports MCP over Streamable HTTP, including apps you use with DeepSeek or local models.

= What your AI can do on your site =

* **Any content type** – posts, pages, custom post types and synced patterns: create, edit, schedule, password-protect, make sticky, set author, post format, custom fields, categories and any taxonomy; trash, restore and roll back to earlier revisions.
* **Write in any format** – block markup, HTML or Markdown. HTML and Markdown become real core blocks (headings, lists, quotes, code, images, tables), and every save is checked for broken block markup.
* **Edit long pages precisely** – see a page's block outline and change, insert, move or delete individual blocks (also inside columns and groups), or find and replace text, instead of rewriting the whole page.
* **Work in bulk** – create or update up to 25 items at once, duplicate posts (with page builder data and fields), and filter lists by any taxonomy, custom field or date. Bulk changes undo in one click.
* **Build new content types** (Full control) – create custom post types with custom fields and custom taxonomies (e.g. Recipes with a Cuisine taxonomy and cooking time), ready for the block editor and REST API.
* **Site editor** (Full control, block themes) – edit templates and template parts such as the header and footer, create new templates, and change global styles (colors, fonts, spacing). Every change can be undone.
* **Blocks and patterns** – write proper block-editor content and use your theme's ready-made layouts.
* **Media library** – upload images from a link, write alt text and captions, set featured images.
* **Categories, tags and custom taxonomies.**
* **Comments** – read, reply and moderate.
* **Menus and basic site settings** (title, tagline, homepage, timezone, comments).
* **Plugins and themes** (Full control) – install from WordPress.org or a .zip link, activate, deactivate, update and delete.
* **Theme and plugin code** (Full control) – browse, read and edit files, or build a child theme. PHP is syntax-checked, a change that breaks the site is rolled back automatically, and every edit can be undone.
* **Users** (Full control) – create, edit and delete accounts, change roles and reset passwords. The connected account cannot delete itself or lose its administrator role, and the last administrator is always kept.
* **Site health** – a read-only technical overview (versions, pending updates, HTTPS, cron).

= Complete SEO toolkit =

Built in and works on every site. With **Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework** your AI reads and writes that plugin's own fields; without an SEO plugin, Viagent Production outputs the SEO tags itself (titles, meta descriptions, canonical, robots, Open Graph, Twitter cards, JSON-LD and a clean sitemap).

* **Search titles, meta descriptions, focus keywords, canonical URLs, noindex/nofollow and social sharing text and images** for posts, pages, products, categories and tags – one at a time or up to 50 at once.
* **Page audits** with a 0–100 score: keyword placement and density, headings, content length, readability, image alt text, internal and external links, keyword cannibalization, and a check of what the live page really outputs (title tag, description, canonical, robots, H1, Open Graph, schema, speed).
* **Site-wide SEO audit** – missing or duplicate titles and descriptions, thin content, noindex pages, missing alt text and featured images, pages without internal links, plus site problems such as blocked indexing, plain permalinks or no HTTPS.
* **Internal link suggestions** (outbound and inbound) and a **broken link checker**.
* **Structured data** – add FAQ, HowTo, Product, LocalBusiness or any other JSON-LD to a page.
* **Redirects** (301, 302, 307, 410, 451, with regex) and a **404 monitor** that shows which broken URLs get traffic.
* **robots.txt editor**, **search engine verification codes** (Google, Bing, Yandex, Pinterest, Baidu), homepage and organization details.
* **Request indexing** via IndexNow (Bing, Yandex and others).

= Integrations with popular plugins =

Extra tools appear automatically when these plugins are active:

* **WooCommerce** – store overview, products (prices, stock, categories), orders, coupons and customers.
* **Advanced Custom Fields (ACF)** – see field groups and fill in custom fields.
* **Contact Form 7, WPForms and Gravity Forms** – find forms with their embed code and read submissions.
* **Any plugin that uses the WordPress Abilities API** – switch its abilities on under Tools.

= Ready-made tasks =

AI apps that support MCP prompts (such as Claude Desktop, Claude Code and VS Code) can offer one-click tasks: *Get to know my site*, *Write a blog post*, *Improve a page*, *SEO check-up*, *Optimize a page for a keyword*, *Fix missing image descriptions*, *Plan my content*, *Review new comments*, *Site health check*, *Store report*, *Add a product* and *Summarize form submissions*.

= Safe by default =

* **You choose the access** – Read only, Content editor or Full control, per app.
* **Drafts only** – new connections can write and edit drafts, but not publish or delete, until you allow it.
* **Undo anything** – every action is logged under Activity, and content changes can be undone with one click.
* **Trash, not delete** – deleted content goes to the trash. Permanent deletion is off unless you turn it on.
* **Core stays with you** – even with Full control, AI apps cannot update WordPress core, import or export, run network administration, post unfiltered HTML, or change your site address, admin email, user registration, search engine visibility, permalinks or Viagent Production's own settings. Viagent Production cannot deactivate, update, delete or edit itself.
* **Pause switch** – block every AI app instantly, and resume when you're ready.
* **Revoke any app** at any time; it's disconnected immediately.
* **Standards-based security** – OAuth 2.1 with PKCE for sign-in, and hashed connection keys and tokens.

= Also included =

* **Compact mode** for apps with tool limits (like Cursor): three tools that give access to everything.
* **Multisite support** – every site has its own connections, settings and activity.
* **Connection check** that spots common hosting problems and explains how to fix them.
* **WP-CLI commands** – `wp viagent-production key-create`, `key-list`, `key-revoke`, `activity-list` and `activity-revert`.
* **Developer friendly** – built on the WordPress Abilities API, with filters to add tools, tasks and integrations.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New**, search for **Viagent Production**, then click **Install Now** and **Activate**.
2. You'll be taken to **Tools → Viagent Production**. Choose your AI app.
3. Choose what the app may do. **Write content** with **Drafts only** is a safe start.
4. Follow the steps shown for your app, then send the suggested first message. The app shows as **Connected** as soon as it works.

**Good to know:** Web apps such as Claude.ai and ChatGPT need your site to be online with HTTPS. Apps on your computer (Claude Desktop, Claude Code, Cursor, VS Code…) also work with local development sites.

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is an open standard that lets AI apps use tools from other software. With Viagent Production, your WordPress site becomes one of those tools, so your AI can read and update your site when you ask it to.

= How do I connect Claude to WordPress? =

Go to **Tools → Viagent Production → Connect an app** and pick **Claude.ai** (a custom connector under Customize → Connectors, which works in the Claude web, desktop and mobile apps), **Claude Desktop** or **Claude Code**. Viagent Production shows the exact steps and the first message to send.

= How do I connect ChatGPT to WordPress? =

In ChatGPT on the web, turn on **Developer mode** (Settings → Security and login), then open **Plugins**, click **+** and choose **Create app** (not "Create plugin" or "Upload plugin"), and enter your site's connector URL. Then sign in to WordPress and approve. Viagent Production's connect wizard shows every step. Developer mode needs a Plus, Pro, Business, Enterprise or Education plan.

= Does it work with Cursor, VS Code and other coding tools? =

Yes. Cursor and VS Code have one-click install buttons, and Windsurf, Codex, Gemini CLI, Antigravity, OpenCode and others get copy-ready configuration.

= Is it safe to let AI change my site? =

Every app acts as the WordPress user who connected it and can never do more than that user. Whatever the user's role, an AI connection can never update WordPress itself, import or export, change the site address, admin email, registration or role settings, change the password or email of an administrator (or of the account it acts as), or unpause itself. Only the Full control level may manage plugins, themes and user accounts, and the approval screen says so. Even then, writing or deleting theme and plugin code and installing from a .zip link stay off until you turn on "Allow AI apps to change code" in Settings — code an AI writes runs on your server, so this is your call to make. New connections are limited to drafts, deletions go to the trash, everything is logged, and content changes can be undone with one click. You can pause all AI access at any time.

= What should I say to my AI first? =

The connect wizard gives you a ready-made first message, for example: "Use the site tools to look at my WordPress site and give me a short overview." After that, just ask in your own words — "Write a blog post about our summer sale and save it as a draft."

= Does Viagent Production send my data to an AI company? =

No. Viagent Production never sends your content to an AI service. Your AI app connects to your site, and only after you create a connection or approve a sign-in. What the app does with the content it reads is covered by that app's own privacy policy.

= Does it work on local sites like WordPress Studio or LocalWP? =

Yes, with AI apps on the same computer. The wizard adjusts the setup automatically for local development certificates where needed.

= Can I control exactly which tools the AI can use? =

Yes. Under **Tools → Viagent Production → Tools** you can search all tools and switch any of them on or off, and each connection only sees the tools its access level allows.

= My AI app can't connect. What should I do? =

Open **Tools → Viagent Production → Connect an app**. Apps that are set up but not connected show a **Show me how to finish** button. The **Settings** screen runs a connection check that explains common hosting problems, such as servers that remove the Authorization header.

= Does it work on multisite? =

Yes. Network-activate Viagent Production to give every site its own connections and activity. Each connection only works on the site it was created on.

= What happens to my data if I delete the plugin? =

Nothing is removed unless you turn on **Delete all Viagent Production data** under **Tools → Viagent Production → Settings**. Deactivating the plugin never deletes anything.

= Where is the source code of the admin screens? =

The readable JavaScript and SCSS source is included in the `src` folder. The compiled files in `build` are created with @wordpress/scripts. The full development setup is in the [Viagent GitHub repository](https://github.com/zakariabinsaifullah/viagent). Clone it, then run `npm install && npm run build`.

== External services ==

Viagent Production does not send your site's content to AI providers or to its authors, and it has no tracking. It connects to external services only in these cases:

* **AI app sign-in details** (for example chatgpt.com) – when an AI app signs in with a Client ID Metadata Document, Viagent Production downloads that app's public client description from the web address the app provides (such as `https://chatgpt.com/oauth/…/client.json`) to check where to send you back after you approve. Only a normal download request is sent; no site data. The result is cached for an hour. See the privacy policy of the app you connect, for example [OpenAI's privacy policy](https://openai.com/policies/privacy-policy/).
* **Web addresses provided by your AI app** – when an AI app uploads an image or file from a link, Viagent Production downloads that file from the address the app gave. Only a normal download request is sent to that address. Local and private network addresses are blocked.
* **IndexNow** (api.indexnow.org) – only when your AI uses the *request indexing* tool, the addresses of the pages it names and your site's IndexNow key are sent to IndexNow, which shares them with participating search engines (Bing, Yandex, Seznam, Naver and others). See the [IndexNow documentation](https://www.indexnow.org/documentation) and [Microsoft's privacy statement](https://privacy.microsoft.com/privacystatement).
* **Links in your content** – the *check links* tool sends a normal request to each link in a page to see whether it still works. No site data is sent.

The connect wizard shows setup snippets and links to each AI app's own documentation (for example docs.anthropic.com, opencode.ai or docs.cursor.com). They are plain links: nothing is loaded from those sites unless you click one.

AI apps such as Claude, ChatGPT or Cursor connect to your site; your site does not connect to them. The content they read is handled under the privacy policy of the app you use.

== Changelog ==

= 1.3.0 =
* Content in any format: block markup, HTML or Markdown, converted to core blocks and validated.
* Block-level editing (outline, replace, insert, delete, attributes — including nested blocks) and find and replace inside posts.
* Bulk create and update (with one-step undo), duplicate post, richer list filters (any taxonomy, custom fields, dates, parent) and full content in lists.
* New post fields: custom fields in the same call, author, password, sticky, post format and pingbacks. Plugin-private custom fields (e.g. page builder data) with Full control.
* Create custom post types, taxonomies and custom fields.
* Site editor tools for block themes: templates, template parts and global styles.
* Synced patterns can be created and edited like other content.

= 1.2.0 =
* Complete SEO toolkit for AI agents, built in and compatible with Yoast SEO, Rank Math, All in One SEO, SEOPress and The SEO Framework – or standalone, with Viagent Production printing the SEO tags itself.
* New tools: SEO overview, bulk SEO updates, page SEO analysis with live-page checks, site-wide SEO audit, internal link suggestions, broken link check, category and tag SEO, custom JSON-LD schema, site SEO settings and verification codes, robots.txt editor, redirects, 404 monitor and IndexNow indexing requests.
* New ready-made task: Optimize a page for a keyword.
* AI connections may make the site visible to search engines, but never hide it.

= 1.1.0 =
* Full control: AI connections can install, activate, deactivate, update and delete plugins and themes, read and edit theme and plugin files (with PHP syntax checks, automatic rollback and undo), and create, edit and delete users and their roles.
* "Site manager" is now called "Full control".

= 1.0.2 =
* AI connections can no longer use administrative capabilities (plugins, themes, updates, files, users, import/export, network administration), even when connected through an administrator account.
* AI connections cannot change critical options (site address, admin email, registration, default role, active plugins and theme, search engine visibility), user roles or Viagent Production's own settings, even through other plugins' abilities.
* The approval screen and connect wizard explain that site administration is always blocked for AI apps.
* Removed the multisite network tools, the PHP error log tool and the search engine visibility setting.
* "Full control" is now called "Site manager".
* Trashed items are only listed for users who can edit that content type, and search only returns published content.

= 1.0.1 =
* Renamed the plugin to Viagent.
* Removed tools that installed, activated or deactivated plugins, switched themes, created or edited users, or created network sites. Plugins, themes and users are now read-only.
* Tightened permissions: non-public post types and taxonomies are only listed for users who can edit them.
* The sign-in approval screen now loads its styles as a stylesheet.
* Fixed translations being loaded too early.

= 1.0.0 =
* Initial release.
* Native MCP server (Streamable HTTP) built on the WordPress Abilities API.
* Connect wizard for 14 AI apps, with one-click sign-in (OAuth 2.1 with PKCE, Client ID Metadata Documents and dynamic client registration) for Claude.ai and ChatGPT.
* Tools for posts, pages, media, taxonomies, comments, menus and settings, plus read-only users, plugins and themes.
* Integrations with WooCommerce, Yoast SEO, Rank Math, Advanced Custom Fields, Contact Form 7, WPForms and Gravity Forms.
* Ready-made tasks (MCP prompts), compact mode and multisite support.
* Safety controls: access levels, drafts-only mode, activity log with undo, and a pause switch.

== Upgrade Notice ==

= 1.3.0 =
AI agents can now create any type of content in any format, edit blocks precisely, work in bulk, create custom post types and edit block theme templates and styles.

= 1.2.0 =
Complete SEO toolkit for AI agents, with or without an SEO plugin.

= 1.1.0 =
Full control: AI connections with the Full control level can now manage plugins, themes, their code and users.

= 1.0.2 =
AI connections can no longer perform any site administration, even through an administrator account.

= 1.0.1 =
Renamed to Viagent. Plugin, theme and user management tools were removed; those tasks stay in the WordPress dashboard.

= 1.0.0 =
Initial release of Viagent – connect WordPress to Claude, ChatGPT, Cursor and other AI agents.
