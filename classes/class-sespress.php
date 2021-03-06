<?php
/**
 * Main file for SesPress class
 *
 * @package SesPress
 */

define( 'CHARSET', 'UTF-8' );

require_once dirname( plugin_dir_path( __FILE__ ) ) . '/vendor/autoload.php';

use Aws\Ses\Exception\SesException;
use Aws\Ses\SesClient;

/**
 * Class SesPress
 * Primary wrapper around Amazon SESClient to instantiate and trigger mails
 */
class SesPress {
	/**
	 * Defines the recipients of current instance.
	 *
	 * @since 0.1
	 * @access protected
	 * @var array
	 */
	protected $recipients;

	/**
	 * Defines the subject of current instance.
	 *
	 * @since 0.1
	 * @access protected
	 * @var string
	 */
	protected $subject;

	/**
	 * Defines the message body of current instance.
	 *
	 * @since 0.1
	 * @access protected
	 * @var string
	 */
	protected $message;

	/**
	 * Defines the sender of current instance.
	 *
	 * @since 0.1
	 * @access protected
	 * @var string
	 */
	protected $from;

	/**
	 * Method to send mail using SES
	 *
	 * @since 0.1
	 * @param array $args    Mail configurations.
	 * @return array
	 */
	public function send( $args ) {

		if ( ! self::are_mails_enabled() ) {
			return array(
				'success' => false,
				'data' => 'mails_disabled',
			);
		}

		$this->set_configurations( $args );

		$client = SesClient::factory(array(
			'version' => 'latest',
			'region' => get_option( 'sespress_region' ),
			'credentials' => array(
				'key'    => get_option( 'sespress_aws_access_key_id' ),
				'secret' => get_option( 'sespress_aws_secret_access_key' ),
			),
		));

		try {
			$result = $client->sendEmail([
				'Destination' => [
					'ToAddresses' => $this->recipients,
				],
				'Message' => [
					'Body' => [
						'Html' => [
							'Charset' => CHARSET,
							'Data' => ( isset( $this->message['html'] ) && $this->message['html'] ) ? $this->message['html'] : '',
						],
						'Text' => [
							'Charset' => CHARSET,
							'Data' => ( isset( $this->message['text'] ) && $this->message['text'] ) ? $this->message['text'] : '',
						],
					],
					'Subject' => [
						'Charset' => CHARSET,
						'Data' => $this->subject,
					],
				],
				'Source' => $this->from ? $this->from : get_option( 'sespress_default_sender' ),
			]);
			$message_id = $result->get( 'MessageId' );
			return array(
				'success' => true,
				'data' => $message_id,
			);
		} catch ( SesException $error ) {
			return array(
				'success' => false,
				'data' => 'aws: ' . $error->getAwsErrorMessage(),
			);
		} catch ( Exception $error ) {
			return array(
				'success' => false,
				'data' => $error->getMessage(),
			);
		}
	}

	/**
	 * Method to set configurations for current instance
	 *
	 * @since 0.1
	 * @access protected
	 * @param array $args    Array of configurations to set.
	 * @return void
	 */
	protected function set_configurations( $args ) {
		$this->set_subject( $args['subject'] );
		$this->set_sender( self::get_formatted_address( sanitize_text_field( $args['sender']['name'] ), sanitize_email( $args['sender']['email'] ) ) );

		$recipients = [];
		foreach ( $args['recipients'] as $recipient ) {
			array_push( $recipients, self::get_formatted_address( sanitize_text_field( $recipient['name'] ), sanitize_email( $recipient['email'] ) ) );
		}
		$this->set_recipients( $recipients );

		if ( array_key_exists( 'message', $args ) ) {
			if ( array_key_exists( 'html', $args['message'] ) ) {
				$this->set_message( $args['message']['html'] );
			}
			if ( array_key_exists( 'text', $args['message'] ) ) {
				$this->set_message( $args['message']['text'], 'text' );
			}
		}

		if ( array_key_exists( 'template', $args ) ) {
			$template = $args['template'];
			if ( array_key_exists( 'path', $args['template'] ) ) {
				$template['meta'] = array_key_exists( 'meta', $template ) ? $template['meta'] : [];
				$this->set_mail_template( $template['path'], $template['meta'] );
			}
		}
	}

	/**
	 * Static method to check if mails are enabled
	 *
	 * @since 0.1
	 * @access protected
	 * @return boolean
	 */
	protected static function are_mails_enabled() {
		return 'on' === get_option( 'sespress_enable_emails' );
	}

