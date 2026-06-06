<?php
/**
 * Nexus by Therum — background job queue.
 *
 * Thin wrapper over Action Scheduler when available (bundled with WC and
 * most modern WP installs), or wp_cron as a fallback. Used by:
 *   - inbound webhook processing (queue heavy work off the request thread)
 *   - background credential re-validation
 *   - audit log pruning
 *   - any future async work
 *
 * Public API:
 *   nexus_queue_async( $hook, $args = [], $delay_seconds = 0 ): bool
 *   nexus_queue_recurring( $hook, $args, $interval ): bool
 *   nexus_queue_pending( $hook = '' ): array
 *   nexus_queue_cancel( $hook, $args = [] ): bool
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_QUEUE_GROUP = 'nexus';

function nexus_queue_has_action_scheduler(): bool {
	return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
}

/**
 * Schedule a one-shot job. Hook will fire on a background request with
 * the given args. Always returns true on enqueue (the underlying impl
 * may queue silently). Use $delay_seconds=0 for "as soon as possible."
 */
function nexus_queue_async( string $hook, array $args = [], int $delay_seconds = 0 ): bool {
	if ( nexus_queue_has_action_scheduler() ) {
		if ( $delay_seconds <= 0 ) {
			as_enqueue_async_action( $hook, $args, NEXUS_QUEUE_GROUP );
		} else {
			as_schedule_single_action( time() + $delay_seconds, $hook, $args, NEXUS_QUEUE_GROUP );
		}
		return true;
	}
	// Fallback to wp_cron. Less reliable (only fires on traffic) but
	// keeps the interface consistent — callers don't have to care.
	return (bool) wp_schedule_single_event( time() + max( 0, $delay_seconds ), $hook, $args );
}

/**
 * Schedule a recurring job. $interval is seconds between fires.
 * Returns true if a fresh schedule was created (or it already exists).
 */
function nexus_queue_recurring( string $hook, array $args, int $interval ): bool {
	if ( nexus_queue_has_action_scheduler() ) {
		if ( ! as_next_scheduled_action( $hook, $args, NEXUS_QUEUE_GROUP ) ) {
			as_schedule_recurring_action( time() + $interval, $interval, $hook, $args, NEXUS_QUEUE_GROUP );
		}
		return true;
	}
	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_event( time() + $interval, _nexus_queue_wp_cron_schedule( $interval ), $hook, $args );
	}
	return true;
}

/**
 * Pending jobs for inspection / debug surfaces. Empty array when
 * the underlying impl has no introspection (wp_cron fallback).
 */
function nexus_queue_pending( string $hook = '' ): array {
	if ( ! nexus_queue_has_action_scheduler() ) return [];
	$args = [ 'group' => NEXUS_QUEUE_GROUP, 'status' => 'pending', 'per_page' => 50 ];
	if ( $hook !== '' ) $args['hook'] = $hook;
	return function_exists( 'as_get_scheduled_actions' )
		? as_get_scheduled_actions( $args, 'ARRAY_A' )
		: [];
}

function nexus_queue_cancel( string $hook, array $args = [] ): bool {
	if ( nexus_queue_has_action_scheduler() ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, NEXUS_QUEUE_GROUP );
			return true;
		}
	}
	return (bool) wp_clear_scheduled_hook( $hook, $args );
}

/**
 * Register a custom wp_cron schedule when Action Scheduler isn't present
 * and the caller wants a non-standard interval. Returns the slug to use.
 */
function _nexus_queue_wp_cron_schedule( int $interval ): string {
	$slug = 'nexus_every_' . max( 60, $interval );
	add_filter( 'cron_schedules', function( $sched ) use ( $slug, $interval ) {
		$sched[ $slug ] = [ 'interval' => $interval, 'display' => $slug ];
		return $sched;
	} );
	return $slug;
}
