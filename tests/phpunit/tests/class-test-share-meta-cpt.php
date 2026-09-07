<?php
/**
 * Tests for the per-post share meta on custom post types.
 *
 * WordPress only exposes registered post meta over REST when the post
 * type supports custom fields; `WP_REST_Posts_Controller` gates the
 * write on the schema, so without that support the block editor's meta
 * payload is dropped silently. For an opted-in custom post type that
 * means the custom Bluesky text, the "share this post" toggle, and the
 * reply restriction all LOOK saved in the editor and are gone after a
 * reload. The registration must therefore opt the type into custom
 * fields itself.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Atmosphere;
use Atmosphere\Transformer\Threadgate;

/**
 * Share-meta-on-CPT tests.
 */
class Test_Share_Meta_Cpt extends \WP_UnitTestCase {

	/**
	 * The Atmosphere instance under test.
	 *
	 * @var \Atmosphere\Atmosphere
	 */
	private $atmosphere;

	/**
	 * Register the reporter's exact post type shape: everything except
	 * custom-fields support.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->atmosphere = new Atmosphere();

		\register_post_type(
			'atm_case',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt' ),
			)
		);
		\add_post_type_support( 'atm_case', 'atmosphere' );

		$this->atmosphere->register_share_meta();

		/*
		 * The suite shares one REST server; if an earlier test built it,
		 * this post type has no route yet. Rebuild lazily on first use.
		 */
		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * Drop the post type again.
	 */
	public function tear_down(): void {
		\unregister_post_type( 'atm_case' );
		\wp_set_current_user( 0 );

		// Later tests must rebuild the routes without this post type.
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * All three per-post settings must survive a REST save on a custom
	 * post type that did not declare custom-fields support itself.
	 */
	public function test_share_meta_round_trips_on_a_cpt_without_custom_fields() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/atm_case' );
		$request->set_body_params(
			array(
				'title'  => 'A case',
				'status' => 'publish',
				'meta'   => array(
					'atmosphere_custom_text'     => 'Hand-written for Bluesky.',
					'atmosphere_disabled'        => true,
					Threadgate::META_RESTRICTION => array( 'nobody' ),
				),
			)
		);

		$response = \rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );

		$post_id = $response->get_data()['id'];

		$this->assertSame( 'Hand-written for Bluesky.', \get_post_meta( $post_id, ATMOSPHERE_META_CUSTOM_TEXT, true ), 'The custom text must persist, not just render in the editor.' );
		$this->assertTrue( (bool) \get_post_meta( $post_id, ATMOSPHERE_META_DISABLED, true ), 'The do-not-share choice must persist; losing it silently federates the post.' );
		$this->assertSame( array( 'nobody' ), \get_post_meta( $post_id, Threadgate::META_RESTRICTION, true ), 'The reply restriction is a safety setting and must persist.' );
	}

	/**
	 * The mechanism: registration opts the supported type into custom
	 * fields, which is what opens the REST meta schema.
	 */
	public function test_registration_adds_custom_fields_support() {
		$this->assertTrue( \post_type_supports( 'atm_case', 'custom-fields' ) );
	}
}
