<?php

/**
 * WP-CLI command to ptimize/compress WordPress image attachments
 * using the Kraken Image Optimizer API.
 *
 * @author Till Krüss
 * @license MIT License
 * @link https://github.com/tillkruss/wp-cli-kraken
 */
class WP_CLI_Kraken extends WP_CLI_Command {

	/**
	 * @var string Cache uploads directory path.
	 */
	private $upload_dir;

	/**
	 * @var bool Dry-run state.
	 */
	private $dryrun = false;

	/**
	 * @var int Maximum number of images to krake.
	 */
	private $limit = -1;

	/**
	 * @var array Runtime statistics.
	 */
	private $statistics = array();

	/**
	 * @var array Attachment Kraken metadata cache.
	 */
	private $kraken_metadata = array();

	/**
	 * @var array Default config values.
	 */
	private $config = array(
		'lossy' => false,
		'compare' => 'md4',
		'types' => 'gif, jpeg, png, svg'
	);

	/**
	 * @var array Image comparison methods.
	 */
	private $comparators = array(
		'none',
		'md4',
		'timestamp'
	);

	/**
	 * @var array Accepted image MIME types.
	 */
	private $mime_types = array(
		'gif'  => 'image/gif',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'svg'  => 'image/svg+xml'
	);

	/**
	 * Constructor. Checks if the cURL extention is available.
	 * Sets values for `$this->upload_dir` and `$this->statistics`.
	 *
	 * @return void
	 */
	public function __construct() {

		// abort if cURL extention is not available
		if ( ! extension_loaded( 'curl' ) || ! function_exists( 'curl_init' ) ) {
			WP_CLI::error( 'Please install/enable the cURL PHP extension.' );
		}

		// cache uploads directory path
		$this->upload_dir = wp_upload_dir()[ 'basedir' ];

		// setup `$this->statistics` values
		foreach ( array(
			'attachments', 'files', 'compared', 'changed', 'unknown', 'uploaded',
			'kraked', 'failed', 'samesize', 'size', 'saved' ) as $key
		) { $this->statistics[ $key ] = 0; }

	}

	/**
	 * Krake image(s).
	 *
	 * ## DESCRIPTION
	 *
	 * Optimize/compress WordPress image attachments using the Kraken Image Optimizer API.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : One or more IDs of the attachments to krake.
	 *
	 * [--lossy]
	 * : Use lossy image compression.
	 *
	 * [--limit=<number>]
	 * : Maximum number of images to krake. Default: -1
	 *
	 * [--types=<types>]
	 * : Image format(s) to krake. Default: 'gif,jpeg,png,svg'
	 *
	 * [--compare=<method>]
	 * : Image metadata comparison method. Values: none, md4, timestamp. Default: md4
	 *
	 * [--all]
	 * : Bypass metadata comparison.
	 *
	 * [--dry-run]
	 * : Do a dry run and show report without executing API calls.
	 *
	 * [--api-key=<key>]
	 * : Kraken API key to use.
	 *
	 * [--api-secret=<secret>]
	 * : Kraken API secret to use.
	 *
	 * [--api-test]
	 * : Validate Kraken API credentials and show account summary.
	 *
	 * ## EXAMPLES
	 *
	 *     # Krake all images that have not been kraked
	 *     wp media krake
	 *
	 *     # Krake all image sizes of attachment with id 1337
	 *     wp media krake 1337
	 *
	 *     # Krake a maximum of 42 images
	 *     wp media krake --limit=42
	 *
	 *     # Do a dry run and show report without executing API calls
	 *     wp media krake --dry-run
	 *
	 *     # Validate Kraken API credentials and show account summary
	 *     wp media krake --api-test
	 *
	 */
	public function __invoke( array $args, array $assoc_args ) {

		$this->_init_config( $assoc_args ); 
		
		$images = new WP_Query( array(
			'post_type' => 'attachment',
			'post__in' => $args,
			'post_mime_type' => implode( ', ', $this->_parse_mime_types( $this->config[ 'types' ], true ) ),
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids'
		) );

		// abort if no matching attachments are found
		if ( ! $images->post_count ) {
			WP_CLI::warning( 'No matching attachments found.' );
			return;
		}

		WP_CLI::line( sprintf(
			'Found %d %s to check. %s',
			$images->post_count,
			_n( 'attachment', 'attachments', $images->post_count ),
			$this->limit === -1
				? 'Kraking all images.'
				: sprintf( 'Kraking a maximum of %d %s.', $this->limit, _n( 'image', 'images', $this->limit ) )
		) );

		WP_CLI::line( 'Skipping already kraked images.' );

		// loop through all attachments and check all image sizes for each one
		foreach ( $images->posts as $id ) {
			if ( ! $this->_check_image_sizes( $id ) ) {
				break; // limit has been reached
			}
		}

		$this->_show_report();

	}

