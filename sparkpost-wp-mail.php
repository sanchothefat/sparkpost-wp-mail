<?php
/**
 * Plugin Name:  SparkPost wp_mail Drop-In
 * Plugin URI:   https://github.com/sanchothefat/sparkpost-wp-mail
 * Description:  Drop-in replacement for wp_mail using the SparkPost API.
 * Version:      0.0.2
 * Author:       Daniel Bachhuber, Robert O'Rourke
 * Author URI:   https://github.com/sanchothefat
 * License:      GPL-3.0+
 * License URI:  http://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Override WordPress' default wp_mail function with one that sends email
 * using SparkPost's API.
 *
 * Note that this function requires the SPARKPOST_API_KEY constant to be defined
 * in order for it to work. The easiest place to define this is in wp-config.
 *
 * @since  0.0.1
 * @access public
 * @todo   Add support for attachments
 * @param  string $to
 * @param  string $subject
 * @param  string $message
 * @param  mixed  $headers
 * @param  array  $attachments
 * @return bool true if mail has been sent, false if it failed
 */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	// Return early if our API key hasn't been defined.
	if ( ! defined( 'SPARKPOST_API_KEY' ) ) {
		return false;
	}

	// Compact the input, apply the filters, and extract them back out
	extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) ) );

	// Get the site domain and get rid of www.
	$sitename = strtolower( parse_url( site_url(), PHP_URL_HOST ) );
	if ( 'www.' === substr( $sitename, 0, 4 ) ) {
		$sitename = substr( $sitename, 4 );
	}

	$from_email = 'wordpress@' . $sitename;

	$message_args = array(
		// Email
		'recipients'         => $to,
		'headers'            => array(
			'Content-type'  => 'application/json',
			'Authorization' => SPARKPOST_API_KEY,
			'User-Agent'    => 'sparkpost-wp-mail',
		),
		'content'            => array(
			'html'          => $message,
			'text'          => null,
			'subject'       => $subject,
			'from'          => array(
				'email' => $from_email,
				'name'  => get_bloginfo( 'name' ),
			),
			'reply_to'      => null,
			'headers'       => array(),
			'attachments'   => array(),
			'inline_images' => array(),
		),

		// Options
		'options'            => array(
			'start_time'       => null,
			'open_tracking'    => null,
			'click_tracking'   => null,
			'transactional'    => null,
			'sandbox'          => false,
			'skip_suppression' => null,
			'inline_css'       => null,
		),

		// SparkPost defaults
		'description'        => null,
		'campaign_id'        => null,
		'metadata'           => array(),
		'substitution_data'  => array(),
		'return_path'        => null, // Elite only
		'template_id'        => '',
		'use_draft_template' => null,
	);

	$message_args = apply_filters( 'sparkpost_wp_mail_pre_message_args', $message_args );

	// Make sure our recipients value is an array so we can manipulate it for the API.
	if ( ! is_array( $message_args['recipients'] ) ) {
		$message_args['recipients'] = explode( ',', $message_args['recipients'] );
	}

	// Sneaky support for multiple to addresses.
	$processed_to = array();
	foreach ( (array) $message_args['recipients'] as $email ) {
		if ( is_array( $email ) ) {
			$processed_to[] = $email;
		} else {
			$processed_to[] = array( 'email' => $email );
		}
	}
	$message_args['recipients'] = $processed_to;

	// Attachments
	foreach ( (array) $attachments as $attachment ) {
		$message_args['content']['attachments'][] = array(
			'type' => mime_content_type( $attachment ),
			'name' => basename( $attachment ),
			'data' => 'data:' . mime_content_type( $attachment ) . ';base64,' . base64_encode( file_get_contents( $attachment ) ),
		);
	}

	// Set up message headers if we have any to send.
	if ( ! empty( $headers ) ) {
		$message_args = _sparkpost_wp_mail_headers( $headers, $message_args );
	}

	// Default filters we should still apply.
	$message_args['content']['from']['email'] = apply_filters( 'wp_mail_from', $message_args['content']['from']['email'] );
	$message_args['content']['from']['name']  = apply_filters( 'wp_mail_from_name', $message_args['content']['from']['name'] );

	// Allow user to override message args before they're sent to SparkPost.
	$message_args = apply_filters( 'sparkpost_wp_mail_message_args', $message_args );

	$request_args = array(
		'body' => $message_args,
	);

	$request_url = 'https://api.sparkpost.com/api/v1/transmissions';
	$response    = wp_remote_post( $request_url, $request_args );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}

	return true;
}

/**
 * Handle email headers before they're sent to the SparkPost API.
 *
 * @since  0.0.2
 * @access private
 * @todo   Improve BCC handling
 * @param  mixed $headers
 * @param  array $message_args
 * @return array $message_args
 */
function _sparkpost_wp_mail_headers( $headers, $message_args ) {
	if ( ! is_array( $message_args ) ) {
		return $message_args;
	}

	// Prepare the passed headers.
	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
	}

	// Bail if we don't have any headers to work with.
	if ( empty( $headers ) ) {
		return $message_args;
	}

	foreach ( (array) $headers as $index => $header ) {

		if ( false === strpos( $header, ':' ) ) {
			continue;
		}

		// Explode them out
		list( $name, $content ) = explode( ':', trim( $header ), 2 );

		// Cleanup crew
		$name    = trim( $name );
		$content = trim( $content );

		switch ( strtolower( $name ) ) {

			// SparkPost handles these separately
			case 'subject':
			case 'from':
			case 'to':
			case 'reply-to':
				unset( $headers[ $index ] );
				break;

			case 'cc':
				$cc           = explode( ',', $content );
				$processed_cc = array();
				foreach ( (array) $cc as $email ) {
					$processed_cc[] = array(
						'email' => trim( $email ),
						'type'  => 'cc',
					);
				}
				$message_args['content']['headers']['cc'] = array_merge( $message_args['content']['headers']['cc'], $processed_cc );
				break;

			case 'bcc':
				$bcc           = explode( ',', $content );
				$processed_bcc = array();
				foreach ( (array) $bcc as $email ) {
					$processed_bcc[] = array(
						'email' => trim( $email ),
						'type'  => 'bcc',
					);
				}
				$message_args['content']['headers']['bcc'] = array_merge( $message_args['content']['headers']['bcc'], $processed_bcc );
				break;

			case 'importance':
			case 'x-priority':
			case 'x-msmail-priority':
				if ( ! $message_args['important'] ) {
					$message_args['important'] = ( strpos( strtolower( $content ), 'high' ) !== false ) ? true : false;
				}
				break;

			default:
				if ( 'x-' === substr( $name, 0, 2 ) ) {
					$message_args['content']['headers'][ trim( $name ) ] = trim( $content );
				}
				break;
		}
	}
	return apply_filters( 'sparkpost_wp_mail_headers', $message_args );
}