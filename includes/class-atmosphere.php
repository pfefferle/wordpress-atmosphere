<?php
/**
 * Main plugin initialization and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Content_Parser\Html;
use Atmosphere\Content_Parser\Leaflet;
use Atmosphere\Content_Parser\Markpub;
use Atmosphere\Content_Parser\Pckt;
use Atmosphere\Content_Parser\Registry;
use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Preview;
use Atmosphere\Transformer\Publication;
use Atmosphere\Transformer\Threadgate;
use Atmosphere\Integrations\Load;
use Atmosphere\Rest\Admin\Connection_Controller;
use Atmosphere\Rest\Admin\Pre_Publish_Controller;
use Atmosphere\Rest\Client_Metadata_Controller;
use Atmosphere\Rest\Reactions_Controller;
use Atmosphere\WP_Admin\Admin;
use Atmosphere\WP_Admin\Health_Check;
use Atmosphere\WP_Admin\Post_List;
use Atmosphere\WP_Admin\Settings_Fields;

/**
 * Atmosphere main class.
 */
class Atmosphere {

	/**
	 * Allowed values for the long-form composition strategy filter and
	 * the matching `atmosphere_long_form_composition` option.
	 */
	public const LONG_FORM_STRATEGIES = array( 'link-card', 'truncate-link', 'teaser-thread' );

	/**
	 * Comment meta key tracking how many times publish has been
	 * deferred waiting for a parent comment to publish first.
	 *
	 * @var string
	 */
	private const META_PUBLISH_ATTEMPTS = '_atmosphere_publish_attempts';

	/**
	 * Post meta key tracking how many delayed retries a failed
	 * publish/update cron worker has scheduled for the post.
	 *
	 * Deleted on success, on a permanent (non-retryable) failure, and
	 * when the retry ladder is exhausted, so the next fresh save always
	 * starts with a full retry budget.
	 *
	 * @var string
	 */
	private const META_PUBLISH_RETRIES = '_atmosphere_publish_retries';

	/**
	 * Post meta key holding the most recent publish/update failure.
	 *
	 * Written by the cron workers when an attempt fails, cleared on the
	 * next success. Shape: `code`, `message` (sanitized + truncated —
	 * PDS-supplied strings can carry hostile bytes), `retrying` (whether
	 * the backoff ladder scheduled another attempt), `time`. Exposed to
	 * the block editor via the read-only `atmosphere_publish_error`
	 * REST field so authors can see that a share failed instead of the
	 * failure vanishing into a WP_DEBUG-gated log line.
	 *
	 * @var string
	 */
	private const META_LAST_PUBLISH_ERROR = '_atmosphere_last_publish_error';

	/**
	 * Backoff ladder for transient publish/update failures, in seconds.
	 *
	 * `wp_schedule_single_event()` is one-shot: without a re-queue, a
	 * single PDS 5xx / rate limit / network blip permanently drops the
	 * post from Bluesky with no operator-visible trace. One entry per
	 * retry — three attempts spread over ~21 minutes rides out PDS
	 * restarts and rate-limit windows without hammering a struggling
	 * server.
	 *
	 * @var int[]
	 */
	private const PUBLISH_RETRY_DELAYS = array(
		MINUTE_IN_SECONDS,
		5 * MINUTE_IN_SECONDS,
		15 * MINUTE_IN_SECONDS,
	);

	/**
	 * Post meta marker set when remote records were removed because a
	 * previously public post left public visibility.
	 *
	 * @var string
	 */
	private const META_VISIBILITY_CLEANUP = '_atmosphere_visibility_cleanup';

	/**
	 * Option marking that the historical visibility cleanup migration ran.
	 *
	 * @var string
	 */
	private const OPTION_VISIBILITY_CLEANUP_MIGRATED = 'atmosphere_visibility_cleanup_migrated';

	/**
	 * Option storing the highest post ID processed by the historical
	 * visibility-cleanup migration. Used for keyset (ID > last_seen)
	 * pagination so concurrent deletes don't shift the cursor.
	 *
	 * @var string
	 */
	private const OPTION_VISIBILITY_CLEANUP_LAST_ID = 'atmosphere_visibility_cleanup_last_id';

	/**
	 * Post IDs currently being handled by on_status_change().
	 *
	 * @var array<int,bool>
	 */
	private static array $publishing_post_ids = array();

	/**
	 * Per-request cache of what the `wp_head` emitters resolve, keyed by
	 * resolver, queried object ID, and connected DID.
	 *
	 * The three emitters ask overlapping questions — which post may name
	 * records, which document URI, which publication URI — so without
	 * this the same work runs up to three times per pageview. The part
	 * that costs is `is_post_publishable()`, which walks every
	 * registered post type and fires the `atmosphere_syncable_post_types`
	 * filter on each call, so a site hooking that filter pays for it
	 * repeatedly. `wp_head` fires once per request, which is what makes
	 * a plain memo sufficient here.
	 *
	 * @var array<string,mixed>
	 */
	private static array $head_record_cache = array();

	/**
	 * Maximum re-schedule hops for a child comment waiting on a
	 * not-yet-published parent. After this many deferrals the child
	 * is skipped if the parent still lacks a threadable strongRef so a
	 * stuck parent does not block it forever — see
	 * {@see Atmosphere::parent_has_bsky_representation()} for the
	 * skip rule.
	 *
	 * @var int
	 */
	private const PARENT_DEFER_MAX_ATTEMPTS = 3;

	/**
	 * Seconds between parent-pending re-schedule hops.
	 *
	 * @var int
	 */
	private const PARENT_DEFER_DELAY_SECONDS = 30;

	/**
	 * Wire up all hooks.
	 */
	public function init(): void {
		/*
		 * Admin self-registers on init. This runs before admin_init,
		 * rest_api_init, and wp_ajax_* so sub-hooks those callbacks add
		 * are wired up in time, and it also ensures REST endpoints are
		 * available on non-admin requests.
		 */
		\add_action( 'init', array( Admin::class, 'register' ), 5 );
		\add_action( 'init', array( Icons::class, 'register' ) );
		\add_action( 'admin_init', array( Post_List::class, 'register' ) );

		/*
		 * Settings API option registration (`Options::init()`) and
		 * Settings page UI assembly (`Settings_Fields::init()`) live in
		 * their own classes, matching the layout the ActivityPub plugin
		 * uses. Wired on `init` (priority 5) the same way as `Admin`
		 * above, so each can self-wire the request-specific hooks it needs.
		 */
		\add_action( 'init', array( Options::class, 'init' ), 5 );
		\add_action( 'init', array( Settings_Fields::class, 'init' ), 5 );

		/*
		 * Site Health status test + debug information. Registered
		 * directly on the pull filters (no context gate, no `init`
		 * indirection): they only fire on Site Health surfaces — the
		 * screen, the weekly scheduled check, WP-CLI — so the class is
		 * autoloaded only there and every other request just stores three
		 * callables. That is also why the ajax action is spelled out
		 * instead of read from `Health_Check::REACHABILITY_ACTION`: a
		 * constant fetch autoloads the class, `::class` does not. The
		 * registration test pins the literal to the constant.
		 */
		\add_filter( 'site_status_tests', array( Health_Check::class, 'add_tests' ) );
		\add_action( 'wp_ajax_health-check-atmosphere-reachability', array( Health_Check::class, 'ajax_client_metadata' ) );
		\add_filter( 'debug_information', array( Health_Check::class, 'debug_information' ) );

		/*
		 * Display-side @handle.tld mention auto-linking. Self-registers on
		 * init so the_content (priority 100) is wired for both front-end
		 * rendering and the site.standard.document content parsers.
		 */
		\add_action( 'init', array( Mention::class, 'init' ), 5 );

		\add_action( 'init', array( Connectors::class, 'init' ), 5 );

		/*
		 * Seed the long-form composition strategy from the user's
		 * setting. Priority 1 so any downstream filter at the default
		 * priority can still override it per post.
		 */
		\add_filter( 'atmosphere_long_form_composition', array( self::class, 'seed_long_form_composition' ), 1 );

		// Register every REST controller in one place.
		\add_action( 'rest_api_init', array( $this, 'register_rest_controllers' ) );

		/*
		 * Block-editor pre-publish panel. REST data and editor asset are
		 * deliberately separate concerns: the controller serves the
		 * projection on the admin `atmosphere/1.0` namespace, Block_Editor
		 * only enqueues the script.
		 */
		Block_Editor::register();

		// Front-end blocks (e.g. the Bluesky reactions facepile). Self-gates
		// off when the ActivityPub plugin is active.
		Blocks::register();

		// Per-post "share to Bluesky" toggle + custom-text meta (REST-exposed for the editor panel).
		\add_action( 'init', array( $this, 'register_share_meta' ) );

		/*
		 * Reconcile when the share toggle or custom text changes.
		 * `transition_post_status` alone is fragile here: the editor writes the
		 * meta *after* the post update fires the transition, and a meta-only
		 * save fires no transition at all — so react to the committed meta
		 * write directly.
		 */
		\add_action( 'added_post_meta', array( $this, 'on_share_meta_changed' ), 10, 3 );
		\add_action( 'updated_post_meta', array( $this, 'on_share_meta_changed' ), 10, 3 );
		\add_action( 'deleted_post_meta', array( $this, 'on_share_meta_changed' ), 10, 3 );

		// Read-only REST field exposing the published post's Bluesky URL.
		\add_action( 'rest_api_init', array( $this, 'register_share_status_field' ) );

		/*
		 * Frontend verification headers. The emitters memoize what they
		 * resolve, so the head render opens by dropping anything left
		 * over — see `flush_head_record_cache()` for why that matters
		 * outside a request-per-process runtime.
		 */
		\add_action( 'wp_head', array( self::class, 'flush_head_record_cache' ), 0 );
		\add_action( 'wp_head', array( $this, 'output_document_link' ) );
		\add_action( 'wp_head', array( $this, 'output_publication_link' ) );
		\add_action( 'wp_head', array( $this, 'output_at_tags' ) );

		// Well-known endpoints and front-end query vars.
		\add_action( 'init', array( $this, 'register_wellknown_rewrite' ) );
		\add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		\add_action( 'template_redirect', array( $this, 'serve_wellknown_atproto_did' ), 0 );
		\add_action( 'template_redirect', array( $this, 'serve_wellknown_publication' ), 0 );

		// Register the built-in content parsers.
		self::register_default_content_parsers();

		// Plugin integrations (may register additional parsers).
		Load::init();

		// JSON preview for AT Protocol records.
		\add_action( 'template_redirect', array( Preview::class, 'render' ) );

		// Post lifecycle hooks.
		\add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );

		/*
		 * Historical visibility-cleanup migration is queued (not run)
		 * on admin_init by a `manage_options`-capable user, and the
		 * actual batched walk runs in a single-event cron handler.
		 * Splitting the trigger from the work keeps subscribers from
		 * driving the full `posts_per_page => -1` walk on their first
		 * /wp-admin/* hit, and the cron context decouples the long
		 * walk from any specific admin pageload's timeout budget.
		 */
		\add_action( 'admin_init', array( $this, 'maybe_queue_historical_visibility_cleanup' ) );
		\add_action( 'atmosphere_run_historical_visibility_cleanup', array( $this, 'run_historical_visibility_cleanup' ) );

