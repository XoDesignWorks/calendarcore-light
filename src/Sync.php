<?php
/**
 * Keeps postmeta, the occurrence table and the caches in sync.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Single place where writes to an event trigger the derived data.
 */
class Sync {

	/**
	 * Event IDs already processed in this request, avoids double work when
	 * both save_post and the REST hook fire.
	 *
	 * @var array<int,bool>
	 */
	private $processed = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'save_post_' . XODW_CC_POST_TYPE, array( $this, 'on_save' ), 20, 3 );
		add_action( 'rest_after_insert_' . XODW_CC_POST_TYPE, array( $this, 'on_rest_save' ), 20, 1 );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'on_delete' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_untrash' ), 10, 1 );

		// Cache invalidation.
		add_action( 'xodw_cc_meta_normalized', array( $this, 'flush' ) );
		add_action( 'created_term', array( $this, 'flush_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'flush_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'flush_term' ), 10, 3 );
	}

	/**
	 * Rebuilds the derived data of an event after a classic save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function on_save( $post_id, $post, $update = false ) {
		unset( $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// Meta arrives after the post in REST writes; rest_after_insert runs then.
			return;
		}

		$this->rebuild( (int) $post_id );
	}

	/**
	 * Rebuilds after a block editor / REST save.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function on_rest_save( $post ) {
		if ( $post instanceof \WP_Post ) {
			$this->rebuild( (int) $post->ID );
		}
	}

	/**
	 * Handles scheduled posts going live and events being unpublished.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_status_change( $new_status, $old_status, $post ) {
		if ( ! $post instanceof \WP_Post || XODW_CC_POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status ) {
			return;
		}

		$this->processed = array();
		$this->rebuild( (int) $post->ID );
	}

	/**
	 * Drops the occurrences of a deleted or trashed event.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_delete( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || XODW_CC_POST_TYPE !== $post->post_type ) {
			return;
		}

		Instances::delete_event( (int) $post_id );
		$this->flush();
	}

	/**
	 * Restores the occurrences of an untrashed event.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_untrash( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || XODW_CC_POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->processed = array();
		$this->rebuild( (int) $post_id );
	}

	/**
	 * Normalises the meta and regenerates the occurrences once per request.
	 *
	 * @param int $event_id Event post ID.
	 * @return void
	 */
	private function rebuild( $event_id ) {
		if ( isset( $this->processed[ $event_id ] ) ) {
			return;
		}

		$this->processed[ $event_id ] = true;

		EventMeta::normalize( $event_id );
		( new RecurringEngine() )->generate( $event_id, true );
	}

	/**
	 * Invalidates the query cache.
	 *
	 * @return void
	 */
	public function flush() {
		xodw_cc_flush_cache();
	}

	/**
	 * Invalidates the cache when a venue or organizer term changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function flush_term( $term_id, $tt_id, $taxonomy ) {
		unset( $term_id, $tt_id );

		if ( in_array( $taxonomy, array( XODW_CC_TAX_VENUE, XODW_CC_TAX_ORGANIZER ), true ) ) {
			$this->flush();
		}
	}
}
