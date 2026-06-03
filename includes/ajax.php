<?php
/**
 * Nexus by Therum — AJAX save / delete handlers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_nexus_connector_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$id = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) );
	if ( ! $id ) wp_send_json_error( 'no connector id' );

	$registry = nexus_connector_registry();
	if ( ! isset( $registry[ $id ] ) ) wp_send_json_error( 'unknown connector' );
	$connector = $registry[ $id ];

	if ( ! empty( $connector['built_in'] ) ) wp_send_json_error( 'built-in connector' );

	$posted_config = isset( $_POST['config'] ) && is_array( $_POST['config'] )
		? wp_unslash( $_POST['config'] )
		: [];

	// Read existing config so we can preserve password fields when the client
	// posts back the masked placeholder ('••••••••') without edits.
	$existing = nexus_get_connector( $id );
	$prior    = $existing['config'] ?? [];

	$clean = [];
	foreach ( $connector['fields'] as $field ) {
		$key = $field['key'];
		$val = $posted_config[ $key ] ?? '';

		if ( $field['type'] === 'checkbox' ) {
			$clean[ $key ] = ! empty( $val ) ? '1' : '';
		} elseif ( $field['type'] === 'url' ) {
			$clean[ $key ] = esc_url_raw( $val );
		} elseif ( $field['type'] === 'select' ) {
			$opts = array_keys( $field['options'] ?? [] );
			$clean[ $key ] = in_array( $val, $opts, true ) ? $val : ( $opts[0] ?? '' );
		} elseif ( $field['type'] === 'password' ) {
			// Treat the placeholder bullets as "no change" — keep prior value.
			$bullets = str_repeat( '•', 8 );
			if ( $val === $bullets || $val === '' ) {
				$clean[ $key ] = $prior[ $key ] ?? '';
			} else {
				$clean[ $key ] = sanitize_text_field( $val );
			}
		} else {
			$clean[ $key ] = sanitize_text_field( $val );
		}
	}

	nexus_save_connector( $id, [
		'enabled' => true,
		'config'  => $clean,
		'updated' => time(),
	] );

	wp_send_json_success( [ 'msg' => 'Saved', 'id' => $id ] );
} );

add_action( 'wp_ajax_nexus_connector_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );
	$id = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) );
	if ( ! $id ) wp_send_json_error( 'no id' );
	nexus_delete_connector( $id );
	wp_send_json_success( [ 'msg' => 'Disconnected' ] );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  CUSTOM CONNECTORS — add (upsert) and delete
//
//  Same handler covers Add (editing_slug empty) and Edit (editing_slug set).
//  When editing_slug differs from the new slug, the saved credential is
//  moved to the new id before the old custom row is dropped — so renaming
//  doesn't orphan keys.
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_nexus_custom_add', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$category = sanitize_key( wp_unslash( $_POST['category'] ?? '' ) );
	if ( ! in_array( $category, NEXUS_CUSTOM_CATEGORIES, true ) ) {
		wp_send_json_error( [ 'message' => 'Custom connectors only land in CMS / Ecommerce / APIs in 1.1.0.' ] );
	}

	$name = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
	if ( $name === '' ) wp_send_json_error( [ 'message' => 'Name is required.' ] );

	$orig_slug    = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
	$slug         = $orig_slug !== '' ? $orig_slug : sanitize_title( $name );
	$editing_slug = sanitize_key( wp_unslash( $_POST['editing_slug'] ?? '' ) );

	if ( $slug === '' ) wp_send_json_error( [ 'message' => 'Could not derive a slug from that name.' ] );

	$builtin_ids = array_keys( nexus_connector_registry_builtin() );
	if ( in_array( $slug, $builtin_ids, true ) ) {
		wp_send_json_error( [ 'message' => 'Slug "' . $slug . '" collides with a built-in connector. Pick another.' ] );
	}
	$existing = nexus_get_custom_connectors();
	if ( isset( $existing[ $slug ] ) && $slug !== $editing_slug ) {
		wp_send_json_error( [ 'message' => 'A custom connector with the id "' . $slug . '" already exists.' ] );
	}

	$color = sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) );
	if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
		$color = '#' . substr( md5( $slug ), 0, 6 );
	}

	$initial = strtoupper( mb_substr( $name, 0, 2 ) );
	if ( $initial === '' ) $initial = '?';

	// 1–4 credential rows from the modal. Row 1's label is required; the
	// rest are skipped when blank. Keys are derived from the label (slugged)
	// with a numeric suffix on collision so two "Token" rows don't clobber.
	$fields    = [];
	$used_keys = [];
	for ( $i = 1; $i <= 4; $i++ ) {
		$raw_label = trim( sanitize_text_field( wp_unslash( $_POST[ 'cred_label_' . $i ] ?? '' ) ) );
		if ( $raw_label === '' ) continue;

		$type = sanitize_key( wp_unslash( $_POST[ 'cred_type_' . $i ] ?? 'password' ) );
		if ( ! in_array( $type, [ 'password', 'text' ], true ) ) $type = 'password';

		$base_key = sanitize_key( str_replace( '-', '_', sanitize_title( $raw_label ) ) );
		if ( $base_key === '' || $base_key === 'base_url' ) $base_key = 'field_' . $i;
		$key = $base_key;
		$n   = 2;
		while ( in_array( $key, $used_keys, true ) ) {
			$key = $base_key . '_' . $n++;
		}
		$used_keys[] = $key;

		$fields[] = [
			'key'         => $key,
			'label'       => $raw_label,
			'type'        => $type,
			'placeholder' => $type === 'password' ? '••••••••' : '',
			'required'    => $i === 1,
		];
	}

	if ( empty( $fields ) ) {
		wp_send_json_error( [ 'message' => 'At least one credential field is required.' ] );
	}

	$base_url = trim( sanitize_text_field( wp_unslash( $_POST['base_url'] ?? '' ) ) );
	if ( $base_url !== '' ) {
		$fields[] = [ 'key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'placeholder' => 'https://api.example.com', 'required' => false ];
	}

	$row = [
		'name'     => $name,
		'category' => $category,
		'color'    => $color,
		'initial'  => $initial,
		'desc'     => sanitize_text_field( wp_unslash( $_POST['desc'] ?? '' ) ),
		'fields'   => $fields,
		'docs'     => esc_url_raw( wp_unslash( $_POST['docs'] ?? '' ) ),
	];

	if ( $editing_slug && $editing_slug !== $slug ) {
		$saved = nexus_get_connector( $editing_slug );
		if ( ! empty( $saved ) ) {
			nexus_save_connector( $slug, $saved );
			nexus_delete_connector( $editing_slug );
		}
		$existing = nexus_get_custom_connectors();
		unset( $existing[ $editing_slug ] );
		update_option( 'nexus_custom_connectors', $existing, false );
	}

	nexus_save_custom_connector( $slug, $row );

	wp_send_json_success( [
		'id'      => $slug,
		'editing' => (bool) $editing_slug,
	] );
} );

add_action( 'wp_ajax_nexus_custom_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$slug = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) );
	if ( ! $slug ) wp_send_json_error( 'no id' );

	$customs = nexus_get_custom_connectors();
	if ( ! isset( $customs[ $slug ] ) ) {
		wp_send_json_error( [ 'message' => 'Built-in connectors can\'t be deleted.' ] );
	}

	nexus_delete_custom_connector( $slug );
	wp_send_json_success( [ 'id' => $slug ] );
} );
