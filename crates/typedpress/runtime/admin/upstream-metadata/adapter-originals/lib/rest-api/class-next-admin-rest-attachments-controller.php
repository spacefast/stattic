<?php
/**
 * REST API: Zero_Admin_WP_REST_Attachments_Controller class
 *
 * @package Zero_Admin
 * @since 1.0.0
 */

/**
 * Core controller used to access attachments via the REST API.
 *
 * @since 1.0.0
 * @since x.x.x Support media type and mime type filtering.
 *
 * @see WP_REST_Attachments_Controller
 */
class Zero_Admin_REST_Attachments_Controller extends WP_REST_Attachments_Controller {
	/**
	 * Determines the allowed query_vars for a get_items() response and
	 * prepares for WP_Query.
	 *
	 * @since 4.7.0
	 * @since ?.?.? Added orderby_mime_type filter to add custom ordering.
	 * @since ?.?.? Extends the `media_type` and `mime_type` request arguments to support array values.
	 *
	 * @param array           $prepared_args Optional. Array of prepared arguments. Default empty array.
	 * @param WP_REST_Request $request       Optional. Request to prepare items for.
	 * @return array Array of query arguments.
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {
		$query_args = parent::prepare_items_query( $prepared_args, $request );

		if ( isset( $query_args['orderby'] ) && isset( $request['orderby'] ) && 'mime_type' === $request['orderby'] ) {
			/*
			 * This filter is used to add custom ordering to the query.
			 * The parameter `post_mime_type` should be added in WP_Query:parse_orderby().
			 * src/wp-includes/class-wp-query.php. See: https://github.com/WordPress/wordpress-develop/pull/10135.
			 */
			add_filter( 'posts_orderby', array( $this, 'orderby_mime_type' ), 10, 2 );
			$query_args['orderby'] = 'post_mime_type';
		}

		$all_mime_types = array();
		$media_types    = $this->get_media_types();

		/*
		 * There is a Core patch for filtering attachments by multiple media/mime types in https://github.com/WordPress/wordpress-develop/pull/10054.
		 * Can be removed when remove once required WordPress version >= 6.9.
		 */
		if ( ! empty( $request['media_type'] ) ) {
			$requested_media_types = is_array( $request['media_type'] ) ? $request['media_type'] : explode( ',', $request['media_type'] );
			foreach ( $requested_media_types as $type ) {
				if ( isset( $media_types[ $type ] ) ) {
					$all_mime_types = array_merge( $all_mime_types, $media_types[ $type ] );
				}
			}
		}

		/*
		 * There is a Core patch for for filtering attachments by multiple media/mime types in https://github.com/WordPress/wordpress-develop/pull/10054.
		 * Can be removed when remove once required WordPress version >= 6.9.
		 */
		if ( ! empty( $request['mime_type'] ) ) {
			$requested_mime_types = is_array( $request['mime_type'] ) ? $request['mime_type'] : explode( ',', $request['mime_type'] );
			foreach ( $requested_mime_types as $mime_type ) {
				$parts = explode( '/', $mime_type );
				if ( isset( $media_types[ $parts[0] ] ) && in_array( $mime_type, $media_types[ $parts[0] ], true ) ) {
					$all_mime_types[] = $mime_type;
				}
			}
		}

		if ( ! empty( $all_mime_types ) ) {
			$query_args['post_mime_type'] = array_values( array_unique( $all_mime_types ) );
		}

		/*
		 * Ensure deterministic ordering to prevent duplicate records across pages.
		 * When multiple attachments have the same post_date, add ID as secondary sort to guarantee consistent ordering.
		 * Note: this is to circumvent a bug that is currently being tracked in https://core.trac.wordpress.org/ticket/44349.
		 */
		if ( ! empty( $query_args['orderby'] ) ) {
			if ( is_array( $query_args['orderby'] ) ) {
				$query_args['orderby']['ID'] = 'DESC';
			} else {
				$query_args['orderby'] .= ', ID DESC';
			}
		}

		return $query_args;
	}

	/**
	 * Ensures deterministic ordering for attachments by MIME type.
	 * When multiple attachments have the same MIME type, adds ID as secondary sort to guarantee consistent ordering.
	 * This prevents the same attachment from appearing on multiple pages when filtering by MIME type.
	 * The parameter `post_mime_type` should be added in WP_Query:parse_orderby().
	 * src/wp-includes/class-wp-query.php. See: https://github.com/WordPress/wordpress-develop/pull/10135.
	 *
	 * @param string   $orderby The orderby clause.
	 * @param WP_Query $query The query object.
	 * @return string The orderby clause.
	 */
	public function orderby_mime_type( $orderby, $query ) {
		$query_orderby = $query->get( 'orderby' );

		if ( ( is_string( $query_orderby ) && strpos( $query_orderby, 'post_mime_type' ) !== false ) ||
			( is_array( $query_orderby ) && array_key_exists( 'post_mime_type', $query_orderby ) ) ) {
			global $wpdb;
			$order = $query->get( 'order' );

			/*
			 * Ensure deterministic ordering to prevent duplicate records across pages.
			 * When multiple attachments have the same post_mime_type, add ID as secondary sort to guarantee consistent ordering.
			 * This prevents the same attachment from appearing on multiple pages when filtering by post_mime_type.
			 * Note: this is to circumvent a bug that is currently being tracked in https://core.trac.wordpress.org/ticket/44349.
			 */
			return "{$wpdb->posts}.post_mime_type {$order}, {$wpdb->posts}.ID {$order}";
		}
		return $orderby;
	}

	/**
	 * Applies edits to a media item and creates a new attachment record.
	 *
	 * Flip and customized attachment fields added in https://github.com/WordPress/wordpress-develop/pull/10041.
	 * This method can be removed once required WordPress version >= 6.9.0.
	 *
	 * @since 5.5.0
	 * @since 6.9.0 Accept args usually passed to `update_item` so consumers can update the newly-created attachment post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, WP_Error object on failure.
	 */
	public function edit_media_item( $request ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = $request['id'];

		// This also confirms the attachment is an image.
		$image_file = wp_get_original_image_path( $attachment_id );
		$image_meta = wp_get_attachment_metadata( $attachment_id );

		if (
			! $image_meta ||
			! $image_file ||
			! wp_image_file_matches_image_meta( $request['src'], $image_meta, $attachment_id )
		) {
			return new WP_Error(
				'rest_unknown_attachment',
				__( 'Unable to get meta information for file.', 'zero-admin' ),
				array( 'status' => 404 )
			);
		}

		$supported_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic' );
		$mime_type       = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, $supported_types, true ) ) {
			return new WP_Error(
				'rest_cannot_edit_file_type',
				__( 'This type of file cannot be edited.', 'zero-admin' ),
				array( 'status' => 400 )
			);
		}

		// The `modifiers` param takes precedence over the older format.
		if ( isset( $request['modifiers'] ) ) {
			$modifiers = $request['modifiers'];
		} else {
			$modifiers = array();

			if ( ! empty( $request['flip'] ) ) {
				$modifiers[] = array(
					'type' => 'flip',
					'args' => array(
						'flip' => $request['flip'],
					),
				);
			}

			if ( ! empty( $request['rotation'] ) ) {
				$modifiers[] = array(
					'type' => 'rotate',
					'args' => array(
						'angle' => $request['rotation'],
					),
				);
			}

			if ( isset( $request['x'], $request['y'], $request['width'], $request['height'] ) ) {
				$modifiers[] = array(
					'type' => 'crop',
					'args' => array(
						'left'   => $request['x'],
						'top'    => $request['y'],
						'width'  => $request['width'],
						'height' => $request['height'],
					),
				);
			}

			if ( 0 === count( $modifiers ) ) {
				return new WP_Error(
					'rest_image_not_edited',
					__( 'The image was not edited. Edit the image before applying the changes.', 'zero-admin' ),
					array( 'status' => 400 )
				);
			}
		}

		/*
		 * If the file doesn't exist, attempt a URL fopen on the src link.
		 * This can occur with certain file replication plugins.
		 * Keep the original file path to get a modified name later.
		 */
		$image_file_to_edit = $image_file;
		if ( ! file_exists( $image_file_to_edit ) ) {
			$image_file_to_edit = _load_image_to_edit_path( $attachment_id );
		}

		$image_editor = wp_get_image_editor( $image_file_to_edit );

		if ( is_wp_error( $image_editor ) ) {
			return new WP_Error(
				'rest_unknown_image_file_type',
				__( 'Unable to edit this image.', 'zero-admin' ),
				array( 'status' => 500 )
			);
		}

		foreach ( $modifiers as $modifier ) {
			$args = $modifier['args'];
			switch ( $modifier['type'] ) {
				case 'flip':
					/*
					 * Flips the current image.
					 * The vertical flip is the first argument (flip along horizontal axis), the horizontal flip is the second argument (flip along vertical axis).
					 * See: WP_Image_Editor::flip()
					 */
					$result = $image_editor->flip( 0 !== (int) $args['flip']['vertical'], 0 !== (int) $args['flip']['horizontal'] );
					if ( is_wp_error( $result ) ) {
						return new WP_Error(
							'rest_image_flip_failed',
							__( 'Unable to flip this image.', 'zero-admin' ),
							array( 'status' => 500 )
						);
					}
					break;
				case 'rotate':
					// Rotation direction: clockwise vs. counterclockwise.
					$rotate = 0 - $args['angle'];

					if ( 0 !== $rotate ) {
						$result = $image_editor->rotate( $rotate );

						if ( is_wp_error( $result ) ) {
							return new WP_Error(
								'rest_image_rotation_failed',
								__( 'Unable to rotate this image.', 'zero-admin' ),
								array( 'status' => 500 )
							);
						}
					}

					break;

				case 'crop':
					$size = $image_editor->get_size();

					$crop_x = (int) round( ( $size['width'] * $args['left'] ) / 100.0 );
					$crop_y = (int) round( ( $size['height'] * $args['top'] ) / 100.0 );
					$width  = (int) round( ( $size['width'] * $args['width'] ) / 100.0 );
					$height = (int) round( ( $size['height'] * $args['height'] ) / 100.0 );

					if ( $size['width'] !== $width || $size['height'] !== $height ) {
						$result = $image_editor->crop( $crop_x, $crop_y, $width, $height );

						if ( is_wp_error( $result ) ) {
							return new WP_Error(
								'rest_image_crop_failed',
								__( 'Unable to crop this image.', 'zero-admin' ),
								array( 'status' => 500 )
							);
						}
					}

					break;

			}
		}

		// Calculate the file name.
		$image_ext  = pathinfo( $image_file, PATHINFO_EXTENSION );
		$image_name = wp_basename( $image_file, ".{$image_ext}" );

		/*
		 * Do not append multiple `-edited` to the file name.
		 * The user may be editing a previously edited image.
		 */
		if ( preg_match( '/-edited(-\d+)?$/', $image_name ) ) {
			// Remove any `-1`, `-2`, etc. `wp_unique_filename()` will add the proper number.
			$image_name = preg_replace( '/-edited(-\d+)?$/', '-edited', $image_name );
		} else {
			// Append `-edited` before the extension.
			$image_name .= '-edited';
		}

		$filename = "{$image_name}.{$image_ext}";

		// Create the uploads subdirectory if needed.
		$uploads = wp_upload_dir();

		// Make the file name unique in the (new) upload directory.
		$filename = wp_unique_filename( $uploads['path'], $filename );

		// Save to disk.
		$saved = $image_editor->save( $uploads['path'] . "/$filename" );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Grab original attachment post so we can use it to set defaults.
		$original_attachment_post = get_post( $attachment_id );

		// Check request fields and assign default values.
		$new_attachment_post                 = $this->prepare_item_for_database( $request );
		$new_attachment_post->post_mime_type = $saved['mime-type'];
		$new_attachment_post->guid           = $uploads['url'] . "/$filename";

		// Unset ID so wp_insert_attachment generates a new ID.
		unset( $new_attachment_post->ID );

		// Set new attachment post title with fallbacks.
		$new_attachment_post->post_title = $new_attachment_post->post_title ?? $original_attachment_post->post_title ?? $image_name;

		// Set new attachment post caption (post_excerpt).
		$new_attachment_post->post_excerpt = $new_attachment_post->post_excerpt ?? $original_attachment_post->post_excerpt ?? '';

		// Set new attachment post description (post_content) with fallbacks.
		$new_attachment_post->post_content = $new_attachment_post->post_content ?? $original_attachment_post->post_content ?? '';

		// Set post parent if set in request, else the default of `0` (no parent).
		$new_attachment_post->post_parent = $new_attachment_post->post_parent ?? 0;

		// Insert the new attachment post.
		$new_attachment_id = wp_insert_attachment( wp_slash( $new_attachment_post ), $saved['path'], 0, true );

		if ( is_wp_error( $new_attachment_id ) ) {
			if ( 'db_update_error' === $new_attachment_id->get_error_code() ) {
				$new_attachment_id->add_data( array( 'status' => 500 ) );
			} else {
				$new_attachment_id->add_data( array( 'status' => 400 ) );
			}

			return $new_attachment_id;
		}

		// First, try to use the alt text from the request. If not set, copy the image alt text from the original attachment.
		$image_alt = isset( $request['alt_text'] ) ? sanitize_text_field( $request['alt_text'] ) : get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( ! empty( $image_alt ) ) {
			// update_post_meta() expects slashed.
			update_post_meta( $new_attachment_id, '_wp_attachment_image_alt', wp_slash( $image_alt ) );
		}

		if ( wp_is_serving_rest_request() ) {
			/*
			 * Set a custom header with the attachment_id.
			 * Used by the browser/client to resume creating image sub-sizes after a PHP fatal error.
			 */
			header( 'X-WP-Upload-Attachment-ID: ' . $new_attachment_id );
		}

		// Generate image sub-sizes and meta.
		$new_image_meta = wp_generate_attachment_metadata( $new_attachment_id, $saved['path'] );

		// Copy the EXIF metadata from the original attachment if not generated for the edited image.
		if ( isset( $image_meta['image_meta'] ) && isset( $new_image_meta['image_meta'] ) && is_array( $new_image_meta['image_meta'] ) ) {
			// Merge but skip empty values.
			foreach ( (array) $image_meta['image_meta'] as $key => $value ) {
				if ( empty( $new_image_meta['image_meta'][ $key ] ) && ! empty( $value ) ) {
					$new_image_meta['image_meta'][ $key ] = $value;
				}
			}
		}

		// Reset orientation. At this point the image is edited and orientation is correct.
		if ( ! empty( $new_image_meta['image_meta']['orientation'] ) ) {
			$new_image_meta['image_meta']['orientation'] = 1;
		}

		// The attachment_id may change if the site is exported and imported.
		$new_image_meta['parent_image'] = array(
			'attachment_id' => $attachment_id,
			// Path to the originally uploaded image file relative to the uploads directory.
			'file'          => _wp_relative_upload_path( $image_file ),
		);

		/**
		 * Filters the meta data for the new image created by editing an existing image.
		 *
		 * @since 5.5.0
		 *
		 * @param array $new_image_meta    Meta data for the new image.
		 * @param int   $new_attachment_id Attachment post ID for the new image.
		 * @param int   $attachment_id     Attachment post ID for the edited (parent) image.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$new_image_meta = apply_filters( 'wp_edited_image_metadata', $new_image_meta, $new_attachment_id, $attachment_id );

		wp_update_attachment_metadata( $new_attachment_id, $new_image_meta );

		$response = $this->prepare_item_for_response( get_post( $new_attachment_id ), $request );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%s', $this->namespace, $this->rest_base, $new_attachment_id ) ) );

		return $response;
	}

	/**
	 * Gets the request args for the edit item route.
	 *
	 * Flip and customized attachment fields added in https://github.com/WordPress/wordpress-develop/pull/10041.
	 * This method can be removed once required WordPress version >= 6.9.0.
	 *
	 * @since 5.5.0
	 * @since 6.9.0 Include args usually passed to `update_item` so consumers can update the newly-created attachment post.
	 *
	 * @return array
	 */
	protected function get_edit_media_item_args() {
		$args = array(
			'src'       => array(
				'description' => __( 'URL to the edited image file.', 'zero-admin' ),
				'type'        => 'string',
				'format'      => 'uri',
				'required'    => true,
			),
			// The `modifiers` param takes precedence over the older format.
			'modifiers' => array(
				'description' => __( 'Array of image edits.', 'zero-admin' ),
				'type'        => 'array',
				'minItems'    => 1,
				'items'       => array(
					'description' => __( 'Image edit.', 'zero-admin' ),
					'type'        => 'object',
					'required'    => array(
						'type',
						'args',
					),
					'oneOf'       => array(
						array(
							'title'      => __( 'Flip', 'zero-admin' ),
							'properties' => array(
								'type' => array(
									'description' => __( 'Flip type.', 'zero-admin' ),
									'type'        => 'string',
									'enum'        => array( 'flip' ),
								),
								'args' => array(
									'description' => __( 'Flip arguments.', 'zero-admin' ),
									'type'        => 'object',
									'required'    => array(
										'flip',
									),
									'properties'  => array(
										'flip' => array(
											'description' => __( 'Flip direction. [ horizontal, vertical ] 0 for no flip, 1 for flip.', 'zero-admin' ),
											'minItems'    => 2,
											'type'        => 'object',
											'required'    => array(
												'horizontal',
												'vertical',
											),
											'properties'  => array(
												'horizontal' => array(
													'description' => __( 'Horizontal flip direction. 0 for no flip, 1 for flip.', 'zero-admin' ),
													'type' => 'number',
												),
												'vertical' => array(
													'description' => __( 'Vertical flip direction. 0 for no flip, 1 for flip.', 'zero-admin' ),
													'type' => 'number',
												),
											),
										),
									),
								),
							),
						),
						array(
							'title'      => __( 'Rotation', 'zero-admin' ),
							'properties' => array(
								'type' => array(
									'description' => __( 'Rotation type.', 'zero-admin' ),
									'type'        => 'string',
									'enum'        => array( 'rotate' ),
								),
								'args' => array(
									'description' => __( 'Rotation arguments.', 'zero-admin' ),
									'type'        => 'object',
									'required'    => array(
										'angle',
									),
									'properties'  => array(
										'angle' => array(
											'description' => __( 'Angle to rotate clockwise in degrees.', 'zero-admin' ),
											'type'        => 'number',
										),
									),
								),
							),
						),
						array(
							'title'      => __( 'Crop', 'zero-admin' ),
							'properties' => array(
								'type' => array(
									'description' => __( 'Crop type.', 'zero-admin' ),
									'type'        => 'string',
									'enum'        => array( 'crop' ),
								),
								'args' => array(
									'description' => __( 'Crop arguments.', 'zero-admin' ),
									'type'        => 'object',
									'required'    => array(
										'left',
										'top',
										'width',
										'height',
									),
									'properties'  => array(
										'left'   => array(
											'description' => __( 'Horizontal position from the left to begin the crop as a percentage of the image width.', 'zero-admin' ),
											'type'        => 'number',
										),
										'top'    => array(
											'description' => __( 'Vertical position from the top to begin the crop as a percentage of the image height.', 'zero-admin' ),
											'type'        => 'number',
										),
										'width'  => array(
											'description' => __( 'Width of the crop as a percentage of the image width.', 'zero-admin' ),
											'type'        => 'number',
										),
										'height' => array(
											'description' => __( 'Height of the crop as a percentage of the image height.', 'zero-admin' ),
											'type'        => 'number',
										),
									),
								),
							),
						),
					),
				),
			),
			'rotation'  => array(
				'description'      => __( 'The amount to rotate the image clockwise in degrees. DEPRECATED: Use `modifiers` instead.', 'zero-admin' ),
				'type'             => 'integer',
				'minimum'          => 0,
				'exclusiveMinimum' => true,
				'maximum'          => 360,
				'exclusiveMaximum' => true,
			),
			'x'         => array(
				'description' => __( 'As a percentage of the image, the x position to start the crop from. DEPRECATED: Use `modifiers` instead.', 'zero-admin' ),
				'type'        => 'number',
				'minimum'     => 0,
				'maximum'     => 100,
			),
			'y'         => array(
				'description' => __( 'As a percentage of the image, the y position to start the crop from. DEPRECATED: Use `modifiers` instead.', 'zero-admin' ),
				'type'        => 'number',
				'minimum'     => 0,
				'maximum'     => 100,
			),
			'width'     => array(
				'description' => __( 'As a percentage of the image, the width to crop the image to. DEPRECATED: Use `modifiers` instead.', 'zero-admin' ),
				'type'        => 'number',
				'minimum'     => 0,
				'maximum'     => 100,
			),
			'height'    => array(
				'description' => __( 'As a percentage of the image, the height to crop the image to. DEPRECATED: Use `modifiers` instead.', 'zero-admin' ),
				'type'        => 'number',
				'minimum'     => 0,
				'maximum'     => 100,
			),
		);

		/*
		 * Get the args based on the post schema. This calls `rest_get_endpoint_args_for_schema()`,
		 * which also takes care of sanitization and validation.
		 */
		$update_item_args = $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE );

		if ( isset( $update_item_args['caption'] ) ) {
			$args['caption'] = $update_item_args['caption'];
		}

		if ( isset( $update_item_args['description'] ) ) {
			$args['description'] = $update_item_args['description'];
		}

		if ( isset( $update_item_args['title'] ) ) {
			$args['title'] = $update_item_args['title'];
		}

		if ( isset( $update_item_args['post'] ) ) {
			$args['post'] = $update_item_args['post'];
		}

		if ( isset( $update_item_args['alt_text'] ) ) {
			$args['alt_text'] = $update_item_args['alt_text'];
		}

		return $args;
	}

	/**
	 * Prepares links for the request.
	 * Consistent with the WP_REST_Attachments_Controller response object,
	 * `post` refers to the post that the media is attached to.
	 *
	 * @since 4.7.0
	 *
	 * @param WP_Post $post Post object.
	 * @return array Links for the given post.
	 */
	protected function prepare_links( $post ) {
		$links = parent::prepare_links( $post );

		// Can be removed when remove once required WordPress version >= 6.9.
		// See: https://core.trac.wordpress.org/ticket/64034.
		if ( ! empty( $post->post_parent ) ) {
			$post = get_post( $post->post_parent );

			if ( ! empty( $post ) ) {
				$links['https://api.w.org/attached-to'] = array(
					'href'       => rest_url( rest_get_route_for_post( $post ) ),
					'embeddable' => true,
					'post_type'  => $post->post_type,
					'id'         => $post->ID,
				);
			}
		}

		return $links;
	}

	/**
	 * Retrieves the query params for collections of attachments.
	 *
	 * @since 4.7.0
	 * @since ?.?.? Added the `mime_type` orderby value.
	 * @since ?.?.? Extends the `media_type` and `mime_type` request arguments to support array values.
	 *
	 * @return array Query parameters for the attachment collection as an array.
	 */
	public function get_collection_params() {
		$params                      = parent::get_collection_params();
		$params['orderby']['enum'][] = 'mime_type';
		$media_types                 = array_keys( $this->get_media_types() );

		$params['media_type'] = array(
			'default'     => null,
			'description' => __( 'Limit result set to attachments of a particular media type or media types.', 'zero-admin' ),

			/*
			 * The Core patch for for filtering attachments by multiple media/mime types sets this to an array in https://github.com/WordPress/wordpress-develop/pull/10054.
			 * However we need to support both string and array values for the `media_type` parameter because this class extends the current WP_REST_Attachments_Controller,
			 * and that class supports only string values. See: https://github.com/WordPress/wordpress-develop/pull/10135.
			 */
			'type'        => array(
				'string',
				'array',
			),
			'items'       => array(
				'type' => 'string',
				'enum' => $media_types,
			),
		);

		$params['mime_type'] = array(
			'default'     => null,
			'description' => __( 'Limit result set to attachments of a particular MIME type or MIME types.', 'zero-admin' ),

			/*
			 * The Core patch for for filtering attachments by multiple media/mime types sets this to an array in https://github.com/WordPress/wordpress-develop/pull/10054.
			 * However we need to support both string and array values for the `mime_type` parameter because this class extends the current WP_REST_Attachments_Controller,
			 * and that class supports only string values. See: https://github.com/WordPress/wordpress-develop/pull/10135.
			 */
			'type'        => array(
				'string',
				'array',
			),
			'items'       => array(
				'type' => 'string',
			),
		);
		return $params;
	}
}