	/**
	 * Sets class/config values from command-line flags and YAML config file values.
	 *
	 * @param array $args Passed command-line flags.
	 * @return void
	 */
	private function _init_config( array $args ) {

		// fetch `kraken` config values from YAML config file
		$extra_config = WP_CLI::get_runner()->extra_config;
		$config = isset( $extra_config[ 'kraken' ] ) ? $extra_config[ 'kraken' ] : array();

		// set dry-run state?
		if ( isset( $args[ 'dry-run' ] ) ) {
			$this->dryrun = true;
		}
		
		$settings   = WP_Kraken::get_instance()->kraken_settings;
		$api_key    = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$api_secret = isset( $settings['api_secret'] ) ? $settings['api_secret'] : '';

		// validate Kraken API credentials format
		if ( isset( $api_key, $api_secret ) ) {

			if ( preg_match( '~^[a-f0-9]{32}$~i', $api_key ) && preg_match( '~^[a-f0-9]{40}$~i', $api_secret ) ) {

				$this->config[ 'api-key' ] = $api_key;
				$this->config[ 'api-secret' ] = $api_secret;

				// validate API credentials
				if ( ! $this->dryrun ) {

					$ch = curl_init();
					curl_setopt( $ch, CURLOPT_URL, 'https://api.kraken.io/user_status' );
					curl_setopt( $ch, CURLOPT_POST, true );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( array(
						'auth' => array(
							'api_key' => $api_key,
							'api_secret' => $api_secret
						)
					) ) );

					$response = json_decode( curl_exec( $ch ), true );

					if ( curl_errno( $ch ) ) {

						// show warning if a cURL occurred
						WP_CLI::warning( sprintf( 'Kraken API credentials validation failed. (cURL error [%d]: %s)', curl_errno( $ch ), curl_error( $ch ) ) );

					} else {

						if ( $response[ 'success' ] ) {

							// always show account quota details
							WP_CLI::line( sprintf(
								'Monthly Quota: %s, Current Usage: %s, Remaining: %s',
								size_format( $response[ 'quota_total' ] ),
								size_format( $response[ 'quota_used' ], 2 ),
								size_format( $response[ 'quota_remaining' ], 2 )
							) );

							// abort if we're only doing an API test
							if ( isset( $args[ 'api-test' ] ) ) {
								WP_CLI::success( 'Kraken API test successful.' );
								exit;
							}

						} else {

							WP_CLI::warning( sprintf( 'Kraken API credentials validation failed. (Error: %s)', $response[ 'error' ] ) );

						}

					}

					curl_close( $ch );

				}

			} else {
				WP_CLI::error( 'Please specify valid Kraken API credentials.' );
			}

		} else {
			WP_CLI::error( 'Please specify your Kraken API credentials.' );
		}

		// parse `--limit=<number>` flag
		if ( isset( $args[ 'limit' ] ) ) {
			$limit = trim( $args[ 'limit' ] );
			if ( is_string( $args[ 'limit' ] ) && ( $limit === '-1' || $limit > 0 ) ) {
				$this->limit = intval( $limit );
			} else {
				WP_CLI::error( 'Invalid `limit` flag value.' );
			}
		}
		

