# ATmosphere Plugin Developer Documentation

## Table of Contents
- [Introduction](#introduction)
- [Where to Start](#where-to-start)
- [Public Hooks](#public-hooks)
- [Previewing AT Protocol Records](#previewing-at-protocol-records)
- [Extending Content Formats](#extending-content-formats)
- [Custom Post Type Support](#custom-post-type-support)
- [Publishing Programmatically](#publishing-programmatically)
- [Outgoing Comment Controls](#outgoing-comment-controls)
- [Token Encryption](#token-encryption)
- [Templates and Admin UI](#templates-and-admin-ui)

## Introduction

This documentation is for developers who want to extend, integrate with, or build on the ATmosphere plugin — whether you're writing a companion plugin, adding a content parser for the `site.standard.document` content union, or hooking into the publish / reaction pipeline.

If you're contributing to ATmosphere itself, start with [`AGENTS.md`](../AGENTS.md) for repository conventions.

## Where to Start

- [Development Environment Setup](development-environment.md) — wp-env, prerequisites, troubleshooting.
- [PHP Coding Standards](php-coding-standards.md) — naming, escaping, error handling, performance.
- [Class Structure](php-class-structure.md) — directory layout and architectural patterns.
- [Code Linting](code-linting.md) — PHPCS rules and common fixes.
- [Pull Request Guide](pull-request.md) — branch naming, checklists, commit format.
- [Release Process](release-process.md) — `npm run release`, patch releases, GitHub Release UI.
- [Translations](translations.md) — text domain, GlotPress, translator-friendly strings.
- [Content Formats](content-formats.md) — the AT Protocol content types ATmosphere can produce.
- [`org.wordpress.html` Lexicon](org.wordpress.html.md) — the rendered-HTML content type schema.
- [Integrations Guide](../integrations/README.md) — how third-party plugins register content parsers.

## Public Hooks

ATmosphere exposes a small set of filters and actions for plugins to extend behaviour. The full catalog with signatures lives in [`docs/php-coding-standards.md → Hook Patterns`](php-coding-standards.md#hook-patterns). The most commonly used:

| Hook | Type | Use |
|------|------|-----|
| `atmosphere_content_parser` | filter | Deprecated parser hook; use `Content_Parser\Registry::register()` instead. |
| `atmosphere_document_content` | filter | Last-chance modification of the parsed content object. |
| `atmosphere_document_links` | filter | Add a typed `links` union to `site.standard.document` records. |
| `atmosphere_document_labels` | filter | Add standard self-labels to `site.standard.document` records. |
| `atmosphere_document_contributors` | filter | Add contributor metadata to `site.standard.document` records. |
| `atmosphere_publication_labels` | filter | Add standard self-labels to `site.standard.publication` records. |
| `atmosphere_publication_show_in_discover` | filter | Override `preferences.showInDiscover` (defaults to the site's `blog_public` option) for `site.standard.publication` records. |
| `atmosphere_record_tags` | filter | Add, change, or remove the tags/keywords written into a post's records. Runs before the 8-tag cap and covers both the Bluesky post and the document. |
| `atmosphere_syncable_post_types` | filter | Add or remove post types eligible for cross-posting. |
| `atmosphere_connection_only_mode` | filter | Return `true` to embed ATmosphere purely as a connection layer: auto cross-posting, reaction/reply import, and comment publishing all default off, and the Settings → ATmosphere screen is hidden. |
| `atmosphere_should_auto_publish` | filter | Effective on/off for automatic post cross-posting; runs after the stored setting and connection-only mode, and has the final say. |
| `atmosphere_should_publish_bluesky_post` | filter | Return `false` to publish the `site.standard.document` record only, without a companion `app.bsky.feed.post`, across backfill, auto-publish, and edits. Receives the post being published as a second argument, so the answer can be made per post (by type, meta, or author). Shapes what a publish writes (not whether it runs), so it is a pure filter with no connection-only pass. Forward-only — leaves already-published Bluesky posts in place. |
| `atmosphere_should_publish_comments` | filter | Effective on/off for publishing local comments as Bluesky replies; runs after the stored setting and connection-only mode, and has the final say. Re-enable this lane while in connection-only mode. Not the per-comment `_comment` filter below. |
| `atmosphere_should_publish_comment` | filter | Customise which approved comments from users allowed to publish posts are mirrored as Bluesky replies. |
| `atmosphere_should_sync_reactions` | filter | Effective on/off for importing Bluesky likes and reposts; runs after the stored setting and connection-only mode, and has the final say. |
| `atmosphere_should_sync_replies` | filter | Effective on/off for importing Bluesky replies as comments; runs after the stored setting and connection-only mode, and has the final say. Re-enable this lane while in connection-only mode. Not the per-reply `_reply` filter below. |
| `atmosphere_should_sync_publication` | filter | Effective on/off for writing/refreshing the `site.standard.publication` record. Defaults on, forced off in connection-only mode, and this has the final say — so a connection-layer host doesn't get a public publication record written on connect unless it opts back in. |
| `atmosphere_should_sync_reply` | filter | Customise which inbound Bluesky replies become WordPress comments. |
| `atmosphere_transform_bsky_post` | filter | Mutate the Bluesky post record before write. |
| `atmosphere_transform_document` | filter | Mutate the document record before write. |
| `atmosphere_transform_publication` | filter | Mutate the publication record before write. |
| `atmosphere_atproto_preview_transformers` | filter | Add a transformer to the `?atproto={$type}` preview for posts and the front page. |
| `atmosphere_at_tags` | filter | Add, change, or remove the AT Tags `<meta>` tags mapping a page to its AT Protocol records. |
| `atmosphere_appview_host` | filter | Point Bluesky web links at an alternative AT Protocol appview (host or subpath). |
| `atmosphere_appview_url` | filter | Rewrite the whole assembled appview link, including its route. |
| `atmosphere_publish_post_result` | action | React to a post-publish outcome (success or `WP_Error`). |
| `atmosphere_publish_comment_result` | action | React to a comment-publish outcome. |
| `atmosphere_reaction_synced` | action | React when a Bluesky reaction is stored as a WordPress comment. |
| `atmosphere_connected` | action | React when an AT Protocol account is connected (OAuth callback succeeded). Useful for a host plugin embedding ATmosphere as a connection layer. |
| `atmosphere_disconnected` | action | React when the AT Protocol connection is torn down. |
| `atmosphere_reauth_required` | action | React when the connection first enters a reauth-required state after a permanent OAuth failure. Fires once per transition. |

When adding a new public hook, mark its `@since` tag as `unreleased` — the release script rewrites it (see [Release Process → Marking Unreleased Code](release-process.md#marking-unreleased-code)).

### Mapping pages back to AT Protocol records

Every page that represents a record advertises it twice: through the `<link rel="site.standard.document">` / `<link rel="site.standard.publication">` tags standard.site expects, and through the [AT Tags](https://tangled.org/chrisshank.com/at-tags/) `<meta>` tags Bluesky and Leaflet emit. A dual-published post carries all five:

```html
<link rel="site.standard.document" href="at://did:plc:xxx/site.standard.document/3l…" />
<link rel="site.standard.publication" href="at://did:plc:xxx/site.standard.publication/3l…" />
<meta name="at:canonical" content="at://did:plc:xxx/site.standard.document/3l…" />
<meta name="at:alternate" content="at://did:plc:xxx/site.standard.publication/3l…" />
<meta name="at:alternate" content="at://did:plc:xxx/app.bsky.feed.post/3l…" />
```

`at:canonical` marks the records the page is a rendering of; `at:alternate` marks records it references. On the front page the publication is canonical instead, since that is the URL the publication record's `url` field points at.

Two tags from the proposal are not emitted: `at:author`, because a site has exactly one connected account and it would attribute every post on a multi-author site to whoever connected it, and `at:me`, which has no clear use under the same constraint. Add either — or a namespaced `at:{namespace}:{property}` — with `atmosphere_at_tags`, which receives the assembled tags keyed by name, each holding a list of AT-URIs:

```php
add_filter(
	'atmosphere_at_tags',
	function ( $tags ) {
		$tags['at:me'] = array( 'at://' . \Atmosphere\get_did() );
		return $tags;
	}
);
```

Returning an empty array suppresses the meta tags entirely; the `<link rel>` tags are unaffected by this filter.

### Pointing Bluesky links at another appview

Rendered links to Bluesky (profiles, hashtags, mentions, posts) default to the `bsky.app` web appview. Two filters let you redirect them, depending on how much you need to change.

Both filters pass up to three arguments. As with any WordPress filter, register with `$accepted_args = 3` if your callback needs `$path` and `$context`:

- `$path` — the path being built, e.g. `profile/<did>` or `hashtag/<tag>`.
- `$context` — array with the available parts: `type` (one of `profile`, `post`, `mention`, `hashtag`), `did`, `handle`, `rkey`, `tag`.

#### `atmosphere_appview_host` — swap the host (or subpath)

Use this when the alternative appview mirrors bsky.app's routes (`/profile/...`, `/hashtag/...`) and you only need to change where they live. The first argument is the default host, `'bsky.app'`.

The returned value can be a bare host, a host on a subdomain, or a host with a path prefix, with or without a scheme or trailing slash — it's normalized before use, so an appview hosted on a subpath works cleanly:

```php
// Bare host.
add_filter( 'atmosphere_appview_host', fn() => 'deer.social' );

// Appview living on a subpath: yields https://something.social/atblue/profile/<did>.
add_filter( 'atmosphere_appview_host', fn() => 'something.social/atblue' );

// Route by context: send profiles elsewhere, keep hashtags on bsky.app.
add_filter(
	'atmosphere_appview_host',
	function ( $host, $path, $context ) {
		return 'hashtag' === ( $context['type'] ?? '' ) ? $host : 'deer.social';
	},
	10,
	3
);
```

#### `atmosphere_appview_url` — rewrite the whole link

Use this when the appview's routes differ from bsky.app's — for example `/account/<did>` instead of `/profile/<did>`, or a custom hashtag route. The first argument is the fully assembled URL (after the host filter has run); rebuild it from `$context` and return a complete URL:

```php
// Custom profile route: /account/<did> instead of /profile/<did>.
add_filter(
	'atmosphere_appview_url',
	function ( $url, $path, $context ) {
		if ( 'mention' === ( $context['type'] ?? '' ) || 'profile' === ( $context['type'] ?? '' ) ) {
			return 'https://my.appview/account/' . ( $context['did'] ?? $context['handle'] ?? '' );
		}
		return $url;
	},
	10,
	3
);
```

Of note: links rendered on the fly (facet mentions, hashtags, and the "View on Bluesky" link) pick up the filters on every render, so changing them updates immediately. The author and source links stored on synced reaction comments are resolved once at sync time, so they keep whichever host was in effect when the comment was synced.

### Extending Standard.site metadata

ATmosphere emits the core `site.standard.publication` and `site.standard.document` fields from WordPress data. Optional Standard.site fields that do not have a native WordPress source are extension points.

Document metadata filters:

```php
add_filter(
	'atmosphere_document_links',
	static fn( $links, \WP_Post $post ) => array(
		'$type' => 'example.document.links',
		'items' => array(
			array( 'uri' => 'https://example.com/source' ),
		),
	),
	10,
	2
);

add_filter(
	'atmosphere_document_labels',
	static fn() => array(
		'$type'  => 'com.atproto.label.defs#selfLabels',
		'values' => array(
			array( 'val' => 'adult' ),
		),
	)
);

add_filter(
	'atmosphere_document_contributors',
	static fn( $contributors, \WP_Post $post ) => array(
		array(
			'did'         => 'did:plc:editor123',
			'role'        => 'editor',
			'displayName' => 'Jane Editor',
		),
	),
	10,
	2
);
```

Publication metadata filters:

```php
add_filter(
	'atmosphere_publication_labels',
	static fn() => array(
		'$type'  => 'com.atproto.label.defs#selfLabels',
		'values' => array(
			array( 'val' => 'adult' ),
		),
	)
);

// `showInDiscover` defaults to the site's `blog_public` option; force it
// off (or return null to omit the preference entirely) regardless.
add_filter( 'atmosphere_publication_show_in_discover', '__return_false' );
```

The field-specific filters run before `atmosphere_transform_document` and `atmosphere_transform_publication`, so a final record-level filter can still inspect or override the complete record.

### Tags and keywords

A post's `tags` come from its post tags plus its categories (minus `uncategorized`), de-duplicated and capped at 8 by `Transformer\Base::collect_tags()`. The same list feeds the `app.bsky.feed.post` and the `site.standard.document`.

`atmosphere_record_tags` filters that list before the cap:

```php
// Drop legacy terms a migration left behind.
add_filter(
	'atmosphere_record_tags',
	static fn( array $tags ): array => array_values( array_diff( $tags, array( '1', 'imported' ) ) )
);
```

Prefer this over `atmosphere_transform_document` / `atmosphere_transform_bsky_post` for tag changes. Those run after the cap, so removing a tag there shortens the list rather than making room for the next one.

Non-string entries are dropped from the return value, and the result is de-duplicated and capped again, so a filter cannot write more than 8 tags into a record. The per-tag length limit (64 graphemes for `app.bsky.feed.post`) is not enforced, the same way it is not enforced for an over-long WordPress tag name, so a filter that builds tag names rather than picking from existing terms should keep them short itself.

ATmosphere models one root publication per WordPress site. It verifies that publication at `/.well-known/site.standard.publication` and does not currently implement Standard.site's non-root publication verification path (`/.well-known/site.standard.publication/path/to/publication`). Social Standard.site lexicons such as `site.standard.graph.subscription` and `site.standard.graph.recommend` are also out of scope for the plugin's publishing flow; ATmosphere requests explicit `repo:` scopes only for `app.bsky.feed.post`, `site.standard.document`, and `site.standard.publication`, and intentionally keeps the documented `include:site.standard.authFull` permission set for Standard.site compatibility even though it does not publish or manage social records itself.

## Previewing AT Protocol Records

Append `?atproto` to a URL while logged in to see the JSON records ATmosphere would publish, without writing anything. Post previews require the `edit_post` capability for that specific post (the same gate as the block-editor panel); the front-page publication preview requires `edit_posts`:

| URL | Returns |
|-----|---------|
| `?atproto` on a post | The `site.standard.document` record (default). |
| `?atproto=app.bsky.feed.post` on a post | The Bluesky record(s) — a single post or a thread. |
| `?atproto` / `?atproto=site.standard.publication` on the front page | The site-level `site.standard.publication` record. |
| `?atproto=all` | Every record family for that view, keyed by its lexicon `$type`. |
| `?atproto={unknown}` | A `400` JSON error listing the supported selectors. |

Each selector is the lexicon NSID of a transformer ([`Atmosphere\Transformer\Base`](../includes/transformer/class-base.php)). The preview reuses the same transformers as the publish path, so what you see is what would be written. That includes the document strongRef in a long-form Bluesky record's `associatedRefs` — its CID is computed from the previewed document record, exactly like the publish path computes it. One caveat: on a post that has never been published to the PDS the ref is omitted, because its rkey is only reserved when the post is first published; it appears once the post has been published.

### Adding your own lexicon to the preview

The `atmosphere_atproto_preview_transformers` filter receives the transformers offered for the current view and the queried post (`null` on the front page). Append any `Base` subclass; it becomes available under `?atproto={its-collection-nsid}` and in `?atproto=all` automatically — its `get_collection()` NSID is the selector, and `get_preview_records()` (which defaults to a single `transform()`, overridden when a post fans out into multiple records) supplies the JSON. `get_preview_records()` must be read-only — it runs on a GET request, so it must not upload blobs, write meta, or reserve rkeys.

```php
add_filter(
	'atmosphere_atproto_preview_transformers',
	static function ( array $transformers, ?\WP_Post $post ): array {
		// Only offer this preview on singular posts.
		if ( $post instanceof \WP_Post ) {
			$transformers[] = new My_Plugin\Example_Transformer( $post );
		}

		return $transformers;
	},
	10,
	2
);
```

```php
class Example_Transformer extends \Atmosphere\Transformer\Base {

	public function transform(): array {
		return array(
			'$type'  => 'com.example.document',
			'postId' => $this->object->ID,
		);
	}

	public function get_collection(): string {
		return 'com.example.document'; // The ?atproto selector.
	}

	public function get_rkey(): string {
		return (string) $this->object->ID;
	}
}
```

Entries that are not `Base` instances are ignored, and a filter that returns a non-array falls back to the built-in transformers — so a malformed filter return cannot break the endpoint. A transformer whose `get_collection()` matches a built-in NSID supersedes that built-in for the request, mirroring how `Content_Parser\Registry::register()` lets a registration override a default.

## Extending Content Formats

The `site.standard.document` record's `content` field is a singular open union of typed content objects (see [`docs/content-formats.md`](content-formats.md)). ATmosphere ships built-in parsers for HTML, Markpub, Leaflet, and pckt formats, and integrations can register additional parsers.

To provide a parser:

1. Implement `Atmosphere\Content_Parser\Content_Parser` (defined in `includes/content-parser/interface-content-parser.php`), or extend `Atmosphere\Content_Parser\Parser_Base` for WordPress/block helpers.
2. Register the parser with `Atmosphere\Content_Parser\Registry::register( $parser, $priority )`.
3. Optionally expose `applies_to( \WP_Post $post ): bool` so the registry can skip posts the parser cannot represent.

The deprecated `atmosphere_content_parser` filter remains for existing integrations. A returned parser still wins over the registry, and `null` still suppresses the `content` field, but using the filter emits a deprecation notice.

The **Content format** setting is a preference, not an absolute guarantee for every post. When the selected parser does not apply or cannot safely represent a post, the registry falls back to the next applicable parser, normally rendered HTML.

A complete worked example (with `class-load.php` registration) is in [`integrations/README.md`](../integrations/README.md).

## Custom Post Type Support

ATmosphere only cross-posts post types that opt in. Two ways to add one:

### Per-site option

```php
\update_option( 'atmosphere_support_post_types', array( 'post', 'product' ) );
```

### Native theme/plugin support

```php
\add_post_type_support( 'product', 'atmosphere' );
```

Opting a post type into sharing also force-enables its `custom-fields` support. WordPress only saves registered meta over the REST API when the type supports custom fields, so without this the per-post Bluesky settings would be dropped silently by the editor. Side effects to know about: the Custom Fields panel becomes available in that type's editor preferences, and any other plugin's `show_in_rest` meta on the same type, previously inert for the same reason, becomes readable and writable over REST as well.

### Filter override

```php
\add_filter(
    'atmosphere_syncable_post_types',
    static function ( array $types ): array {
        $types[] = 'event';
        return $types;
    }
);
```

The plugin merges all three sources, dedupes, and sanitises.

## Publishing Programmatically

Publishing UIs, syndication managers, and other companion plugins can drive
cross-posting per post instead of relying on the automatic save-flow.

### Per-post controls

Two registered post metas (both `show_in_rest`, writable with `edit_post`)
control what a cross-post looks like before it happens:

| Meta key | Constant | Effect |
|----------|----------|--------|
| `atmosphere_disabled` | `ATMOSPHERE_META_DISABLED` | Sharing is opt-out: `'1'` excludes the post from cross-posting. |
| `atmosphere_custom_text` | `ATMOSPHERE_META_CUSTOM_TEXT` | Replaces the derived Bluesky post text for this post. |

While the automatic save-flow is active, changing either meta schedules a
reconcile that publishes, updates, or removes the remote records to match.
Integrations that disable `atmosphere_auto_publish` (below) must run that
reconcile themselves by calling `\Atmosphere\Publisher::update_post()`
after changing the metas — setting `atmosphere_disabled` alone does not
remove already-published records.

### Taking over the publish flow

The automatic save-flow is gated by the `atmosphere_auto_publish` option
(`'1'` by default). An integration that wants to decide *when* a post is
cross-posted can disable it and call the publisher directly:

```php
\update_option( 'atmosphere_auto_publish', '0' );

$result = \Atmosphere\Publisher::publish_post( $post );

if ( \is_wp_error( $result ) ) {
    // Post was ineligible or the write failed.
}
```

`publish_post()` enforces `\Atmosphere\is_post_publishable()` — the post
must be published, not password-protected, of a [supported post
type](#custom-post-type-support), and not opted out via
`atmosphere_disabled`. It fires
[`atmosphere_publish_post_result`](#public-hooks) once with the final
outcome, and returns the `applyWrites` response(s) or a `WP_Error`.
`\Atmosphere\Publisher::update_post()` and
`\Atmosphere\Publisher::delete_post()` complete the lifecycle;
`update_post()` doubles as the reconcile — when the post is no longer
publishable (trashed, unpublished, or opted out via `atmosphere_disabled`)
it removes the remote records.

### Reading back the published record

After a successful publish the post carries the created record's
references:

| Meta key | Constant | Value |
|----------|----------|-------|
| `_atmosphere_bsky_uri` | `\Atmosphere\Transformer\Post::META_URI` | The `at://` URI of the Bluesky post. |
| `_atmosphere_bsky_tid` | `\Atmosphere\Transformer\Post::META_TID` | The record key (TID). |

```php
$at_uri = \get_post_meta( $post_id, \Atmosphere\Transformer\Post::META_URI, true );
```

Replies, likes, and reposts synced back from Bluesky arrive as native
WordPress comments (comment types `comment`, `like`, and `repost`, all
with `protocol` comment meta `atproto`). Replies carry a link to the
reply's Bluesky page in `source_url`; likes and reposts have no Bluesky
landing page, so their `source_url` is intentionally empty and
`comment_author_url` (the author's profile) is the outbound link.
`comment_author` holds the remote display name HTML-encoded, the same way
core stores names that arrive through the comment form: safe to print as
is, decode it with `html_entity_decode()` if you need the plain-text name.
Integrations can react to each via
[`atmosphere_reaction_synced`](#public-hooks).

### Document-only publishing

By default every cross-post writes an `app.bsky.feed.post` (Bluesky) record
alongside the `site.standard.document` record. Returning `false` from
[`atmosphere_should_publish_bluesky_post`](#public-hooks) drops the Bluesky
companion and publishes the document alone — useful for running a site as a
standard.site publication that never appears on Bluesky. It applies uniformly
across auto-publish, the `wp atmosphere backfill` command, and edit-updates.

The filter shapes *what* a publish writes, not *whether* the site publishes,
so — unlike the connection-only lane switches — it has no connection-only pass
and stays a pure filter. A host embedded as a connection layer can still choose
document-only output when it runs a manual backfill.

**Decide per post, not just site-wide.** The filter receives the post being
published as a second argument, so a callback can route individual posts.
A site-wide `__return_false` still works unchanged — it simply ignores the post.
The post is always a real `WP_Post`, so no `null` check is needed.

Route a whole post type document-only (e.g. short-form status updates or an
activity-log CPT) while long-form posts keep their Bluesky companion:

```php
add_filter(
	'atmosphere_should_publish_bluesky_post',
	function ( $enabled, $post ) {
		return 'status' === $post->post_type ? false : $enabled;
	},
	10,
	2
);
```

Or honor a per-post author choice stored in post meta ("publish this one
quietly"):

```php
add_filter(
	'atmosphere_should_publish_bluesky_post',
	function ( $enabled, $post ) {
		return get_post_meta( $post->ID, 'skip_bluesky', true ) ? false : $enabled;
	},
	10,
	2
);
```

**This is a one-way choice, on purpose.** A post first published as a
document-only record does not retroactively gain a Bluesky companion if you
later stop returning `false`:

- **Backfill skips it.** A post counts as synced once its document record
  exists (`Document::META_URI` is set), so `wp atmosphere backfill` never
  revisits it.
- **Edits keep it document-only.** An update finds no reserved Bluesky record
  (`Post::META_TID` is never written on the document-only path), treats the
  post as unsynced, fires the `atmosphere_update_skipped_unsynced_post` action,
  and leaves it untouched.

The mirror of this holds in the other direction too: enabling document-only
leaves Bluesky posts published *before* it in place. The filter governs new
writes and never rewrites history — document-only is document-only. Adding a
Bluesky post to an already-published document is a separate job that neither
backfill nor a routine edit performs; a host that genuinely needs it can
subscribe to `atmosphere_update_skipped_unsynced_post` and call
`\Atmosphere\Publisher::publish_post()` itself.

## Outgoing Comment Controls

ATmosphere publishes eligible WordPress comments as Bluesky replies.
Administrators can turn these writes off under
**Settings → ATmosphere → Reactions** ("Outgoing replies"). The underlying
`atmosphere_publish_comments` option defaults to enabled so existing sites
keep their current behavior.

Host plugins can enforce the boundary with a behavior filter that runs
*after* the stored preference and has the final say:

```php
add_filter( 'atmosphere_should_publish_comments', '__return_false' );
```

The override is on effective behavior, not the option — the saved
preference stays untouched (and the settings form keeps editing it), so
removing the filter restores whatever the site had configured. Because the
filter runs last, it can also force the lane back *on* while the option is
off.

While comment publishing is disabled, ATmosphere does not create, update,
or delete Bluesky reply records for WordPress comments, including work that
was already queued in WP-Cron. Replies that were previously published
remain unchanged. Post and standard.site document publishing continues
normally, as does inbound syncing of Bluesky replies, likes, and reposts.

Direct calls to the `Publisher` comment methods return a WP_Error with the
`atmosphere_comment_publishing_disabled` code while the control is off. Use
`\Atmosphere\is_comment_publishing_enabled()` when an integration needs to inspect
the effective state.

## Token Encryption

OAuth tokens are encrypted at rest with a key derived from the site's `AUTH_KEY` and `AUTH_SALT`. That keeps the key out of the database, but it also means the stored tokens become unreadable when the salts change — after a migration, a regenerated `wp-config.php`, or a security plugin that rotates salts on a schedule. ATmosphere detects that case, flags the connection, and asks the user to reconnect.

Sites that rotate their salts deliberately can pin a dedicated key instead, which takes precedence over the salts:

```php
define( 'ATMOSPHERE_ENCRYPTION_KEY', 'a long random secret that never changes' );
```

Define it in `wp-config.php` **before** connecting (or reconnect afterwards — changing key material always orphans previously stored tokens). Treat it like a salt: long, random, and never committed to version control.

## Templates and Admin UI

ATmosphere's admin screens render from `templates/`. The settings page is rendered from a single template; the editor sidebar panel is a React surface registered through `class-admin.php`. There is currently no public template-override mechanism — file an issue if you have a use case that requires one.

## Reporting Issues

Bugs and feature requests: [GitHub Issues](https://github.com/Automattic/wordpress-atmosphere/issues).

Security issues: see the project's security disclosure policy in [`README.md`](../README.md#security).