	/**
	 * Static method to format address using name and email
	 *
	 * @since 0.1
	 * @access protected
	 * @param string $name    Name of recipient/sender.
	 * @param string $email    Email of recipient/sender.
	 * @return string
	 */
	protected static function get_formatted_address( $name, $email ) {
		return sanitize_text_field( $name ) . ' <' . sanitize_email( $email ) . '>';
	}

	/**
	 * Static method to check if test mode is enabled
	 *
	 * @since 0.1
	 * @access protected
	 * @return boolean
	 */
	protected static function is_test_mode() {
		return 'on' === get_option( 'sespress_test_mode' );
	}

	/**
	 * Static method to fetch test mode recipient name configured from WP Dashboard.
	 *
	 * @since 0.1
	 * @access protected
	 * @return string
	 */
	protected static function get_test_mode_recipient_name() {
		return sanitize_text_field( get_option( 'sespress_test_mode_recipient_name' ) );
	}

	/**
	 * Static method to fetch test mode recipient email configured from WP Dashboard.
	 *
	 * @since 0.1
	 * @access protected
	 * @return string
	 */
	protected static function get_test_mode_recipient_email() {
		return sanitize_email( get_option( 'sespress_test_mode_recipient_email' ) );
	}

	/**
	 * Method to get subject of current instance
	 *
	 * @since 0.1
	 * @access protected
	 * @return string
	 */
	protected function get_subject() {
		return $this->subject;
	}

	/**
	 * Method to set subject of current instance
	 *
	 * @since 0.1
	 * @access protected
	 * @param string $subject    Subject string.
	 * @return void
	 */
	protected function set_subject( $subject ) {
		$subject = sanitize_text_field( $subject );
		if ( self::is_test_mode() ) {
			$this->subject = 'Test - ' . $subject;
			return;
		}
		$this->subject = $subject;
	}

	/**
	 * Method to get message body of current instance based on message type.
	 *
	 * @since 0.1
	 * @access protected
	 * @param string $type    Type of message to return Possible values: html (default) or text.
	 * @return string
	 */
	protected function get_message( $type = 'html' ) {
		return $this->message[ $type ];
	}

	/**
	 * Method to set message for current instance.
	 *
	 * @since 0.1
	 * @access protected
	 * @param string $message    Message string.
	 * @param string $type     Message type. Possible values: html (default) or type.
	 * @return void
	 */
	protected function set_message( $message, $type = 'html' ) {
		$type = sanitize_text_field( $type );
		if ( 'text' === strtolower( $type ) ) {
			$this->message['text'] = $message;
		} else {
			$this->message['html'] = $message;
		}
	}

	/**
	 * Method to get an array of recipients set for current instance
	 *
	 * @since 0.1
	 * @access protected
	 * @return array
	 */
	protected function get_recipients() {
		return $this->recipients;
	}

	/**
	 * Method to set recipients for current instance
	 *
	 * @since 0.1
	 * @access protected
	 * @param array $recipients    Recipients to set.
	 * @return void
	 */
	protected function set_recipients( $recipients ) {
		if ( self::is_test_mode() ) {
			$this->recipients = array(
				self::get_formatted_address( self::get_test_mode_recipient_name(), self::get_test_mode_recipient_email() ),
			);
			return;
		}
		$this->recipients = $recipients;
	}

	/**
	 * Method to set sender of current instance
	 *
	 * @since 0.1
	 * @access protected
	 * @return array
	 */
	protected function get_sender() {
		return $this->recipients;
	}

	/**
	 * Method to set sender of current mail instance
	 *
	 * @since 0.1
	 * @access protected
	 * @param string $sender    Sender string. Should be formatted before.
	 * @return void
	 */
	protected function set_sender( $sender ) {
		$this->from = $sender;
	}

	/**
	 * Method to set mail template
	 *
	 * @since 0.1
	 * @access protected
	 * @param string $template_name    Path of the template.
	 * @param array  $args    Dynamic values to be inserted in the mail template.
	 * @return boolean
	 */
	protected function set_mail_template( $template_name, $args = [] ) {
		$template_name = sanitize_text_field( $template_name );
		$template_path = locate_template( array( $template_name ), false, true );
		if ( ! $template_path ) {
			return false;
		}

		$template = array();
		foreach ( $args as $variable => $value ) {
			$variable = preg_replace( '/\s+/', '_', trim( sanitize_text_field( $variable ) ) );
			$template[ $variable ] = sanitize_text_field( $value );
		}

		try {
			ob_start();
			require_once( $template_path );
			$this->message['html'] = ob_get_contents();
			ob_end_clean();
			return true;
		} catch ( Exception $error ) {
			return $error->getMessage();
		}
	}
}