		// Catch permanent deletes (bypassing trash or emptying trash).
		\add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );

		// Comment lifecycle hooks.
		\add_action( 'transition_comment_status', array( $this, 'on_comment_status_change' ), 10, 3 );
		\add_action( 'comment_post', array( $this, 'on_comment_insert' ), 10, 2 );
		\add_action( 'edit_comment', array( $this, 'on_comment_edit' ) );
		\add_action( 'delete_comment', array( $this, 'on_comment_before_delete' ) );

		/*
		 * Auto-sync the publication record whenever something the record
		 * derives from changes. The record bakes in WordPress's site
		 * identity (name, description, icon, home URL) and the active
		 * theme's primary colours; keeping it in lockstep with those
		 * sources avoids a stale publication on the PDS until the next
		 * unrelated event happens to re-sync.
		 *
		 * Triggers cover both surfaces a site administrator can edit
		 * theme colours from: classic-theme Customizer saves
		 * (`customize_save_after`) and block-theme Site Editor saves
		 * (the `wp_global_styles` post update).
		 *
		 * Each option gets both `add_option_*` and `update_option_*`: an
		 * option with no row yet is written by `add_option()`, where
		 * `update_option_*` never fires, which is the case a plugin
		 * option hits the first time it is saved. Registering both for
		 * every option keeps the list uniform, and the add hook is
		 * simply never reached for the core options, which always exist.
		 */
		foreach (
			\array_merge(
				array( 'blogname', 'blogdescription', 'site_icon', 'home', 'siteurl' ),
				\array_values( Publication::get_theme_color_options() )
			) as $publication_option
		) {
			\add_action( 'add_option_' . $publication_option, array( $this, 'schedule_publication_sync' ) );
			\add_action( 'update_option_' . $publication_option, array( $this, 'schedule_publication_sync' ) );
		}
		\add_action( 'switch_theme', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'save_post_wp_global_styles', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'customize_save_after', array( $this, 'schedule_publication_sync' ) );

		// Token refresh cron.
		\add_action( 'atmosphere_refresh_token', array( $this, 'cron_refresh_token' ) );

		/*
		 * Migrate sites that scheduled this event on a previous version
		 * (twicedaily) to the hourly cadence. Bluesky access tokens live
		 * 60 minutes and the auth server's DPoP nonces only 3 minutes,
		 * so a 12-hour interval left the refresh worker far too sparse
		 * to recover from a single failed run before the session aged
		 * past the refresh-token replay window.
		 */
		$schedule = \wp_get_schedule( 'atmosphere_refresh_token' );
		if ( false !== $schedule && 'hourly' !== $schedule ) {
			\wp_clear_scheduled_hook( 'atmosphere_refresh_token' );
		}

		if ( ! \wp_next_scheduled( 'atmosphere_refresh_token' ) && is_connected() ) {
			\wp_schedule_event( \time(), 'hourly', 'atmosphere_refresh_token' );
		}

		/*
		 * Async refresh-token revocation, scheduled by
		 * `Client::disconnect()`. The callback is registered for every
		 * request so the worker fires correctly when WP-Cron picks up
		 * the queued event even though the local connection is gone.
		 */
		\add_action(
			'atmosphere_revoke_refresh_token',
			array( Client::class, 'revoke_refresh_token' ),
			10,
			4
		);

		// Async action hooks (called by WP-Cron).
		self::register_async_hooks();

		// Reaction sync cron + display hooks.
		\add_action( 'atmosphere_sync_reactions', array( Reaction_Sync::class, 'sync' ) );
		\add_action( 'atmosphere_backfill_replies', array( Reaction_Sync::class, 'backfill_scheduled_replies' ) );
		Reaction_Sync::register();

		/*
		 * Reconcile the reaction-sync cron events on `init`, not here on
		 * `plugins_loaded`: a host that re-enables a lane via the
		 * `atmosphere_should_sync_*` filters from its own plugins_loaded/init
		 * callback must have that filter attached before the gate is read, or
		 * the event would never be scheduled even though the lane runs by
		 * cron-dispatch time. Priority 20 leaves room for host filters on the
		 * default init priority.
		 */
		\add_action( 'init', array( self::class, 'maybe_schedule_reaction_crons' ), 20 );
	}

	/**
	 * Schedule (or unschedule) the reaction-sync cron events to match the site's
	 * effective settings.
	 *
	 * Both events are reconciled every request: scheduled when wanted and absent,
	 * cleared when unwanted but lingering — so a site that enters connection-only
	 * mode after connecting doesn't keep an hourly no-op cron running forever.
	 */
	public static function maybe_schedule_reaction_crons(): void {
		if ( ! is_connected() ) {
			return;
		}

		// Hourly like/repost + reply poll. Wanted unless connection-only mode
		// has both sync lanes off (a re-enabling filter keeps it on).
		self::reconcile_cron_event(
			'atmosphere_sync_reactions',
			'hourly',
			\time(),
			! is_connection_only_mode() || is_reaction_sync_enabled() || is_reply_sync_enabled()
		);

		/*
		 * Daily reply-backfill audit — reply-specific, so gate on reply sync.
		 * The half-hour offset keeps its due timestamps from ever coinciding
		 * with the hourly sync's; a whole-hour offset would collide every day,
		 * and both compete for the same reaction-sync lock.
		 */
		self::reconcile_cron_event(
			'atmosphere_backfill_replies',
			'daily',
			\time() + \HOUR_IN_SECONDS / 2,
			! is_connection_only_mode() || is_reply_sync_enabled()
		);
	}

	/**
	 * Bring one recurring cron event in line with whether it's currently wanted.
	 *
	 * @param string $hook       Cron hook name.
	 * @param string $recurrence Schedule recurrence (e.g. `hourly`, `daily`).
	 * @param int    $first_run  Timestamp of the first run when (re)scheduling.
	 * @param bool   $wanted     Whether the event should be scheduled right now.
	 */
	private static function reconcile_cron_event( string $hook, string $recurrence, int $first_run, bool $wanted ): void {
		$scheduled = (bool) \wp_next_scheduled( $hook );

		if ( $wanted && ! $scheduled ) {
			\wp_schedule_event( $first_run, $recurrence, $hook );
		} elseif ( ! $wanted && $scheduled ) {
			\wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Output <link rel="site.standard.document"> on singular posts.
	 *
	 * This confirms the bidirectional link between the web page and
	 * its AT Protocol document record, as required by standard.site.
	 *
	 * Gated on `has_identity()` rather than `is_connected()` so the
	 * verification link survives a temporary OAuth refresh failure: the
	 * DID it is checked against is stable across session expiry and
	 * `needs_reauth` states.
	 *
	 * Also gated on the document record's own `Document::META_URI` so the
	 * link is emitted only for posts the Publisher actually wrote a
	 * `site.standard.document` for. Keying on this (rather than the Bluesky
	 * post's `Post::META_URI`) is what lets document-only sites — which never
	 * write a companion `app.bsky.feed.post` — still advertise their document
	 * records. Without the check, a disconnected site (identity preserved, no
	 * live session) would advertise document AT-URIs for every published WP
	 * post and lazy-mint `META_TID` rows for posts that have no corresponding
	 * record on the PDS — federation/discovery consumers would 404 each one.
	 * Posts published before a disconnect already carry `Document::META_URI`
	 * and remain correctly advertised; new posts created during a disconnect
	 * stay silent until reconnect + publish lands a real record.
	 */
	public function output_document_link(): void {
		$uri = self::current_document_uri();

		if ( '' === $uri ) {
			return;
		}

		\printf(
			'<link rel="site.standard.document" href="%s" />' . "\n",
			\esc_attr( $uri )
		);
	}

	/**
	 * The `site.standard.document` AT-URI advertised by the page being
	 * rendered, or an empty string when the page advertises none.
	 *
	 * Shared by the `<link rel="site.standard.document">` tag and the
	 * `at:canonical` meta tag so the gating below lives in exactly one
	 * place. See {@see Atmosphere::output_document_link()} for why the
	 * gates are what they are.
	 *
	 * The stored URI is returned as-is rather than rebuilt from
	 * `get_did()` plus the post's TID. Rebuilding looks equivalent and
	 * is not: after a disconnect and reconnect to a different account,
	 * `get_did()` is the new DID while the TID still belongs to the old
	 * one, so every already-published post would advertise
	 * `at://NEW_DID/site.standard.document/OLD_TID` — a record that
	 * exists nowhere. The same mismatch would arise from a row whose
	 * `META_TID` was lost while `META_URI` survived, where the lazy mint
	 * would issue a fresh TID unrelated to the published record.
	 *
	 * Not calling `Document::get_rkey()` here also takes the front end
	 * out of the write path entirely. That call refreshes
	 * `Document::META_DID` to the current DID, and this was its only
	 * render-time caller — every other one is in the Publisher at
	 * publish time — so a pageview can no longer move a post's recorded
	 * origin DID out from under the cleanup guards (see #217).
	 *
	 * @return string AT-URI, or '' when the page has no document record
	 *                belonging to the connected account.
	 */
	private static function current_document_uri(): string {
		return self::head_memo(
			'document',
			static function () {
				$post = self::current_publishable_post();

				if ( null === $post ) {
					return '';
				}

				$uri = \get_post_meta( $post->ID, Document::META_URI, true );

				if ( ! \is_string( $uri ) || '' === $uri ) {
					return '';
				}

				return self::verified_record_uri( $uri, 'site.standard.document' );
			}
		);
	}

	/**
	 * A stored AT-URI, or an empty string when it is not a well-formed
	 * URI for `$collection` in the connected account's repo.
	 *
	 * Records are advertised from the URI the Publisher stored, which is
	 * the only record of the repo a write actually landed in. Every
	 * component is checked rather than just the DID: `parse_at_uri()`
	 * asserts only the `at://` prefix and a three-segment shape, so a
	 * corrupted value holding another of our records would otherwise be
	 * advertised as the wrong kind of record.
	 *
	 * @param string $uri        Stored AT-URI.
	 * @param string $collection Collection NSID the URI must name.
	 * @return string The URI when it checks out, '' otherwise.
	 */
	private static function verified_record_uri( string $uri, string $collection ): string {
		$parsed = parse_at_uri( $uri );

		if ( false === $parsed ) {
			return '';
		}

		if (
			get_did() !== $parsed['did']
			|| $collection !== $parsed['collection']
			|| '' === $parsed['rkey']
		) {
			return '';
		}

		return $uri;
	}

	/**
	 * The companion `app.bsky.feed.post` AT-URI for the page being
	 * rendered, or an empty string when there is none to advertise.
	 *
	 * Read back verbatim from the meta the Publisher wrote and validated
	 * through {@see Atmosphere::verified_record_uri()}, on the same
	 * terms as the document URI.
	 *
	 * The origin DID deliberately comes out of the URI rather than
	 * `Post::META_DID`. That meta is not a safe source:
	 * {@see \Atmosphere\Transformer\Post::get_rkey()} refreshes it to
	 * the current DID on every call, before any write to the PDS has
	 * succeeded, so a failed republish after reconnecting leaves the row
	 * claiming the current account while `META_URI` still points at the
	 * old one. Parsing the URI also covers pre-`META_DID` rows, which a
	 * meta comparison has to wave through for lack of anything to
	 * compare. This is why the check does not mirror the mismatch guard
	 * in `Publisher::delete_post()`: that one decides which repo to
	 * issue a delete against and has only the rkey meta to go on, while
	 * here the full AT-URI is in hand.
	 *
	 * @return string AT-URI, or '' when the page has no Bluesky record
	 *                belonging to the connected account.
	 */
	private static function current_bsky_post_uri(): string {
		return self::head_memo(
			'bsky',
			static function () {
				$post = self::current_publishable_post();

				if ( null === $post ) {
					return '';
				}

				$uri = \get_post_meta( $post->ID, Post::META_URI, true );

				if ( ! \is_string( $uri ) || '' === $uri ) {
					return '';
				}

				return self::verified_record_uri( $uri, 'app.bsky.feed.post' );
			}
		);
	}

	/**
	 * The queried post when the current request is a singular view of a
	 * post whose records may be named in the page head, or null when it
	 * is not.
	 *
	 * Wraps {@see \Atmosphere\is_post_publishable()} with the two
	 * request-shape conditions the head emitters share: a persisted
	 * identity to name records under, and a singular view to name them
	 * on.
	 *
	 * @return \WP_Post|null
	 */
	private static function current_publishable_post(): ?\WP_Post {
		return self::head_memo(
			'post',
			static function () {
				if ( ! has_identity() || ! \is_singular() ) {
					return null;
				}

				$post = \get_queried_object();

				if ( ! $post instanceof \WP_Post || ! is_post_publishable( $post ) ) {
					return null;
				}

				return $post;
			}
		);
	}

	/**
	 * Resolve a head-emitter value once per request.
	 *
	 * The queried object and the connected DID are folded into the key
	 * so a cached answer can never outlive the request state it was
	 * computed from.
	 *
	 * @param string   $key     Resolver identifier.
	 * @param callable $resolve Produces the value on a miss.
	 * @return mixed The resolved value.
	 */
	private static function head_memo( string $key, callable $resolve ): mixed {
		$key .= '|' . \get_queried_object_id() . '|' . get_did();

		if ( ! \array_key_exists( $key, self::$head_record_cache ) ) {
			self::$head_record_cache[ $key ] = $resolve();
		}

		return self::$head_record_cache[ $key ];
	}

	/**
	 * Clear the head record cache.
	 *
	 * Hooked on `wp_head` at priority 0, so each head render starts from
	 * nothing. A process static would otherwise be exactly as long-lived
	 * as the process: under mod_php or FPM that is one request and the
	 * distinction never shows, but under a persistent runtime — FrankenPHP
	 * worker mode, Swoole, RoadRunner — a worker serves many requests, and
	 * a post whose document was re-minted to a new rkey (delete then
	 * republish, or a backfill) would keep being advertised under the old
	 * AT-URI until that worker recycled. The memo only needs to survive
	 * the head render, so scoping it there costs nothing and removes the
	 * question.
	 *
	 * Tests call this directly too: a test process is not a request, so
	 * two tests rendering the same URL under different options would
	 * otherwise collide on one cache key.
	 *
	 * @return void
	 */
	public static function flush_head_record_cache(): void {
		self::$head_record_cache = array();
	}

	/**
	 * Output `<link rel="site.standard.publication">` on the URLs that
	 * map to the publication record's `url` field.
	 *
	 * Emitted on:
	 *
	 * - Singular publishable posts, so a resolver landing on an article
	 *   URL can find the parent publication directly without first
	 *   fetching the document record.
	 * - The WordPress front page, which is the local page represented
	 *   by the normalized publication URL. Lets a resolver verify the
	 *   page <-> publication binding by matching AT-URIs, sparing the
	 *   `.well-known/site.standard.publication` round-trip.
	 *
	 * Gated on `has_identity()` (not `is_connected()`) so the
	 * verification link survives transient OAuth refresh failures, in
	 * lockstep with {@see Atmosphere::output_document_link()}.
	 */
	public function output_publication_link(): void {
		if ( ! self::is_publication_url() ) {
			return;
		}

		$uri = self::publication_uri();

		if ( '' === $uri ) {
			return;
		}

		\printf(
			'<link rel="site.standard.publication" href="%s" />' . "\n",
			\esc_attr( $uri )
		);
	}

	/**
	 * The site's `site.standard.publication` AT-URI, or an empty string
	 * when the site has no identity or has not minted a publication TID
	 * yet (fresh install, pre-sync).
	 *
	 * Says nothing about whether the current URL is one the publication
	 * should be advertised on — that's {@see Atmosphere::is_publication_url()}
	 * for the link tag, and the front-page test in
	 * {@see Atmosphere::output_at_tags()} for the meta tags.
	 *
	 * @return string AT-URI, or '' when there is no publication record.
	 */
	private static function publication_uri(): string {
		return self::head_memo(
			'publication',
			static function () {
				if ( ! has_identity() ) {
					return '';
				}

				$pub_tid = \get_option( Publication::OPTION_TID );

				/*
				 * Type-check rather than cast: a corrupted option holding
				 * an array would raise an "Array to string conversion"
				 * notice on the front end, and `build_at_uri()` would
				 * splice the word "Array" into a published AT-URI.
				 */
				if ( ! \is_string( $pub_tid ) || '' === $pub_tid ) {
					return '';
				}

				return build_at_uri( get_did(), 'site.standard.publication', $pub_tid );
			}
		);
	}

	/**
	 * Output the AT Tags `<meta>` mapping from this page to the AT
	 * Protocol records behind it.
	 *
	 * Implements the community AT Tags proposal
	 * (https://tangled.org/chrisshank.com/at-tags/), which Bluesky and
	 * Leaflet both emit. `at:canonical` marks the records the page is a
	 * rendering of — delete them and the page has nothing left to
	 * represent — while `at:alternate` marks records the page merely
	 * references. Repeated names are read as arrays, which is how a post
	 * advertises both its publication and its Bluesky post as alternates.
	 *
	 * The mapping:
	 *
	 * - Singular publishable post with a document record: the document
	 *   is canonical; the parent publication and the companion Bluesky
	 *   post (which backs the reactions and synced comments displayed on
	 *   the page) are alternates.
	 * - Front page: the publication is canonical, since the front page
	 *   is the local page the publication record's `url` points at.
	 * - A static front page that is also a publishable post with a
	 *   document record emits both as canonical, per the array
	 *   semantics — the URL genuinely maps to both records.
	 *
	 * Both alternates are tied to the document's presence, rather than
	 * the publication following `is_publication_url()` and the Bluesky
	 * post standing on its own: a page with no canonical record has
	 * nothing for an alternate to be an alternate *to*. So a post that
	 * carries a Bluesky record but no document — the state `Backfill`
	 * exists to find, and the reason `has_post_records()` ORs the two
	 * meta keys — stays silent here rather than advertising a lone
	 * reference.
	 *
	 * The front-page test below is deliberately not routed through
	 * `is_publication_url()`. The two answer different questions:
	 * `is_publication_url()` asks whether the publication should be
	 * named on this URL at all (front page *or* publishable singular),
	 * while this asks where it is *canonical* (front page only), the
	 * singular case being covered by the alternate branch. Collapsing
	 * them would make every publishable post claim to be a rendering of
	 * the publication record.
	 *
	 * These are additive. The `<link rel>` tags still ship, so consumers
	 * that only read those keep working.
	 */
	public function output_at_tags(): void {
		$doc_uri = self::current_document_uri();
		$pub_uri = self::publication_uri();

		$tags = array(
			'at:canonical' => array(),
			'at:alternate' => array(),
		);

		if ( '' !== $doc_uri ) {
			$tags['at:canonical'][] = $doc_uri;
		}

		if ( '' !== $pub_uri ) {
			if ( \is_front_page() ) {
				$tags['at:canonical'][] = $pub_uri;
			} elseif ( '' !== $doc_uri ) {
				$tags['at:alternate'][] = $pub_uri;
			}
		}

		if ( '' !== $doc_uri ) {
			$bsky_uri = self::current_bsky_post_uri();

			if ( '' !== $bsky_uri ) {
				$tags['at:alternate'][] = $bsky_uri;
			}
		}

		/**
		 * Filters the AT Tags emitted in the page head.
		 *
		 * Keyed by tag name, each holding a list of AT-URIs; a name with
		 * an empty list prints nothing. This is the supported way to add
		 * the tags the plugin does not emit itself — `at:me`, `at:author`,
		 * or a namespaced `at:{namespace}:{property}` property. Neither
		 * identity tag ships by default: a site has exactly one connected
		 * account, so `at:author` would attribute every post on a
		 * multi-author site to whoever connected it.
		 *
		 * @since 2.2.0
		 *
		 * @param array<string, string[]> $tags Tag name => list of AT-URIs.
		 */
		$tags = \apply_filters( 'atmosphere_at_tags', $tags );

		if ( ! \is_array( $tags ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'The atmosphere_at_tags filter must return an array keyed by tag name.', 'atmosphere' ),
				'2.2.0'
			);
			return;
		}

		$skipped = false;

		foreach ( $tags as $name => $uris ) {
			// A filter returning a plain list would otherwise print `name="0"`.
			if ( ! \is_string( $name ) || '' === $name ) {
				$skipped = true;
				continue;
			}

			foreach ( (array) $uris as $uri ) {
				if ( ! \is_string( $uri ) || '' === $uri ) {
					$skipped = true;
					continue;
				}

				\printf(
					'<meta name="%s" content="%s" />' . "\n",
					\esc_attr( $name ),
					\esc_attr( $uri )
				);
			}
		}

		/*
		 * Dropping malformed entries is the right behaviour — one bad
		 * tag should not take the rest of the head with it — but doing
		 * it silently leaves the filtering plugin with no way to see
		 * why its tag never appeared. Reported once per request rather
		 * than per entry so a badly-shaped array can't flood the log.
		 */
		if ( $skipped ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'The atmosphere_at_tags filter produced entries that were not non-empty strings; those were skipped.', 'atmosphere' ),
				'2.2.0'
			);
		}
	}

	/**
	 * Whether the current request URL maps to the publication record's
	 * `url` field — i.e. a URL where the `<link rel="site.standard.publication">`
	 * tag belongs.
	 *
	 * - The WordPress front page always qualifies, regardless of
	 *   whether it shows posts or a static page (a static page set
	 *   as front is both `is_front_page()` AND `is_singular('page')`;
	 *   checking the front-page condition first is what keeps the
	 *   tag emitting in that configuration).
	 * - A publishable singular post qualifies because its document
	 *   record carries a reference back to the publication.
	 *
	 * The singular arm defers to {@see Atmosphere::current_publishable_post()}
	 * so the publishability check is resolved once per request rather
	 * than repeated here. That helper additionally requires an identity,
	 * which changes nothing: the only caller pairs this with
	 * {@see Atmosphere::publication_uri()}, which returns '' without one.
	 */
	private static function is_publication_url(): bool {
		if ( \is_front_page() ) {
			return true;
		}

		return null !== self::current_publishable_post();
	}

	/**
	 * Register the built-in content parsers on the registry.
	 *
	 * The WordPress HTML parser is the automatic winner (lowest priority
	 * number); it applies to any post and carries no blob dependency.
	 * The block-tree formats register at the same, higher number and
	 * only apply to block-editor posts, so a site can opt into them via
	 * the Content format setting.
	 *
	 * @return void
	 */
	public static function register_default_content_parsers(): void {
		Registry::register( new Html(), 10 );
		Registry::register( new Markpub(), 20 );
		Registry::register( new Leaflet(), 20 );
		Registry::register( new Pckt(), 20 );
	}

	/**
	 * Regex patterns of the well-known rewrite rules this plugin owns.
	 *
	 * Maps each pattern to its `index.php` query target. Kept as a single
	 * source of truth so {@see register_wellknown_rewrite()} and
	 * {@see maybe_flush_wellknown_rewrites()} stay in lockstep — if a
	 * future rule is added or renamed, both surfaces pick it up without
	 * a separate edit, and the persisted-rules check still detects drift.
	 *
	 * @var array<string, string>
	 */
	private const WELLKNOWN_REWRITE_PATTERNS = array(
		'^\.well-known/atproto-did/?$'                 => 'index.php?atmosphere_wellknown=atproto-did',
		'^\.well-known/site\.standard\.publication/?$' => 'index.php?atmosphere_wellknown=publication',
	);

	/**
	 * Register rewrite rules for well-known endpoints.
	 */
	public function register_wellknown_rewrite(): void {
		foreach ( self::WELLKNOWN_REWRITE_PATTERNS as $pattern => $target ) {
			\add_rewrite_rule( $pattern, $target, 'top' );
		}
	}

	/**
	 * Register public query vars used by front-end endpoints.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[] Public query vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'atmosphere_wellknown';
		$vars[] = 'atproto';

		return $vars;
	}

	/**
	 * Ensure the well-known rewrite rules are present in the persisted
	 * `rewrite_rules` option, and flush them in if not.
	 *
	 * The activation hook flushes once, but the rule set can drift away
	 * from the persisted array later for several real install paths:
	 *
	 * - Programmatic loads (FOSSE bundle, `require_once`, mu-plugin,
	 *   etc.) never fire `register_activation_hook`, so the initial
	 *   flush never runs.
	 * - Some plugins or hosts wipe `wp_options.rewrite_rules` outside
	 *   of activation. WP then rebuilds the array from whatever rules
	 *   happen to be registered at that moment, which may or may not
	 *   include ours.
	 * - Another plugin that flushes earlier on `init` than our rule
	 *   registration produces a persisted array missing our patterns.
	 *
	 * In all three cases the runtime registration in
	 * {@see register_wellknown_rewrite()} keeps happening on every
	 * request but is functionally inert, because WP routes from the
	 * persisted array, not the in-memory one. The user-facing symptom
	 * is "External handle did not resolve to DID" when the PDS fetches
	 * `/.well-known/atproto-did` and WP serves the normal 404 template
	 * instead of our handler.
	 *
	 * Called surgically from the moments that matter so this is not
	 * paid on every request:
	 *
	 * - After a successful OAuth handshake persists an identity —
	 *   {@see \Atmosphere\OAuth\Client::handle_callback()}.
	 * - When an administrator loads the Atmosphere settings page —
	 *   {@see \Atmosphere\WP_Admin\Admin::add_menu()}.
	 * - Before the `updateHandle` XRPC call, which triggers the PDS to
	 *   fetch the well-known endpoint immediately —
	 *   {@see \Atmosphere\Handle::set_handle()}.
	 *
	 * Uses a soft flush (no `.htaccess` rewrite): WordPress's default
	 * rewrite fallback already routes unmatched URLs to `index.php`, so
	 * refreshing the persisted `rewrite_rules` option is enough to make
	 * our rules take effect without touching the webserver config.
	 */
	public static function maybe_flush_wellknown_rewrites(): void {
		global $wp_rewrite;

		/*
		 * Plain permalinks (the WordPress default `?p=N` scheme) keep
		 * `rewrite_rules` empty and route every request through the query
		 * string. Our `^\.well-known/...$` patterns can never appear in
		 * the persisted array on such a site, and the endpoints cannot
		 * resolve via rewrite there regardless. Bail before the
		 * missing-pattern check so we do not read an always-empty array
		 * as "patterns missing" and burn an `update_option` write on
		 * every call.
		 *
		 * Read the state from `$wp_rewrite` rather than the
		 * `permalink_structure` option: `flush_rewrite_rules()` rebuilds
		 * from `$wp_rewrite`'s in-memory structure, so gating on the same
		 * source keeps the guard and the flush in agreement even if
		 * something wrote the option directly after `WP_Rewrite::init()`
		 * ran this request.
		 */
		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
			return;
		}

		$rules = \get_option( 'rewrite_rules' );

		if ( \is_array( $rules ) ) {
			foreach ( self::WELLKNOWN_REWRITE_PATTERNS as $pattern => $target ) {
				if ( ! isset( $rules[ $pattern ] ) || $rules[ $pattern ] !== $target ) {
					\flush_rewrite_rules( false );
					return;
				}
			}

			return;
		}

		\flush_rewrite_rules( false );
	}

	/**
	 * Serve the /.well-known/atproto-did response.
	 *
	 * Returns the connected DID as plain text so the domain can be
	 * verified as an AT Protocol handle (domain handle verification).
	 *
	 * @see https://atproto.com/specs/handle#handle-resolution
	 */
	public function serve_wellknown_atproto_did(): void {
		if ( \get_query_var( 'atmosphere_wellknown' ) !== 'atproto-did' ) {
			return;
		}

		/*
		 * Bidirectional verification re-fetches this endpoint on every
		 * profile load, so a fronting page/CDN cache must never retain a
		 * pre-connect 404 or a post-disconnect 200 with a stale DID. Send
		 * no-cache headers on every response branch below.
		 */
		\nocache_headers();

		/*
		 * Identity gate (not connection gate): an expired OAuth session
		 * must not break domain handle verification. Bluesky's resolver
		 * re-fetches this endpoint to confirm the bidirectional link
		 * each time a profile loads, so a transient token failure
		 * otherwise propagates as "handle no longer resolves" until the
		 * site admin reconnects.
		 */
		if ( ! has_identity() ) {
			\status_header( 404 );
			exit;
		}

		\status_header( 200 );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		echo \esc_html( get_did() );
		exit;
	}

	/**
	 * Serve the /.well-known/site.standard.publication response.
	 *
	 * Returns the AT-URI of the publication record as plain text,
	 * confirming the link between this domain and the publication.
	 */
	public function serve_wellknown_publication(): void {
		if ( \get_query_var( 'atmosphere_wellknown' ) !== 'publication' ) {
			return;
		}

		/*
		 * Bidirectional verification re-fetches this endpoint on every
		 * profile load, so a fronting page/CDN cache must never retain a
		 * pre-connect 404 or a post-disconnect 200 with a stale AT-URI.
		 * Send no-cache headers on every response branch below.
		 */
		\nocache_headers();

		/*
		 * Identity gate (not connection gate): the publication AT-URI is
		 * derived from the persisted DID + publication TID, both of
		 * which outlive a transient OAuth refresh failure. Returning 404
		 * here while waiting for the user to reconnect would break
		 * standard.site's bidirectional verification each time the
		 * token rotates.
		 */
		if ( ! has_identity() ) {
			\status_header( 404 );
			exit;
		}

		$pub_tid = \get_option( Publication::OPTION_TID );

		if ( ! $pub_tid ) {
			\status_header( 404 );
			exit;
		}

		$uri = build_at_uri( get_did(), 'site.standard.publication', $pub_tid );

		\status_header( 200 );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		echo \esc_html( $uri );
		exit;
	}

	/**
	 * Handle post status transitions.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 */
	public function on_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! is_connected() ) {
			return;
		}

		/*
		 * Publish only when auto-publish is effectively on. The gate folds
		 * together the stored `atmosphere_auto_publish` option (opt-out, off
		 * for any non-'1' value the same way the ActivityPub plugin gates its
		 * toggles), connection-only mode, and the `atmosphere_should_auto_publish`
		 * filter. See {@see \Atmosphere\is_auto_publish_enabled()}.
		 */
		if ( ! is_auto_publish_enabled() ) {
			return;
		}

		$is_publishable         = is_post_publishable( $post );
		$has_records            = self::has_post_records( $post );
		$had_visibility_cleanup = self::has_visibility_cleanup_marker( $post );
		$is_new_publish         = $is_publishable && ! $has_records && ( 'publish' !== $old_status || $had_visibility_cleanup );
		$is_update              = $is_publishable && ! $is_new_publish;
		$is_cleanup             = 'publish' === $old_status && ! $is_publishable && $has_records;

		if ( ! $is_new_publish && ! $is_update && ! $is_cleanup ) {
			// Transition between two non-publish states; nothing to schedule.
			return;
		}

		/*
		 * Publish-time decisions respect current public visibility.
		 * Cleanup is different: if a previously-published post becomes
		 * non-public (draft/private/trash, password-protected, or no
		 * longer supported), remote records must be removed even though
		 * the post is no longer publishable.
		 */
		if ( isset( self::$publishing_post_ids[ $post->ID ] ) ) {
			return;
		}

		self::$publishing_post_ids[ $post->ID ] = true;

		/*
		 * Wrap in try/finally so a throwing listener on
		 * `atmosphere_publishing` (Sentry SDK, JSON_THROW_ON_ERROR in
		 * a webhook sink, etc.) can't strand the per-post guard. A
		 * stuck entry silently no-ops every subsequent transition of
		 * the same post ID in the current PHP process — especially
		 * painful for WP-CLI bulk imports where one fatal early in
		 * the run poisons every later transition of that ID.
		 */
		try {
			\do_action( 'atmosphere_publishing', $post );

			/*
			 * A status transition is fresh user intent, so it starts a
			 * new retry budget. Without this, a counter stranded by a
			 * dead retry event (disconnect cleared the queue, the post
			 * was trashed mid-ladder, a cron event was lost) would
			 * silently shrink — or zero out — the ladder of the next
			 * publish attempt. The stale failure record goes with it:
			 * on a cleanup transition the delete worker never routes
			 * through the retry helper, so an old "share failed" notice
			 * would otherwise stick to a post that is no longer shared.
			 */
			\delete_post_meta( $post->ID, self::META_PUBLISH_RETRIES );
			\delete_post_meta( $post->ID, self::META_LAST_PUBLISH_ERROR );

			if ( $is_publishable ) {
				\wp_clear_scheduled_hook( 'atmosphere_delete_post', array( $post->ID ) );
			}

			if ( $is_new_publish ) {
				\wp_schedule_single_event( \time(), 'atmosphere_publish_post', array( $post->ID ) );
			} elseif ( $is_update ) {
				\wp_schedule_single_event( \time(), 'atmosphere_update_post', array( $post->ID ) );
			} else {
				self::mark_visibility_cleanup( $post );

				/*
				 * Genuine unpublish — use atmosphere_delete_post (not
				 * delete_records) so post meta is cleaned up on success,
				 * allowing a subsequent restore (trash → publish) to
				 * republish correctly.
				 */
				\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );
			}
		} finally {
			unset( self::$publishing_post_ids[ $post->ID ] );
		}
	}

	/**
	 * Max posts the historical migration scans per cron tick.
	 *
	 * Bounded so a site with thousands of historical Atmosphere
	 * records can't blow the cron handler's execution-time budget on
	 * a single fire. The handler reschedules itself until the walk is
	 * exhausted, then sets `OPTION_VISIBILITY_CLEANUP_MIGRATED`.
	 *
	 * @var int
	 */
	private const VISIBILITY_CLEANUP_BATCH_SIZE = 200;

	/**
	 * Queue the historical visibility-cleanup walk if needed.
	 *
	 * Runs on `admin_init`. Bails on subscriber-level users so the
	 * cron event is only ever scheduled by an actual administrator,
	 * even though the walk itself runs in cron context. Bails on
	 * any other condition that would re-schedule the same event,
	 * keeping concurrent admin pageloads from queuing duplicates.
	 */
	public function maybe_queue_historical_visibility_cleanup(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_connected() ) {
			return;
		}

		if ( \get_option( self::OPTION_VISIBILITY_CLEANUP_MIGRATED ) ) {
			return;
		}

		if ( \wp_next_scheduled( 'atmosphere_run_historical_visibility_cleanup' ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_run_historical_visibility_cleanup' );
	}

	/**
	 * Cron handler: walk a single batch of historical posts and queue
	 * cleanup for those that lost public visibility.
	 *
	 * Uses keyset (ID > last_seen) paging rather than `offset` — once
	 * a post's records are deleted, the migration's `meta_query` no
	 * longer matches it, so offset-paged windows would skip ahead by
	 * roughly the number of completed deletes per batch. Keyset
	 * paging is stable against in-flight deletes.
	 *
	 * Reschedules itself for the next batch BEFORE processing so a
	 * mid-batch fatal (OOM, listener fatal) still leaves a recovery
	 * breadcrumb — the next cron tick picks up at the persisted
	 * cursor. An empty batch terminates the walk and sets the
	 * one-shot migration option.
	 */
	public function run_historical_visibility_cleanup(): void {
		if ( \get_option( self::OPTION_VISIBILITY_CLEANUP_MIGRATED ) ) {
			return;
		}

		$last_id = (int) \get_option( self::OPTION_VISIBILITY_CLEANUP_LAST_ID, 0 );

		global $wpdb;

		/*
		 * Raw query because WP_Query's `offset` is unstable under
		 * concurrent deletes (see method docblock). The meta-key list
		 * mirrors the OR EXISTS branches the original `meta_query`
		 * used; `DISTINCT` collapses posts that match on multiple
		 * keys (very common — most synced posts have both `bsky_tid`
		 * and `doc_tid`).
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.ID > %d
				   AND pm.meta_key IN (%s, %s, %s, %s)
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$last_id,
				Post::META_TID,
				Post::META_URI,
				Post::META_THREAD_RECORDS,
				Document::META_URI,
				self::VISIBILITY_CLEANUP_BATCH_SIZE
			)
		);
		// phpcs:enable

		if ( empty( $post_ids ) ) {
			\delete_option( self::OPTION_VISIBILITY_CLEANUP_LAST_ID );
			\update_option( self::OPTION_VISIBILITY_CLEANUP_MIGRATED, '1', false );
			return;
		}

		/*
		 * Persist the cursor BEFORE processing the batch and queue
		 * the next run BEFORE the foreach. A fatal mid-batch then
		 * leaves a recovery breadcrumb (the next cron pulls up at
		 * the cursor we've already advanced past in the DB query but
		 * not in the persisted state) rather than stranding the
		 * migration until a manage_options admin hits admin_init.
		 */
		$max_id = (int) \end( $post_ids );
		\update_option( self::OPTION_VISIBILITY_CLEANUP_LAST_ID, $max_id, false );

		\wp_schedule_single_event( \time() + 60, 'atmosphere_run_historical_visibility_cleanup' );

		foreach ( $post_ids as $post_id ) {
			$post = \get_post( (int) $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( is_post_publishable( $post ) || ! self::has_post_records( $post ) ) {
				continue;
			}

			self::mark_visibility_cleanup( $post );

			if ( \wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ) ) {
				continue;
			}

			\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );
		}
	}

	/**
	 * Whether the post has local metadata for remote records.
	 *
	 * Used to distinguish a cleanup-worthy post from a non-public post
	 * that never reached the PDS.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private static function has_post_records( \WP_Post $post ): bool {
		/*
		 * Drop `Document::META_TID` from the "has records" signal,
		 * keep everything else.
		 *
		 * `Document::META_TID` is the only meta key that can be
		 * written speculatively: `output_document_link()` (frontend
		 * `wp_head`) lazily mints it on the first singular pageview
		 * so the `<link rel="site.standard.document">` tag has
		 * something to point at — well before any publish to the
		 * PDS. Treating that pre-publish TID stamp as "has records"
		 * misclassifies every transition to publish as `is_update`,
		 * the cron handler refuses because no URI/CID exists to
		 * update against, and the publish silently no-ops.
		 *
		 * `Post::META_TID` is different: Publisher writes it only
		 * after a successful `applyWrites`, alongside `META_URI` /
		 * `META_CID`. It's an honest signal of an existing record
		 * and remains a positive indicator here.
		 */
		return ! empty( \get_post_meta( $post->ID, Transformer\Post::META_TID, true ) )
			|| ! empty( \get_post_meta( $post->ID, Transformer\Post::META_URI, true ) )
			|| ! empty( \get_post_meta( $post->ID, Transformer\Post::META_THREAD_RECORDS, true ) )
			|| ! empty( \get_post_meta( $post->ID, Transformer\Document::META_URI, true ) );
	}

	/**
	 * Whether this post previously had records removed for visibility.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private static function has_visibility_cleanup_marker( \WP_Post $post ): bool {
		return (bool) \get_post_meta( $post->ID, self::META_VISIBILITY_CLEANUP, true );
	}

	/**
	 * Mark a post as needing fresh publish if it becomes public again.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function mark_visibility_cleanup( \WP_Post $post ): void {
		\update_post_meta( $post->ID, self::META_VISIBILITY_CLEANUP, '1' );
	}

	/**
	 * Clear the visibility-cleanup marker after a successful publish/update.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function clear_visibility_cleanup_marker( \WP_Post $post ): void {
		\delete_post_meta( $post->ID, self::META_VISIBILITY_CLEANUP );
	}

	/**
	 * Schedule AT Protocol record deletion before a post is permanently deleted.
	 *
	 * Captures every Bluesky post TID (root + thread replies) and the
	 * document TID from post meta, then schedules an async batch delete
	 * via cron. When comment publishing is enabled, outbound comment
	 * replies are also collected through
	 * `Publisher::collect_published_comment_tids()`.
	 *
	 * Enabled comment TIDs must be collected here, while WP still has the
	 * comment rows: `wp_delete_post( $id, true )` fires `before_delete_post`
	 * first and only then iterates child comments, so this is the last
	 * opportunity to read them.
	 *
	 * While comment publishing is enabled, the trash path
	 * (`Publisher::delete_post()`) also cascades comment deletes. This
	 * keeps permanent deletion symmetric. When they are disabled, both
	 * paths preserve existing outbound replies instead.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function on_before_delete( int $post_id ): void {
		if ( ! is_connected() ) {
			return;
		}

		$post = \get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		/*
		 * No support check here. Permanent delete is a cleanup path: if
		 * the post has Atmosphere publication metadata it was synced at
		 * some point, and the remote records must be removed even if the
		 * post type has since been removed from the supported list.
		 * Gating this on current support would orphan already-published
		 * records whenever a site narrows its configuration.
		 */
		$bsky_tids = array();

		$thread_records = \get_post_meta( $post_id, Transformer\Post::META_THREAD_RECORDS, true );
		if ( \is_array( $thread_records ) && ! empty( $thread_records ) ) {
			foreach ( $thread_records as $record ) {
				if ( ! empty( $record['tid'] ) ) {
					$bsky_tids[] = (string) $record['tid'];
				}
			}
		}

		if ( empty( $bsky_tids ) ) {
			$legacy_tid = \get_post_meta( $post_id, Transformer\Post::META_TID, true );
			if ( $legacy_tid ) {
				$bsky_tids[] = (string) $legacy_tid;
			}
		}

		$doc_tid = (string) \get_post_meta( $post_id, Transformer\Document::META_TID, true );

		$comment_tids = is_comment_publishing_enabled()
			? \array_column( Publisher::collect_published_comment_tids( $post_id ), 'tid' )
			: array();

		/*
		 * Capture the DIDs the records were minted under while the meta
		 * still exists. By the time the cron fires the post row and its
		 * meta are gone, so delete_post_by_tids() cannot look them up
		 * itself; passing them through lets it refuse to delete against a
		 * repo the records never lived in (disconnect + reconnect-to-a-new
		 * account).
		 */
		$bsky_origin_did = (string) \get_post_meta( $post_id, Transformer\Post::META_DID, true );
		$doc_origin_did  = (string) \get_post_meta( $post_id, Transformer\Document::META_DID, true );

		// A written threadgate shares the root post's rkey. Capture it now
		// while the meta still exists so the async delete can remove it after
		// the post row is gone.
		$threadgate_tid = ( ! empty( $bsky_tids ) && Threadgate::is_written( $post_id ) )
			? $bsky_tids[0]
			: '';

		if ( ! empty( $bsky_tids ) || '' !== $doc_tid || ! empty( $comment_tids ) ) {
			\wp_schedule_single_event(
				\time(),
				'atmosphere_delete_records',
				array( $bsky_tids, $doc_tid, $comment_tids, $threadgate_tid, $bsky_origin_did, $doc_origin_did )
			);
		}
	}

	/**
	 * Handle a comment transitioning between approval states.
	 *
	 * @param string      $new_status New comment_approved value.
	 * @param string      $old_status Previous comment_approved value.
	 * @param \WP_Comment $comment    Comment object.
	 */
	public function on_comment_status_change( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( 'approved' === $new_status ) {
			$this->schedule_comment_publish( $comment );
			return;
		}

		if ( 'approved' === $old_status ) {
			$this->schedule_comment_delete( $comment );
		}
	}

	/**
	 * Handle a newly-inserted comment.
	 *
	 * Covers the case where a comment lands already-approved (trusted
	 * author), for which transition_comment_status does not fire.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approval status (1, 0, or 'spam').
	 */
	public function on_comment_insert( int $comment_id, int|string $comment_approved ): void {
		if ( 1 !== (int) $comment_approved ) {
			return;
		}

		$comment = \get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->schedule_comment_publish( $comment );
		}
	}

	/**
	 * Handle a comment edit by updating its bsky record.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_edit( int $comment_id ): void {
		$comment = \get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		if ( ! self::should_publish_comment( $comment ) ) {
			return;
		}

		$hook = empty( \get_comment_meta( $comment_id, Comment::META_URI, true ) )
			? 'atmosphere_publish_comment'
			: 'atmosphere_update_comment';

		if ( ! \wp_next_scheduled( $hook, array( $comment_id ) ) ) {
			\wp_schedule_single_event( \time(), $hook, array( $comment_id ) );
		}
	}

	/**
	 * Capture a comment's TID before it is permanently deleted.
	 *
	 * Runs on delete_comment which fires before the row and meta are
	 * removed, so the TID is still reachable. META_URI is the only
	 * reliable signal that a record exists on the PDS — the TID is
	 * persisted eagerly by Comment::get_rkey() before the applyWrites
	 * call, so a TID alone matches both the normal pre-publish state
	 * and a publish that failed after TID allocation; neither should
	 * schedule a delete. The TID-only cron variant lets the async
	 * worker issue the PDS delete without re-reading state that no
	 * longer exists.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_before_delete( int $comment_id ): void {
		if ( ! is_comment_publishing_enabled() || ! is_connected() ) {
			return;
		}

		$uri = \get_comment_meta( $comment_id, Comment::META_URI, true );

		if ( empty( $uri ) ) {
			return;
		}

		$tid = \get_comment_meta( $comment_id, Comment::META_TID, true );

		if ( empty( $tid ) ) {
			return;
		}

		/*
		 * Capture the DID the reply was minted under before the row and
		 * its meta are removed, so the async worker can refuse to delete
		 * against a repo the record never lived in.
		 */
		$origin_did = (string) \get_comment_meta( $comment_id, Comment::META_DID, true );

		$tid  = (string) $tid;
		$args = array( $tid, $origin_did );

		if ( \wp_next_scheduled( 'atmosphere_delete_comment_record', $args ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_delete_comment_record', $args );
	}

	/**
	 * Eligibility gate for outbound comment publishing.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return bool
	 */
	public static function should_publish_comment( \WP_Comment $comment ): bool {
		/*
		 * Checked first: a disabled site skips the eligibility work (which
		 * busts the parent post's cache on every comment event) and the
		 * per-comment filter entirely.
		 */
		if ( ! is_comment_publishing_enabled() ) {
			return false;
		}

		$should = self::is_comment_eligible( $comment );

		/**
		 * Filters whether a comment should be published to Bluesky.
		 *
		 * @param bool        $should  Whether to publish.
		 * @param \WP_Comment $comment Comment object.
		 */
		return (bool) \apply_filters( 'atmosphere_should_publish_comment', $should, $comment );
	}

	/**
	 * Core comment eligibility checks, pre-filter.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return bool
	 */
	private static function is_comment_eligible( \WP_Comment $comment ): bool {
		if ( ! is_connected() ) {
			return false;
		}

		if ( \in_array( (string) $comment->comment_type, array( 'trackback', 'pingback' ), true ) ) {
			return false;
		}

		$user_id = (int) $comment->user_id;

		/*
		 * Registered users may be Subscribers who can comment but are not
		 * trusted to publish site content. Outbound replies are written by
		 * the site's connected Bluesky account, so use the comment author's
		 * stored capabilities rather than the current user: this gate also
		 * runs asynchronously in WP-Cron, where nobody is logged in.
		 */
		if ( $user_id <= 0 || ! \user_can( $user_id, 'publish_posts' ) ) {
			return false;
		}

		if ( '1' !== (string) $comment->comment_approved ) {
			return false;
		}

		if ( 'atproto' === \get_comment_meta( (int) $comment->comment_ID, Reaction_Sync::META_PROTOCOL, true ) ) {
			return false;
		}

		/*
		 * Defence in depth: Reaction_Sync writes META_PROTOCOL after
		 * wp_insert_comment, so if any caller ever fires comment_post
		 * between the insert and the meta write, the gate above would
		 * miss it. The sync always stamps its own agent string, which
		 * is set before the insert — use it as a belt-and-braces check.
		 */
		if ( 0 === \strpos( (string) $comment->comment_agent, 'ATmosphere/' ) ) {
			return false;
		}

		$post_id = (int) $comment->comment_post_ID;

		/*
		 * Drop the in-process `WP_Post` cache so a concurrent web
		 * request that just password-protected the parent is visible
		 * to this worker. Same exposure as the publisher reconcile
		 * path on installs without a persistent object cache: without
		 * this invalidation, the cron handler would publish the reply
		 * against a now-protected parent.
		 */
		\clean_post_cache( $post_id );
		$post = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! is_post_publishable( $post ) ) {
			return false;
		}

		/*
		 * A gated parent keeps its comment thread private too. The post lane
		 * narrows every body-derived field through get_publishable_content(),
		 * but a reply can quote or continue the gated discussion, and the
		 * membership plugin shows the on-site thread behind its gate — so on
		 * a gated post (fully gated, split-point, an inline region, or a
		 * gated access level on a body that narrows no bytes) no comment
		 * federates.
		 */
		if ( is_post_gated( $post ) ) {
			return false;
		}

		$post_uri = \get_post_meta( $post_id, Post::META_URI, true );
		$post_cid = \get_post_meta( $post_id, Post::META_CID, true );

		// Both URI and CID are required to build a valid reply.root strongRef.
		if ( empty( $post_uri ) || empty( $post_cid ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Schedule a publish or update event for a comment.
	 *
	 * @param \WP_Comment $comment Comment object.
	 */
	private function schedule_comment_publish( \WP_Comment $comment ): void {
		if ( ! self::should_publish_comment( $comment ) ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$hook       = empty( \get_comment_meta( $comment_id, Comment::META_URI, true ) )
			? 'atmosphere_publish_comment'
			: 'atmosphere_update_comment';

		if ( \wp_next_scheduled( $hook, array( $comment_id ) ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), $hook, array( $comment_id ) );
	}

	/**
	 * Schedule a delete event when a published comment leaves approved state.
	 *
	 * @param \WP_Comment $comment Comment object.
	 */
	private function schedule_comment_delete( \WP_Comment $comment ): void {
		if ( ! is_comment_publishing_enabled() || ! is_connected() ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;

		if ( empty( \get_comment_meta( $comment_id, Comment::META_URI, true ) ) ) {
			return;
		}

		if ( \wp_next_scheduled( 'atmosphere_delete_comment', array( $comment_id ) ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_delete_comment', array( $comment_id ) );
	}

	/**
	 * Schedule an async publication sync.
	 */
	public function schedule_publication_sync(): void {
		if ( ! is_connected() ) {
			return;
		}

		if ( ! \wp_next_scheduled( 'atmosphere_sync_publication' ) ) {
			\wp_schedule_single_event( \time(), 'atmosphere_sync_publication' );
		}
	}

	/**
	 * Cron: proactively refresh the access token.
	 *
	 * Skips when the stored access token still has more than ten
	 * minutes of life on it — the hourly cadence catches up before
	 * any genuine expiry, and an unconditional refresh on every tick
	 * burns a refresh-token rotation that does not need to happen.
	 * Each rotation is also another chance for the dead-holder
	 * scenario (worker dies mid-flight after the auth server has
	 * already rotated) to bite, so refreshing less aggressively is
	 * strictly more reliable on a healthy session.
	 */
	public function cron_refresh_token(): void {
		if ( ! is_connected() ) {
			return;
		}

		$conn = \get_option( 'atmosphere_connection', array() );
		if ( ! empty( $conn['expires_at'] ) && $conn['expires_at'] > \time() + 600 ) {
			return;
		}

		Client::refresh();
	}

	/**
	 * Seed the `atmosphere_long_form_composition` filter from the option.
	 *
	 * Returns the configured strategy when valid; otherwise returns the
	 * incoming `$strategy` (so downstream filters and the `link-card`
	 * default still apply). An invalid stored value is logged at most
	 * once per hour so operators can spot config drift.
	 *
	 * @param string $strategy Strategy passed in by `apply_filters()`.
	 * @return string
	 */
	public static function seed_long_form_composition( string $strategy ): string {
		$option = (string) \get_option( 'atmosphere_long_form_composition', 'link-card' );

		if ( \in_array( $option, self::LONG_FORM_STRATEGIES, true ) ) {
			return $option;
		}

		if ( ! \get_transient( 'atmosphere_invalid_long_form_composition_logged' ) ) {
			debug_log(
				\sprintf(
					'invalid `atmosphere_long_form_composition` option value %s; falling through to default',
					\wp_json_encode( $option )
				)
			);
			\set_transient( 'atmosphere_invalid_long_form_composition_logged', 1, \HOUR_IN_SECONDS );
		}

		return $strategy;
	}

	/**
	 * Register every REST controller on `rest_api_init`.
	 *
	 * Public controllers live in `Atmosphere\Rest`; admin-only ones in
	 * `Atmosphere\Rest\Admin`. Mirrors the wordpress-activitypub plugin's
	 * `rest_init()`.
	 */
	public function register_rest_controllers(): void {
		( new Client_Metadata_Controller() )->register_routes();
		( new Connection_Controller() )->register_routes();
		( new Pre_Publish_Controller() )->register_routes();
		( new Reactions_Controller() )->register_routes();
	}

	/**
	 * React to the per-post share toggle or custom text being changed.
	 *
	 * Fires on `added_/updated_/deleted_post_meta`, after the value is
	 * committed, so the reconcile runs against fresh meta. It schedules the
	 * standard `atmosphere_update_post` reconciliation, which re-checks
	 * {@see is_post_publishable()} at fire time and either publishes, updates,
	 * or deletes the remote records accordingly — turning the toggle off on an
	 * already-shared post removes it from Bluesky.
	 *
	 * This is the robust counterpart to the `transition_post_status` path:
	 * it covers a meta-only save (no status transition) and the race where a
	 * transition-scheduled cron fires before the meta write commits.
	 *
	 * @param int|int[] $meta_id  Meta row ID(s); unused (signatures differ
	 *                            across the three hooks).
	 * @param int       $post_id  Object the meta belongs to.
	 * @param string    $meta_key Meta key that changed.
	 */
	public function on_share_meta_changed( $meta_id, $post_id, $meta_key ): void {
		if ( ! \in_array( $meta_key, array( ATMOSPHERE_META_DISABLED, ATMOSPHERE_META_CUSTOM_TEXT, Threadgate::META_RESTRICTION ), true ) ) {
			return;
		}

		if ( ! is_connected() || ! is_auto_publish_enabled() ) {
			return;
		}

		if ( ! \get_post( (int) $post_id ) instanceof \WP_Post ) {
			return;
		}

		if ( ! \wp_next_scheduled( 'atmosphere_update_post', array( (int) $post_id ) ) ) {
			\wp_schedule_single_event( \time(), 'atmosphere_update_post', array( (int) $post_id ) );
		}
	}

	/**
	 * Register the per-post "share to Bluesky" toggle and custom-text meta.
	 *
	 * Registered for every supported post type with `show_in_rest` so the
	 * block-editor document panel can bind a toggle and a textarea to them
	 * via the core entity store. Writing either requires `edit_post` on the
	 * post.
	 *
	 * Also force-enables `custom-fields` support on each opted-in type:
	 * without it, WordPress drops the editor's meta writes silently (see
	 * the comment in the loop).
	 */
	public function register_share_meta(): void {
		$auth_callback = static function ( $allowed, $meta_key, $post_id ) {
			// Respect any prior denial rather than widening access.
			return $allowed && \current_user_can( 'edit_post', $post_id );
		};

		foreach ( get_supported_post_types() as $post_type ) {
			/*
			 * WordPress only exposes registered meta over REST when the
			 * post type supports custom fields: `WP_REST_Posts_Controller`
			 * gates the write on the schema, so without it the editor's
			 * meta payload is dropped silently on save — the custom text,
			 * the share toggle, and the reply restriction all look saved
			 * and are gone after a reload. Opting a type into sharing
			 * therefore opts it into custom fields too. Side effect: the
			 * (hidden by default) Custom Fields panel becomes available
			 * in that type's editor preferences.
			 */
			if ( ! \post_type_supports( $post_type, 'custom-fields' ) ) {
				\add_post_type_support( $post_type, 'custom-fields' );
			}

			\register_post_meta(
				$post_type,
				ATMOSPHERE_META_DISABLED,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => $auth_callback,
				)
			);

			\register_post_meta(
				$post_type,
				ATMOSPHERE_META_CUSTOM_TEXT,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => $auth_callback,
				)
			);

			\register_post_meta(
				$post_type,
				Threadgate::META_RESTRICTION,
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => \array_merge(
									array( Threadgate::AUDIENCE_NOBODY ),
									\array_keys( Threadgate::audience_rules() )
								),
							),
						),
					),
					'sanitize_callback' => array( Threadgate::class, 'sanitize_restriction' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}
	}

	/**
	 * Register the read-only REST fields backing the editor panel.
	 *
	 * `atmosphere_url` lets the panel show a "View on Bluesky" link once
	 * the post has been shared, without exposing internal AT-URI meta
	 * keys (empty until the post has a Bluesky record).
	 * `atmosphere_publish_error` carries the most recent share failure
	 * (null when the last attempt succeeded) so the panel can tell the
	 * author a share failed instead of the failure vanishing into a
	 * WP_DEBUG-gated log line.
	 * `atmosphere_has_record` answers "is there anything out there to
	 * delete", which is what the removal warning needs. It is deliberately
	 * not derived from `atmosphere_url`: that carries a Bluesky web URL
	 * built from `Post::META_URI` alone, so a document-only site (one
	 * filtering `atmosphere_should_publish_bluesky_post` false) never has
	 * one, while `delete_post()` still removes its `Document::META_URI`
	 * record. Backing the flag with the same `has_post_records()` the
	 * cleanup path calls keeps the warning and the deletion keyed off one
	 * fact. All three are edit-context only.
	 */
	public function register_share_status_field(): void {
		foreach ( get_supported_post_types() as $post_type ) {
			\register_rest_field(
				$post_type,
				'atmosphere_url',
				array(
					'get_callback'    => static fn ( $post_arr ) => post_share_url( (int) $post_arr['id'] ),
					'update_callback' => null,
					'schema'          => array(
						'type'        => 'string',
						'description' => \__( 'The Bluesky web URL for this post, empty until it is shared.', 'atmosphere' ),
						'context'     => array( 'edit' ),
					),
				)
			);

			\register_rest_field(
				$post_type,
				'atmosphere_has_record',
				array(
					'get_callback'    => static function ( $post_arr ) {
						$post = \get_post( (int) $post_arr['id'] );

						return $post instanceof \WP_Post && self::has_post_records( $post );
					},
					'update_callback' => null,
					'schema'          => array(
						'type'        => 'boolean',
						'description' => \__( 'Whether this post has records on the PDS that a cleanup would remove.', 'atmosphere' ),
						'context'     => array( 'edit' ),
					),
				)
			);

			\register_rest_field(
				$post_type,
				'atmosphere_publish_error',
				array(
					'get_callback'    => static fn ( $post_arr ) => self::get_publish_error( (int) $post_arr['id'] ),
					'update_callback' => null,
					'schema'          => array(
						'type'        => array( 'object', 'null' ),
						'description' => \__( 'The most recent Bluesky sharing failure for this post, null when the last attempt succeeded.', 'atmosphere' ),
						'context'     => array( 'edit' ),
						'properties'  => array(
							'code'            => array(
								'type'        => 'string',
								'description' => \__( 'Machine-readable failure code.', 'atmosphere' ),
							),
							'message'         => array(
								'type'        => 'string',
								'description' => \__( 'Human-readable failure message.', 'atmosphere' ),
							),
							'retrying'        => array(
								'type'        => 'boolean',
								'description' => \__( 'Whether another automatic attempt is scheduled.', 'atmosphere' ),
							),
							'needs_reconnect' => array(
								'type'        => 'boolean',
								'description' => \__( 'Whether reconnecting the Bluesky account is still required before sharing can succeed.', 'atmosphere' ),
							),
							'time'            => array(
								'type'        => 'integer',
								'description' => \__( 'Unix timestamp of the failed attempt.', 'atmosphere' ),
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Queue a share of one post through the standard publish worker.
	 *
	 * Owns the hook name, the argument shape, and the duplicate rule, so a
	 * caller does not have to know any of them. The worker itself decides
	 * between a first publish and an update, re-checks visibility at fire
	 * time, logs failures and schedules retries.
	 *
	 * The `wp_next_scheduled()` check is load-bearing beyond the duplicate
	 * protection core gives for identical events within ten minutes: a
	 * failed attempt is retried on a ladder that reaches fifteen minutes
	 * and beyond, and a second worker must not be queued alongside a retry
	 * that is still pending.
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Post to share.
	 * @return bool True when a worker was queued, false when one was already pending.
	 */
	public static function queue_post_share( int $post_id ): bool {
		$args = array( $post_id );

		if ( \wp_next_scheduled( 'atmosphere_publish_post', $args ) ) {
			return false;
		}

		return (bool) \wp_schedule_single_event( \time(), 'atmosphere_publish_post', $args );
	}

	/**
	 * Shape the stored publish failure for display.
	 *
	 * Shared by the editor panel's `atmosphere_publish_error` REST field
	 * and the posts-list column, so both describe a failure the same way.
	 *
	 * The stored code says whether the failure was reconnect-class; the
	 * live connection check drops the flag once the operator has
	 * reconnected, so a stale per-post error can't keep claiming the site
	 * is disconnected. The stored message of a reconnect-class failure is
	 * that same claim in prose ("Reconnect your Bluesky account …"), so it
	 * is suppressed on the same condition: the surface would otherwise say
	 * "update the post to try again" and "reconnect your account" at once.
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Failure details, or null when the last attempt succeeded.
	 */
	public static function get_publish_error( int $post_id ): ?array {
		$error = \get_post_meta( $post_id, self::META_LAST_PUBLISH_ERROR, true );

		if ( ! \is_array( $error ) || empty( $error['code'] ) ) {
			return null;
		}

		$reconnect_class = Client::is_reconnect_error( (string) $error['code'] );
		$needs_reconnect = $reconnect_class && ! is_connected();

		return array(
			'code'            => (string) $error['code'],
			'message'         => $reconnect_class && ! $needs_reconnect
				? ''
				: (string) ( $error['message'] ?? '' ),
			'retrying'        => ! empty( $error['retrying'] ),
			'needs_reconnect' => $needs_reconnect,
			'time'            => (int) ( $error['time'] ?? 0 ),
		);
	}


	/**
	 * Register async action hooks (called by WP-Cron).
	 */
	public static function register_async_hooks(): void {
		/*
		 * Publish/update cron callbacks re-check post visibility.
		 * A user (or downstream filter) can password-protect a post,
		 * unpublish it, or disable its post type after a cron event was
		 * queued, and we must not still publish it.
		 *
		 * The delete callback uses the inverse gate: if the post has
		 * become publishable again, update the existing records instead
		 * of deleting them; otherwise clean up any existing remote records.
		 */
		\add_action(
			'atmosphere_publish_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( ! $post ) {
					return;
				}
				if ( is_post_publishable( $post ) ) {
					$result = self::has_post_records( $post )
						? Publisher::update_post( $post )
						: Publisher::publish_post( $post );
					self::log_cron_error( 'publish_post', $post_id, $result );
					self::maybe_schedule_publish_retry( 'atmosphere_publish_post', $post_id, $result );
					if ( ! \is_wp_error( $result ) ) {
						self::clear_visibility_cleanup_marker( $post );
					}
					return;
				}
				if ( self::has_post_records( $post ) ) {
					self::mark_visibility_cleanup( $post );
					self::log_cron_error( 'delete_post', $post_id, Publisher::delete_post( $post ) );
				}
			}
		);

		\add_action(
			'atmosphere_update_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( ! $post ) {
					return;
				}
				if ( is_post_publishable( $post ) ) {
					$result = self::has_post_records( $post ) || ! self::has_visibility_cleanup_marker( $post )
						? Publisher::update_post( $post )
						: Publisher::publish_post( $post );
					self::log_cron_error( 'update_post', $post_id, $result );
					self::maybe_schedule_publish_retry( 'atmosphere_update_post', $post_id, $result );
					if ( ! \is_wp_error( $result ) ) {
						self::clear_visibility_cleanup_marker( $post );
					}
					return;
				}
				if ( self::has_post_records( $post ) ) {
					self::mark_visibility_cleanup( $post );
					self::log_cron_error( 'delete_post', $post_id, Publisher::delete_post( $post ) );
				}
			}
		);

		\add_action(
			'atmosphere_delete_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post ) {
					if ( is_post_publishable( $post ) ) {
						$result = self::has_post_records( $post ) || ! self::has_visibility_cleanup_marker( $post )
							? Publisher::update_post( $post )
							: Publisher::publish_post( $post );
						self::log_cron_error( 'delete_post_publishable_reconcile', $post_id, $result );

						/*
						 * Same lifecycle as the publish/update workers: a
						 * successful reconcile clears the retry counter and
						 * the stale failure record, and a transient failure
						 * re-queues (as an update — the worker re-checks
						 * state when the retry fires).
						 */
						self::maybe_schedule_publish_retry( 'atmosphere_update_post', $post_id, $result );
						if ( ! \is_wp_error( $result ) ) {
							self::clear_visibility_cleanup_marker( $post );
						}
						return;
					}
					if ( self::has_post_records( $post ) ) {
						self::mark_visibility_cleanup( $post );
						self::log_cron_error( 'delete_post', $post_id, Publisher::delete_post( $post ) );
					}
				}
			}
		);

		\add_action(
			'atmosphere_sync_publication',
			static function (): void {
				// Respect connection-only mode: no automatic publication write
				// when a host owns the connection (see is_publication_sync_enabled()).
				if ( is_publication_sync_enabled() ) {
					Publisher::sync_publication();
				}
			}
		);

		\add_action(
			'atmosphere_delete_records',
			static function ( $bsky_tids, string $doc_tid, $comment_tids = array(), string $threadgate_tid = '', string $bsky_origin_did = '', string $doc_origin_did = '' ): void {
				/*
				 * delete_post_by_tids() drops the comment TIDs itself when
				 * comment publishing is disabled at execution time. The
				 * trailing args all default so an event queued before they
				 * existed still fires cleanly: no threadgate to remove, and
				 * the wrong-repo-delete guard disabled for that record. The
				 * threadgate arg comes first because it shipped first; the
				 * positions of already-queued events must not move.
				 */
				$comment_tids = \is_array( $comment_tids ) ? $comment_tids : array();
				$result       = Publisher::delete_post_by_tids( $bsky_tids, $doc_tid, $comment_tids, $threadgate_tid, $bsky_origin_did, $doc_origin_did );

				if ( \is_wp_error( $result ) ) {
					/*
					 * One-shot cron event with no retry: dropping this error
					 * would orphan every record in the cascade (root + thread
					 * replies + outbound comment replies + document) on the
					 * PDS with no operator-visible breadcrumb.
					 */
					debug_log(
						\sprintf(
							'delete_records failed (bsky=%d, doc=%s, comments=%d): %s — %s',
							\is_array( $bsky_tids ) ? \count( $bsky_tids ) : (int) ! empty( $bsky_tids ),
							$doc_tid ? 'yes' : 'no',
							\count( $comment_tids ),
							$result->get_error_code(),
							$result->get_error_message()
						)
					);
				}
			},
			10,
			6
		);

		/*
		 * Cron handlers re-evaluate eligibility at fire time so state
		 * changes between enqueue and execution (approve→unapprove,
		 * unapprove→re-approve, user deleted, etc.) are respected. The
		 * separate transition hooks only schedule; they cannot cancel
		 * an already-queued event, and schedule_comment_delete itself
		 * bails when META_URI is absent (which it is pre-publish), so
		 * without these guards a pre-cron unapprove would still
		 * publish, and a pre-cron re-approve would still delete.
		 *
		 * Publisher WP_Error returns are logged rather than silently
		 * dropped so a flaky PDS window or an expired refresh token
		 * leaves a breadcrumb operators can find.
		 */
		\add_action(
			'atmosphere_publish_comment',
			static function ( int $comment_id ): void {
				$comment = \get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}
				if ( ! self::should_publish_comment( $comment ) ) {
					return;
				}
				if ( self::defer_when_parent_pending( $comment ) ) {
					return;
				}
				if ( ! self::parent_has_bsky_representation( $comment ) ) {
					/*
					 * Parent is local-only: anonymous WP commenter, an
					 * ineligible comment that will never publish, or a
					 * federation source other than bsky. Publishing the
					 * reply anyway would either fail at strongRef
					 * construction or fall back to a top-level reply on
					 * the post (losing the WP thread context). Skip
					 * instead, and clear the deferral counter so a
					 * future re-publish (e.g. if the parent gains a URI
					 * later) gets a fresh budget.
					 */
					\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );
					return;
				}
				\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );

				$result = Publisher::publish_comment( $comment );
				self::log_cron_error( 'publish_comment', $comment_id, $result );

				if ( ! \is_wp_error( $result ) ) {
					self::reconcile_comment_after_publish( $comment_id );
				}
			}
		);

		\add_action(
			'atmosphere_update_comment',
			static function ( int $comment_id ): void {
				$comment = \get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}
				if ( ! self::should_publish_comment( $comment ) ) {
					return;
				}

				$result = Publisher::update_comment( $comment );
				self::log_cron_error( 'update_comment', $comment_id, $result );
			}
		);

		\add_action(
			'atmosphere_delete_comment',
			static function ( int $comment_id ): void {
				if ( ! is_comment_publishing_enabled() ) {
					return;
				}

				$comment = \get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}
				// If the comment is eligible again by the time cron
				// fires, another transition has superseded the delete.
				if ( self::should_publish_comment( $comment ) ) {
					return;
				}

				$result = Publisher::delete_comment( $comment );
				self::log_cron_error( 'delete_comment', $comment_id, $result );
			}
		);

		\add_action(
			'atmosphere_delete_comment_record',
			static function ( string $tid, string $origin_did = '' ): void {
				if ( ! is_comment_publishing_enabled() || '' === $tid ) {
					return;
				}

				$result = Publisher::delete_comment_by_tid( $tid, $origin_did );

				if ( \is_wp_error( $result ) ) {
					// Worst-case path: the WP comment row is already gone,
					// so operators need the TID to clean up the orphan
					// record manually.
					debug_log(
						\sprintf(
							'delete_comment_record tid=%s failed: %s — %s',
							$tid,
							$result->get_error_code(),
							$result->get_error_message()
						)
					);
				}
			},
			10,
			2
		);
	}

	/**
	 * Defer a child comment publish when its parent is eligible but
	 * has not published to the PDS yet.
	 *
	 * Comments are scheduled as independent single events with no
	 * dependency ordering: if a user approves a parent and its reply
	 * together, the child's cron event can fire first and see
	 * `resolve_parent_ref()` return null. This defers the child a
	 * short interval (up to PARENT_DEFER_MAX_ATTEMPTS hops) so the
	 * parent has time to publish first. After the cap the cron
	 * handler's {@see Atmosphere::parent_has_bsky_representation()}
	 * check skips the child entirely rather than letting
	 * {@see Comment::build_reply_ref()} fall back to a top-level
	 * reply on the post — losing the WP thread context.
	 *
	 * @param \WP_Comment $comment Comment being published.
	 * @return bool True when the publish was deferred, false to proceed now.
	 */
	private static function defer_when_parent_pending( \WP_Comment $comment ): bool {
		$parent_id = (int) $comment->comment_parent;

		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = \get_comment( $parent_id );

		if ( ! $parent instanceof \WP_Comment ) {
			return false;
		}

		if ( ! self::should_publish_comment( $parent ) ) {
			// Parent is ineligible (anon, rejected, etc.). No reason to
			// defer — it will never gain a bsky URI. The subsequent
			// `parent_has_bsky_representation()` check in the cron
			// handler will skip the publish entirely so we don't
			// promote a nested WP reply into a confusing top-level
			// bsky reply on the post.
			return false;
		}

		if ( ! empty( \get_comment_meta( $parent_id, Comment::META_URI, true ) ) ) {
			// Parent is already published — nothing to defer for.
			return false;
		}

		$comment_id = (int) $comment->comment_ID;
		$attempts   = (int) \get_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS, true );

		if ( $attempts >= self::PARENT_DEFER_MAX_ATTEMPTS ) {
			// Give up on the deferral budget; clear the counter so a
			// future re-publish gets a fresh budget. The subsequent
			// `parent_has_bsky_representation()` check skips the publish
			// rather than letting `build_reply_ref()` fall back to a
			// top-level reply on the post, which would lose the WP
			// thread context.
			\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );
			return false;
		}

		\update_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS, $attempts + 1 );
		\wp_schedule_single_event(
			\time() + self::PARENT_DEFER_DELAY_SECONDS,
			'atmosphere_publish_comment',
			array( $comment_id )
		);

		return true;
	}

	/**
	 * Whether the comment's immediate WP parent has an AT Protocol
	 * strongRef the reply record can thread under.
	 *
	 * Mirrors the exact requirements of {@see Comment::resolve_parent_ref()}:
	 * a strongRef needs BOTH `uri` and `cid`. Half-state rows (URI
	 * present but CID missing, or `META_PROTOCOL = atproto` without
	 * the federated CID alongside) would let `resolve_parent_ref()`
	 * fall through and `build_reply_ref()` substitute the post root,
	 * silently promoting the nested reply to a top-level post — the
	 * exact bug this check is here to prevent.
	 *
	 * Returns true when:
	 *
	 * - The comment has no parent (top-level reply to the post). The
	 *   post itself has a bsky record; the publish path threads against
	 *   that root.
	 * - The parent comment carries both {@see Comment::META_URI} and
	 *   {@see Comment::META_CID} — the plugin already published the
	 *   parent to the PDS.
	 * - The parent comment is marked as ingested by
	 *   {@see Reaction_Sync} (`META_PROTOCOL = atproto`) AND carries
	 *   both `META_SOURCE_ID` (URI) and `META_BSKY_CID`.
	 *
	 * Only the immediate parent is checked: any comment that passes
	 * this gate was itself only publishable through the same gate, so
	 * by induction every WP ancestor is also threadable.
	 *
	 * Known legacy edge case: sites that ran a pre-fix version of the
	 * plugin may have "demoted" comments on bsky — comments whose
	 * original WP parent was local-only but which the old root-fallback
	 * still pushed to bsky as top-level replies under the post. Their
	 * rows now carry valid `META_URI` / `META_CID`, so new replies to
	 * them pass this gate even though the deeper WP ancestor chain is
	 * incomplete. The strongRef itself is still valid (the demoted
	 * comment is on bsky); the bsky-side view simply omits the
	 * pre-existing local-only ancestor, which mirrors what the WP user
	 * sees with the local-only commenter anyway. No further migration
	 * is planned for those legacy rows.
	 *
	 * @param \WP_Comment $comment Comment about to be published.
	 * @return bool
	 */
	private static function parent_has_bsky_representation( \WP_Comment $comment ): bool {
		$parent_id = (int) $comment->comment_parent;

		if ( $parent_id <= 0 ) {
			return true;
		}

		$local_uri = \get_comment_meta( $parent_id, Comment::META_URI, true );
		$local_cid = \get_comment_meta( $parent_id, Comment::META_CID, true );
		if ( ! empty( $local_uri ) && ! empty( $local_cid ) ) {
			return true;
		}

		if ( 'atproto' !== \get_comment_meta( $parent_id, Reaction_Sync::META_PROTOCOL, true ) ) {
			return false;
		}

		$federated_uri = \get_comment_meta( $parent_id, Reaction_Sync::META_SOURCE_ID, true );
		$federated_cid = \get_comment_meta( $parent_id, Reaction_Sync::META_BSKY_CID, true );

		return ! empty( $federated_uri ) && ! empty( $federated_cid );
	}

	/**
	 * Log a WP_Error returned from a comment cron Publisher call.
	 *
	 * `wp_schedule_single_event` does not retry, so a silent drop
	 * here would lose the breadcrumb operators need to diagnose
	 * auth, transport, or PDS-side failures.
	 *
	 * @param string $op        Operation name.
	 * @param int    $object_id Post or comment ID.
	 * @param mixed  $result    Publisher call result.
	 */
	public static function log_cron_error( string $op, int $object_id, $result ): void {
		if ( ! \is_wp_error( $result ) ) {
			return;
		}

		/*
		 * PDS error messages flow through `WP_Error::get_error_message()`
		 * via `API::apply_writes` and can include attacker-controlled
		 * bytes (CRLF, ANSI escapes, fake `[atmosphere]` prefixes that
		 * imitate other log lines). `debug_log()` collapses CRLF before
		 * writing, so a misbehaving PDS cannot smuggle multiline noise
		 * into log-shipping pipelines that parse line prefixes.
		 */
		debug_log(
			\sprintf(
				'%s %d failed: %s — %s',
				$op,
				$object_id,
				$result->get_error_code(),
				$result->get_error_message()
			)
		);
	}

	/**
	 * Public alias for {@see Atmosphere::log_cron_error()} used by the
	 * Publisher reconcile path. Routes the cleanup-delete failure
	 * through a stable op label (`reconcile_cleanup`) so monitors do
	 * not confuse it with the original publish failure.
	 *
	 * @param int   $post_id Post ID whose reconcile cleanup failed.
	 * @param mixed $result  `WP_Error` from `Publisher::delete_post()`.
	 */
	public static function log_reconcile_cleanup_error( int $post_id, $result ): void {
		self::log_cron_error( 'reconcile_cleanup', $post_id, $result );
	}

	/**
	 * Re-queue a failed publish/update cron worker with backoff.
	 *
	 * Mirrors the comment parent-defer pattern ({@see self::defer_for_parent()}):
	 * a per-object attempt counter in meta, a bounded ladder, and a
	 * one-shot re-schedule of the same hook + args. The worker re-checks
	 * post state when the retry fires, so a post that was unpublished or
	 * disabled in the meantime routes to cleanup instead of publishing.
	 *
	 * Success and permanent failures clear the counter so the next
	 * fresh save starts with a full retry budget.
	 *
	 * @param string $hook    Cron hook to re-schedule (`atmosphere_publish_post` or `atmosphere_update_post`).
	 * @param int    $post_id Post ID the worker ran for.
	 * @param mixed  $result  Publisher result: array on success, `WP_Error` on failure.
	 */
	private static function maybe_schedule_publish_retry( string $hook, int $post_id, $result ): void {
		if ( ! \is_wp_error( $result ) ) {
			\delete_post_meta( $post_id, self::META_PUBLISH_RETRIES );
			\delete_post_meta( $post_id, self::META_LAST_PUBLISH_ERROR );
			return;
		}

		if ( ! self::is_transient_publish_error( $result ) ) {
			self::record_publish_error( $post_id, $result, false );
			\delete_post_meta( $post_id, self::META_PUBLISH_RETRIES );
			return;
		}

		/**
		 * Filters the backoff ladder for transient publish/update failures.
		 *
		 * One entry per retry, in seconds — the ladder's length IS the
		 * retry budget. Return an empty array to disable retries, or a
		 * longer array to raise the budget (the same knob covers both,
		 * so the delay schedule and the attempt cap cannot contradict
		 * each other).
		 *
		 * @since 2.0.0
		 *
		 * @param int[] $delays Retry delays in seconds. Default 60, 300, 900.
		 */
		$delays = \apply_filters( 'atmosphere_publish_retry_delays', self::PUBLISH_RETRY_DELAYS );
		$delays = \array_values(
			\array_filter(
				\array_map( 'intval', (array) $delays ),
				static fn( int $delay ): bool => $delay > 0
			)
		);

		$attempts = (int) \get_post_meta( $post_id, self::META_PUBLISH_RETRIES, true );

		if ( $attempts >= \count( $delays ) ) {
			/*
			 * Ladder exhausted. Clear the counter so a future fresh save
			 * gets a new budget, and leave a breadcrumb — this is the
			 * point where a post has definitively failed to reach the
			 * PDS despite retries. No breadcrumb when the filter
			 * disabled retries outright: "giving up after 0 retries"
			 * would misread as a failure of the ladder the operator
			 * deliberately switched off.
			 */
			self::record_publish_error( $post_id, $result, false );
			\delete_post_meta( $post_id, self::META_PUBLISH_RETRIES );

			if ( ! empty( $delays ) ) {
				debug_log(
					\sprintf(
						'%s %d: giving up after %d retries (%s)',
						$hook,
						$post_id,
						$attempts,
						$result->get_error_code()
					)
				);
			}
			return;
		}

		self::record_publish_error( $post_id, $result, true );
		\update_post_meta( $post_id, self::META_PUBLISH_RETRIES, $attempts + 1 );
		\wp_schedule_single_event(
			\time() + self::publish_retry_delay( $delays[ $attempts ], $result ),
			$hook,
			array( $post_id )
		);
	}

	/**
	 * Resolve how long to wait before the next publish attempt.
	 *
	 * Normally this is just the ladder's own step. The exception is a
	 * rate-limited PDS: it tells us exactly when the window rolls over
	 * (`API::rate_limited_error()` carries that as `retry_after`), and
	 * retrying before then is guaranteed to burn a rung of the ladder on
	 * an identical 429. So the longer of the two wins.
	 *
	 * Only the PDS-supplied wait is capped, and at a day rather than an
	 * hour: Bluesky budgets repo writes per day as well as per hour, so
	 * a daily-limit 429 legitimately reports a reset most of a day out,
	 * and an hour-capped wait would spend every rung of the ladder
	 * inside a window that is still closed. The cap is there so a
	 * malformed or hostile `ratelimit-reset` cannot park a queued
	 * publish weeks into the future; it must never shorten the ladder's
	 * own step, which is why it applies to the header value alone.
	 *
	 * @since 2.2.0
	 *
	 * @param int       $delay Ladder delay for this attempt, in seconds.
	 * @param \WP_Error $error The failure being retried.
	 * @return int Seconds to wait.
	 */
	private static function publish_retry_delay( int $delay, \WP_Error $error ): int {
		$data        = $error->get_error_data();
		$retry_after = \is_array( $data ) && isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0;

		if ( $retry_after <= 0 ) {
			return $delay;
		}

		/*
		 * Pad by a second so the retry lands just after the window
		 * rolls over rather than exactly on the boundary.
		 */
		return \max( $delay, \min( $retry_after + 1, DAY_IN_SECONDS ) );
	}

	/**
	 * Persist a publish failure to post meta for the editor to surface.
	 *
	 * The message flows from `WP_Error::get_error_message()` and can carry
	 * PDS-supplied bytes, so it is sanitized and truncated before storage
	 * — same caution as `log_cron_error()`, but for a value that ends up
	 * rendered in the block editor rather than a log line.
	 *
	 * @param int       $post_id  Post the attempt ran for.
	 * @param \WP_Error $error    The failure.
	 * @param bool      $retrying Whether the backoff ladder scheduled another attempt.
	 */
	private static function record_publish_error( int $post_id, \WP_Error $error, bool $retrying ): void {
		\update_post_meta(
			$post_id,
			self::META_LAST_PUBLISH_ERROR,
			array(
				'code'     => (string) $error->get_error_code(),
				'message'  => truncate_text( sanitize_text( $error->get_error_message() ), 300 ),
				'retrying' => $retrying,
				'time'     => \time(),
			)
		);
	}

	/**
	 * Whether a publish failure is worth retrying.
	 *
	 * Retry-by-default with a bounded ladder: a wrongly-retried
	 * permanent error costs at most three extra requests, while a
	 * wrongly-dropped transient error silently loses the post. Only
	 * failures that are deterministic — locally-generated preconditions
	 * or a PDS 4xx that will reject the identical payload again — are
	 * excluded.
	 *
	 * @param \WP_Error $error Failure returned by the Publisher.
	 * @return bool True when a retry has a chance of succeeding.
	 */
	private static function is_transient_publish_error( \WP_Error $error ): bool {
		/*
		 * Reconnect-class failures are permanent by definition; the code
		 * list lives in {@see Client::is_reconnect_error()}, next to the
		 * paths that mint those codes, so each code is declared once.
		 */
		if ( Client::is_reconnect_error( (string) $error->get_error_code() ) ) {
			return false;
		}

		$permanent_codes = array(
			'atmosphere_post_not_publishable',
			'atmosphere_missing_tid',
			'atmosphere_invalid_pre_apply_writes_return',
			'atmosphere_invalid_pre_apply_writes_response',
			'atmosphere_invalid_pre_upload_blob_return',
			'atmosphere_did_mismatch',

			/*
			 * Never retry a failed thread rollback: the orphan manifest
			 * records live partial records on the PDS, and a retried
			 * publish would mint fresh TIDs next to them — a duplicate,
			 * user-visible copy of the post. This state needs operator
			 * attention (see Post::META_ORPHAN_RECORDS), not another
			 * attempt.
			 */
			'atmosphere_thread_rollback_failed',
		);

		if ( \in_array( $error->get_error_code(), $permanent_codes, true ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = \is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		/*
		 * No status means the request never completed (DNS, TLS,
		 * timeout) — the classic transient class. 408/429/5xx are the
		 * server-side equivalents. Any other 4xx is a deterministic
		 * rejection of this exact payload and would fail identically
		 * on every attempt.
		 */
		if ( 0 === $status ) {
			return true;
		}

		return 408 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Roll back a successful publish if the comment became ineligible
	 * during the in-flight applyWrites.
	 *
	 * The race: `Comment::get_rkey()` persists META_TID before the API
	 * call, and META_URI is only written after success. Both
	 * `schedule_comment_delete` and `on_comment_before_delete` require
	 * META_URI to schedule cleanup. A moderator who deletes or
	 * unapproves the comment while applyWrites is in flight therefore
	 * leaves a live Bluesky reply with no scheduled cleanup once
	 * `store_comment_result()` finally writes META_URI.
	 *
	 * Re-checking eligibility after publish closes that race. If the
	 * comment is gone or no longer eligible, we clear the meta we just
	 * wrote and schedule the same TID-only delete event the
	 * permanent-delete path uses, so transient PDS failures retry via
	 * the standard cleanup channel rather than getting dropped here.
	 *
	 * @param int $comment_id Comment ID just published.
	 */
	private static function reconcile_comment_after_publish( int $comment_id ): void {
		/*
		 * A switch flipped while applyWrites was in flight cannot undo the
		 * completed request. Keep the returned record metadata intact and do
		 * not enqueue a compensating delete while outgoing writes are off.
		 */
		if ( ! is_comment_publishing_enabled() ) {
			return;
		}

		/*
		 * Drop the in-process `WP_Comment` cache so a concurrent web
		 * request that just unapproved or deleted this comment is
		 * visible to the reconcile re-check. Same exposure as the
		 * publisher reconcile path on installs without a persistent
		 * object cache: without this invalidation, a moderator's
		 * mid-publish unapprove races the post-publish read and the
		 * Bluesky reply stays live with no cleanup scheduled.
		 */
		\clean_comment_cache( $comment_id );
		$fresh = \get_comment( $comment_id );

		if ( $fresh instanceof \WP_Comment && self::should_publish_comment( $fresh ) ) {
			return;
		}

		$tid = (string) \get_comment_meta( $comment_id, Comment::META_TID, true );
		// Capture the origin DID before clearing meta so the TID-only
		// cleanup event can guard against a wrong-repo delete.
		$origin_did = (string) \get_comment_meta( $comment_id, Comment::META_DID, true );

		Publisher::clear_comment_record_meta( $comment_id );

		if ( '' === $tid ) {
			return;
		}

		$args = array( $tid, $origin_did );

		if ( \wp_next_scheduled( 'atmosphere_delete_comment_record', $args ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_delete_comment_record', $args );
	}
}