		// setup comparison method
		if ( isset( $args[ 'all' ] ) ) {

			// krake all images
			$this->config[ 'compare' ] = 'none';

		}

	}

	/**
	 * Finds all image sizes (thumbnail, medium, etc.) of given attachment.
	 * Calls `_kraken_image()` directly for each image that has no Kraken metadata.
	 * Calls `_maybe_kraken_image()` images that have Kraken metadata for comparison.
	 * Returns `false` if image limit has been reached, otherwise `true`.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function _check_image_sizes( $attachment_id ) {

		$files = array();
		$fileurl = wp_get_attachment_url( $attachment_id );

		if ( get_post_meta( $attachment_id, '_kraken_size', true ) || get_post_meta( $attachment_id, '_kraked_thumbs', true ) ) {
			$this->statistics['samesize'] ++;
			
			WP_CLI::line( sprintf(
				'Skipping %s because it has already been compressed.',
				get_the_title( $attachment_id )
			) );
			
			return true;
		}
		
		$title = [];
		$post_id = $attachment_id;
		while( get_post( $post_id )->post_parent ) {
			$title[] = get_the_title( $post_id );
			$post_id = get_post( $post_id )->post_parent;
		}

		$title[] = get_the_title( $post_id );
		
		$title = implode( ' - ', array_reverse( $title ) );
		$this->statistics[ 'attachments' ]++;

		if ( $this->dryrun ) {
			WP_CLI::line( sprintf(
				'Dry run: %s, file size: %s, url: %s',
				$title,
				WP_Kraken::get_file_size( $fileurl ),
				$fileurl
			) );
		} else {
			$result = WP_Kraken::get_instance()->kraken_attachment( $attachment_id );
			
			if ( isset( $result['error'] ) ) {
				$this->statistics['failed'] ++;
				WP_CLI::warning( $result['error'] );
			} elseif ( isset( $result['success'] ) ) {

				$this->statistics['kraked'] ++;
				
				WP_CLI::line( sprintf( 
					'Processed %s',
					$title
				) );

			} else {
				$this->statistics['failed'] ++;
				WP_CLI::warning( 'Unexpected response' );
				WP_CLI::debug( $result );
			}
		}
		
		// if a limit is set, return `false` once it's reached
		if ( $this->limit !== -1 ) {

			if ( $this->dryrun ) {
				if ( $this->statistics[ 'attachments' ] >= $this->limit ) {
					return false;
				}
			} else {
				if ( $this->statistics[ 'attachments' ] >= $this->limit ) {
					return false;
				}
			}

		}


		return true;

	}


	/**
	 * Parses given comma-separated types into array and returns it.
	 * If `$map` is `true`, the returned array will contain MIME types, instead of given types.
	 * Returns `false` if none or invalid types are passed.
	 *
	 * @param string $string Comma-separated list of types.
	 * @param bool $map Return MIME types, instead of given types.
	 * @return array|false
	 */
	private function _parse_mime_types( $string, $map = false ) {

		$types = array();
		$values = preg_split( '~[\s,]+~', trim( $string ) );

		foreach ( $values as $value ) {

			if ( empty( $value ) ) {
				continue;
			}

			if ( $value === 'jpg' ) {
				$value = 'jpeg'; // Mooji-style!
			}

			// validate value and return `false` if value is invalid
			if ( isset( $this->mime_types[ $value ] ) ) {
				$types[] = $map ? $this->mime_types[ $value ] : $value;
			} else {
				return false;
			}

		}

		return empty( $types ) ? false : $types;

	}

	/**
	 * Shows final report.
	 *
	 * @return void
	 */
	private function _show_report() {

		WP_CLI::line( sprintf(
			'%d %s checked.',
			$this->statistics[ 'attachments' ],
			_n( 'attachment', 'attachments', $this->statistics[ 'attachments' ] )
		) );

		WP_CLI::line( sprintf(
			'%d %s successfully kraked.',
			$this->statistics[ 'kraked' ],
			_n( 'image', 'images', $this->statistics[ 'kraked' ] )
		) );

		WP_CLI::line( sprintf(
			'%d %s already fully optimized.',
			$this->statistics[ 'samesize' ],
			_n( 'image', 'images', $this->statistics[ 'samesize' ] )
		) );

		WP_CLI::line( sprintf(
			'%d %s failed to kraken.',
			$this->statistics[ 'failed' ],
			_n( 'image', 'images', $this->statistics[ 'failed' ] )
		) );

		if ( $this->statistics[ 'size' ] > 0 && $this->statistics[ 'saved' ] > 0 ) {

			WP_CLI::line( sprintf(
				'%s bytes compressed to %s bytes. Savings: %s%%',
				size_format( $this->statistics[ 'size' ], 2 ),
				size_format( $this->statistics[ 'saved' ], 2 ),
				round( abs( ( $this->statistics[ 'saved' ] / $this->statistics[ 'size' ] ) * 100 ), 2 )
			) );

		}

	}

}

// register `media krake` command
WP_CLI::add_command( 'media krake', 'WP_CLI_Kraken' );
