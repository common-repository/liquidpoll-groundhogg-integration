<?php
/**
 * Plugin Name: LiquidPoll - Groundhogg Integration
 * Plugin URI: https://liquidpoll.com/plugin/liquidpoll-fluent-crm
 * Description: Integration with Groundhogg
 * Version: 1.0.3
 * Author: LiquidPoll
 * Text Domain: liquidpoll-groundhogg
 * Domain Path: /languages/
 * Author URI: https://liquidpoll.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

use WPDK\Utils;
use function Groundhogg\get_db;

defined( 'ABSPATH' ) || exit;

defined( 'LIQUIDPOLL_GROUNDHOGG_PLUGIN_URL' ) || define( 'LIQUIDPOLL_GROUNDHOGG_PLUGIN_URL', WP_PLUGIN_URL . '/' . plugin_basename( dirname( __FILE__ ) ) . '/' );
defined( 'LIQUIDPOLL_GROUNDHOGG_PLUGIN_DIR' ) || define( 'LIQUIDPOLL_GROUNDHOGG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'LIQUIDPOLL_GROUNDHOGG_PLUGIN_FILE' ) || define( 'LIQUIDPOLL_GROUNDHOGG_PLUGIN_FILE', plugin_basename( __FILE__ ) );


if ( ! class_exists( 'LIQUIDPOLL_Integration_groundhogg' ) ) {
	/**
	 * Class LIQUIDPOLL_Integration_groundhogg
	 */
	class LIQUIDPOLL_Integration_groundhogg {

		protected static $_instance = null;


		/**
		 * LIQUIDPOLL_Integration_groundhogg constructor.
		 */
		function __construct() {

			load_plugin_textdomain( 'liquidpoll-groundhogg', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );

			add_filter( 'LiquidPoll/Filters/poll_meta_field_sections', array( $this, 'add_field_sections' ) );

			add_action( 'liquidpoll_email_added_local', array( $this, 'add_emails_to_groundhogg' ) );
		}


		/**
		 * Add emails to Groundhogg
		 *
		 * @param $args
		 */
		function add_emails_to_groundhogg( $args ) {

			global $wpdb;

			$poll_id         = Utils::get_args_option( 'poll_id', $args );
			$poller_id_ip    = Utils::get_args_option( 'poller_id_ip', $args );
			$email_address   = Utils::get_args_option( 'email_address', $args );
			$first_name      = Utils::get_args_option( 'first_name', $args );
			$last_name       = Utils::get_args_option( 'last_name', $args );
			$groundhogg_tags = Utils::get_meta( 'poll_form_int_groundhogg_tags', $poll_id, array() );
			$polled_value    = $wpdb->get_var( $wpdb->prepare( "SELECT polled_value FROM " . LIQUIDPOLL_RESULTS_TABLE . " WHERE poll_id = %d AND poller_id_ip = %s ORDER BY datetime DESC LIMIT 1", $poll_id, $poller_id_ip ) );

			if ( ! empty( $polled_value ) ) {
				$poll         = liquidpoll_get_poll( $poll_id );
				$poll_options = $poll->get_poll_options();
				$poll_type    = $poll->get_type();

				foreach ( $poll_options as $option_id => $option ) {
					if ( $polled_value == $option_id ) {

						if ( 'poll' == $poll_type ) {
							$groundhogg_tags = array_merge( $groundhogg_tags, Utils::get_args_option( 'groundhogg_tags', $option, array() ) );
						}

						if ( 'nps' == $poll_type ) {
							$groundhogg_tags = array_merge( $groundhogg_tags, Utils::get_args_option( 'groundhogg_nps_tags', $option, array() ) );

						}

						break;
					}
				}
			}

			if ( defined( 'GROUNDHOGG_VERSION' ) ) {
				$args            = array(
					'email'        => $email_address,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'optin_status' => 2,
				);
				$groundhogg_tags = array_unique( $groundhogg_tags );
				$contact_id      = get_db( 'contacts' )->add( $args );

				if ( $contact_id && is_array( $groundhogg_tags ) ) {
					foreach ( $groundhogg_tags as $tag_id ) {
						get_db( 'tag_relationships' )->add( $tag_id, $contact_id );
					}
				}
			}
		}


		/**
		 * Add section in form field
		 *
		 * @param $field_sections
		 *
		 * @return array
		 */
		function add_field_sections( $field_sections ) {

			if ( defined( 'GROUNDHOGG_VERSION' ) ) {

				$field_sections['poll_form']['fields'][] = array(
					'type'       => 'subheading',
					'content'    => esc_html__( 'Integration - Groundhogg', 'wp-poll' ),
					'dependency' => array( '_type', 'any', 'poll,nps,reaction', 'all' ),
				);

				$field_sections['poll_form']['fields'][] = array(
					'id'         => 'poll_form_int_groundhogg_enable',
					'title'      => esc_html__( 'Enable Integration', 'wp-poll' ),
					'label'      => esc_html__( 'This will store the submissions in Groundhogg.', 'wp-poll' ),
					'type'       => 'switcher',
					'default'    => false,
					'dependency' => array( '_type', 'any', 'poll,nps,reaction', 'all' ),
				);

				$field_sections['poll_form']['fields'][] = array(
					'id'         => 'poll_form_int_groundhogg_tags',
					'title'      => esc_html__( 'Select Tags', 'wp-poll' ),
					'subtitle'   => esc_html__( 'Select Groundhogg tags', 'wp-poll' ),
					'type'       => 'select',
					'multiple'   => true,
					'chosen'     => true,
					'options'    => $this->get_groundhogg_tags(),
					'dependency' => array( '_type|poll_form_int_groundhogg_enable', 'any|==', 'poll,nps,reaction|true', 'all' ),
				);

				foreach ( Utils::get_args_option( 'fields', $field_sections['poll_options'], array() ) as $index => $arr_field ) {
					if ( isset( $arr_field['id'] ) && 'poll_meta_options' == $arr_field['id'] ) {
						$field_sections['poll_options']['fields'][ $index ]['fields'][] = array(
							'id'         => 'groundhogg_tags',
							'title'      => esc_html__( 'Select Tags', 'wp-poll' ),
							'subtitle'   => esc_html__( 'Select Groundhogg tags', 'wp-poll' ),
							'type'       => 'select',
							'multiple'   => true,
							'chosen'     => true,
							'options'    => $this->get_groundhogg_tags(),
							'dependency' => array( '_type', '==', 'poll', 'all' ),
						);
						break;
					}
				}

				foreach ( Utils::get_args_option( 'fields', $field_sections['poll_options'], array() ) as $index => $arr_field ) {
					if ( isset( $arr_field['id'] ) && 'poll_meta_options_nps' == $arr_field['id'] ) {
						$field_sections['poll_options']['fields'][ $index ]['fields'][] = array(
							'id'         => 'groundhogg_nps_tags',
							'title'      => esc_html__( 'Select Tags', 'wp-poll' ),
							'subtitle'   => esc_html__( 'Select Groundhogg tags', 'wp-poll' ),
							'type'       => 'select',
							'multiple'   => true,
							'chosen'     => true,
							'options'    => $this->get_groundhogg_tags(),
							'dependency' => array( '_type', '==', 'nps', 'all' ),
						);
						break;
					}
				}
			}

			return $field_sections;
		}


		/**
		 * Return Groundhogg tags
		 *
		 * @return array
		 */
		function get_groundhogg_tags() {

			$tags = [];

			if ( defined( 'GROUNDHOGG_VERSION' ) ) {
				$items = get_db( 'tags' )->query( array( 'limit' => 100 ) );
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						$tags[ $item->tag_id ] = $item->tag_name;
					}
				}
			}

			return $tags;
		}


		/**
		 * @return \LIQUIDPOLL_Integration_groundhogg|null
		 */
		public static function instance() {

			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}

add_action( 'wpdk_init_wp_poll', array( 'LIQUIDPOLL_Integration_groundhogg', 'instance' ) );

