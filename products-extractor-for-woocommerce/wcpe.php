<?php
/**
 * Plugin Name: استخراج محصولات ووکامرس برای ترب - رسمی
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس
 * Version: 1.3.2
 * Author: Torob
 * Author URI: https://torob.com/
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: products-extractor-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	class WC_Products_Extractor extends WP_REST_Controller {
		private $plugin_version = "1.3.2";

		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		private function woocommerce_version() {
			if ( defined( "WC_VERSION" ) ) {
				return WC_VERSION;
			} else {
				return WC()->version;
			}
		}

		private function php_version() {
			if ( defined( "PHP_VERSION" ) ) {
				return PHP_VERSION;
			} elseif ( function_exists( 'phpversion' ) ) {
				return phpversion();
			}

			return null;
		}

		/**
		 * find matching product and variation
		 */
		private function find_matching_variation( $product, $attributes ) {
			foreach ( $attributes as $key => $value ) {
				if ( strpos( $key, 'attribute_' ) === 0 ) {
					continue;
				}
				unset( $attributes[ $key ] );
				$attributes[ sprintf( 'attribute_%s', $key ) ] = $value;
			}
			if ( class_exists( 'WC_Data_Store' ) ) {
				$data_store = WC_Data_Store::load( 'product' );

				return $data_store->find_matching_product_variation( $product, $attributes );
			} else {
				return $product->get_matching_variation( $attributes );
			}
		}

		/**
		 * Register rout: https://domain.com/wcpe/v1/products
		 */
		public function register_routes() {
			$version   = '1';
			$namespace = 'wcpe/v' . $version;
			$base      = 'products';
			register_rest_route( $namespace, '/' . $base, array(
				array(
					'methods'             => 'POST',
					'callback'            => array(
						$this,
						'get_products'
					),
					'permission_callback' => '__return_true',
					'args'                => array()
				)
			) );
		}

		/**
		* Check update and validate the request
		*
		* @param WP_REST_Request $request The REST API request object.
		*
		* @return array|WP_Error The response from the remote server or an error object.
		*/
		public function check_request( $request ) {
			// Get shop domain
			$site_url    = wp_parse_url( get_site_url() );
			$shop_domain = str_replace( 'www.', '', $site_url['host'] );

			// torob verify token url
			$endpoint_url = 'https://extractor.torob.com/validate_token/';

			// Get Parameters
			$token = sanitize_text_field( $request->get_param( 'token' ) );

			// Get Headers
			$header = $request->get_header( 'X-Authorization' );
			if ( empty( $header ) ) {
				$header = $request->get_header( 'Authorization' );
			}

			// Verify token
			return wp_safe_remote_post( $endpoint_url, array(
					'method'      => 'POST',
					'timeout'     => 12,
					'redirection' => 0,
					'httpversion' => '1.1',
					'blocking'    => true,
					'headers'     => array(
						'AUTHORIZATION' => $header,
					),
					'body'        => array(
						'token'       => $token,
						'shop_domain' => $shop_domain,
						'version'     => $this->plugin_version
					),
					'cookies'     => array()
				) );
		}


		/**
		 * Get single product values
		 */
		public function get_product_values( $product, $is_child = false ) {
			$temp_product = new stdClass();
			if ( $is_child ) {
				$parent                  = wc_get_product( $product->get_parent_id() );
				$temp_product->title     = $parent->get_name();
				$temp_product->subtitle  = get_post_meta( $product->get_parent_id(), 'product_english_name', true );
				$cat_ids                 = $parent->get_category_ids();
				$temp_product->parent_id = $parent->get_id();
			} else {
				$temp_product->title     = $product->get_name();
				$temp_product->subtitle  = get_post_meta( $product->get_id(), 'product_english_name', true );
				$cat_ids                 = $product->get_category_ids();
				$temp_product->parent_id = 0;
			}
			$temp_product->page_unique   = $product->get_id();
			$temp_product->current_price = $product->get_price();
			$temp_product->old_price     = $product->get_regular_price();
			$temp_product->availability  = $product->get_stock_status();
			$temp_product->category_name = get_term_by( 'id', end( $cat_ids ), 'product_cat', 'ARRAY_A' )['name'];
			$temp_product->image_links   = [];
			$attachment_ids              = $product->get_gallery_image_ids();
			foreach ( $attachment_ids as $attachment_id ) {
				$t_link = wp_get_attachment_image_src( $attachment_id, 'full' );
				if ( $t_link ) {
					$temp_product->image_links[] = $t_link[0];
				}
			}
			$t_image = wp_get_attachment_image_src( $product->get_image_id(), 'full' );
			if ( $t_image ) {
				$temp_product->image_link = $t_image[0];
				if ( ! in_array( $t_image[0], $temp_product->image_links ) ) {
					$temp_product->image_links[] = $t_image[0];
				}
			} else {
				$temp_product->image_link = null;
			}
			$temp_product->page_url   = get_permalink( $product->get_id() );
			$temp_product->short_desc = $product->get_short_description();
			$temp_product->spec       = array();
			$temp_product->date       = $product->get_date_created();
			$temp_product->guarantee  = '';

			if ( ! $is_child ) {
				if ( $product->is_type( 'variable' ) ) {
					// Set prices to 0 then calculate them
					$temp_product->current_price = 0;
					$temp_product->old_price     = 0;

					// Find price for default attributes. If it can't find return max price of variations
					$variation_id = $this->find_matching_variation( $product, $product->get_default_attributes() );
					if ( $variation_id != 0 ) {
						$variation                   = wc_get_product( $variation_id );
						$temp_product->current_price = $variation->get_price();
						$temp_product->old_price     = $variation->get_regular_price();
						$temp_product->availability  = $variation->get_stock_status();
					} else {
						$temp_product->current_price = $product->get_variation_price( 'max' );
						$temp_product->old_price     = $product->get_variation_regular_price( 'max' );
					}

					// Extract default attributes
					foreach ( $product->get_default_attributes() as $key => $value ) {
						if ( ! empty( $value ) ) {
							if ( substr( $key, 0, 3 ) === 'pa_' ) {
								$value = get_term_by( 'slug', $value, $key );
								if ( $value ) {
									$value = $value->name;
								} else {
									$value = '';
								}
								$key                                     = wc_attribute_label( $key );
							}
							$temp_product->spec[ urldecode( $key ) ] = rawurldecode( $value );
						}
					}
				}
				// add remain attributes
				foreach ( $product->get_attributes() as $attribute ) {
					if ( $attribute['visible'] == 1 ) {
						$name = wc_attribute_label( $attribute['name'] );
						if ( substr( $attribute['name'], 0, 3 ) === 'pa_' ) {
							$values = wc_get_product_terms( $product->get_id(), $attribute['name'], array( 'fields' => 'names' ) );
						} else {
							$values = $attribute['options'];
						}
						if ( ! array_key_exists( $name, $temp_product->spec ) ) {
							$temp_product->spec[ $name ] = implode( ', ', $values );
						}
					}
				}
			} else {
				foreach ( $product->get_attributes() as $key => $value ) {
					if ( ! empty( $value ) ) {
						if ( substr( $key, 0, 3 ) === 'pa_' ) {
							$value = get_term_by( 'slug', $value, $key );
							if ( $value ) {
								$value = $value->name;
							} else {
								$value = '';
							}
							$key                                     = wc_attribute_label( $key );
						}
						$temp_product->spec[ urldecode( $key ) ] = rawurldecode( $value );
					}
				}
			}

			$guarantee_keys = [
				"گارانتی",
				"guarantee",
				"warranty",
				"garanty",
				"گارانتی:",
				"گارانتی محصول",
				"گارانتی محصول:",
				"ضمانت",
				"ضمانت:"
			];

			foreach ( $guarantee_keys as $guarantee ) {
				if ( ! empty( $temp_product->spec[ $guarantee ] ) ) {
					$temp_product->guarantee = $temp_product->spec[ $guarantee ];
				}
			}

			if ( ! array_key_exists( 'شناسه کالا', $temp_product->spec ) ) {
				$sku = $product->get_sku();
				if ( $sku != "" ) {
					$temp_product->spec['شناسه کالا'] = $sku;
				}
			}

			if ( count( $temp_product->spec ) > 0 ) {
				$temp_product->spec = [ $temp_product->spec ];
			}

			return $temp_product;
		}

		/**
		 * Get all products
		 *
		 * @param bool $show_variations Whether to include product variations.
		 * @param int $limit The number of products to retrieve per page.
		 * @param int $page The current page number.
		 *
		 * @return array The data containing products, count, and max pages.
		 */
		private function get_all_products( $show_variations, $limit, $page ) {
			if ( $show_variations ) {
				// Make query
				$query    = new WP_Query( array(
					'posts_per_page' => $limit,
					'paged'          => $page,
					'post_status'    => 'publish',
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'post_type'      => array( 'product', 'product_variation' ),
				) );
			} else {
				// Make query
				$query    = new WP_Query( array(
					'posts_per_page' => $limit,
					'paged'          => $page,
					'post_status'    => 'publish',
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'post_type'      => array( 'product' ),
				) );
			}
			$products = $query->get_posts();

			// Count products
			$data['count'] = $query->found_posts;

			// Total pages
			$data['max_pages'] = $query->max_num_pages;

			$data['products'] = array();

			// Retrieve and send data in json
			foreach ( $products as $product ) {
				$product   = wc_get_product( $product->ID );
				$parent_id = $product->get_parent_id();
				// Process for parent product
				if ( $parent_id == 0 ) {
					// Exclude the variable product. (variations of it will be inserted.)
					if ( $show_variations ) {
						if ( ! $product->is_type( 'variable' ) ) {
							$data['products'][]       = $this->get_product_values( $product );
						}
					} else {
						$data['products'][]       = $this->get_product_values( $product );
					}
				} else {
					// Process for visible child
					if ( $product->get_price() ) {
						$data['products'][]       = $this->get_product_values( $product, true );
					}
				}
			}

			return $data;
		}

		/**
		  * Get a list of products by their IDs.
		  *
		  * @param array $product_list An array of product IDs to retrieve.
		  *
		  * @return array The list of products with their details.
		  */
		 private function get_list_products( $product_list ) {
			$data['products'] = array();

			// Retrieve and send data in json
			foreach ( $product_list as $pid ) {
				$product = wc_get_product( $pid );
				if ( $product && $product->get_status() === "publish" ) {
					$parent_id = $product->get_parent_id();
					// Process for parent product
					if ( $parent_id == 0 ) {
						$data['products'][]       = $this->get_product_values( $product );
					} else {
						// Process for visible child
						if ( $product->get_price() ) {
							$data['products'][]       = $this->get_product_values( $product, true );
						}
					}
				}
			}

			return $data;
		}

		/**
		 * Get a list of slugs and retrieve product data by their links.
		 *
		 * @param array $slug_list An array of product slugs to retrieve.
		 *
		 * @return array The list of products with their details.
		 */
		private function get_list_slugs( $slug_list ) {
			$data['products'] = array();

			// Retrieve and send data in json
			foreach ( $slug_list as $sid ) {
				$product = get_page_by_path( $sid, OBJECT, 'product' );
				if ( $product && $product->post_status === "publish" ) {
					$temp_product       = $this->get_product_values( wc_get_product( $product->ID ) );
					$data['products'][] = $this->prepare_response_for_collection( $temp_product );
				}
			}

			return $data;
		}

		/**
		 * Get all or a collection of products
		 *
		 * @param WP_REST_Request $request Full data about the request.
		 *
		 * @return WP_REST_Response
		 */
		public function get_products( $request ) {
			// Get Parameters
			$show_variations = rest_sanitize_boolean( $request->get_param( 'variation' ) );
			$limit           = intval( $request->get_param( 'limit' ) );
			$page            = intval( $request->get_param( 'page' ) );
			if ( ! empty( $request->get_param( 'products' ) ) ) {
				$product_list = explode( ',', ( sanitize_text_field( $request->get_param( 'products' ) ) ) );
				if ( is_array( $product_list ) ) {
					foreach ( $product_list as $key => $field ) {
						$product_list[ $key ] = intval( $field );
					}
				}
			}
			if ( ! empty( $request->get_param( 'slugs' ) ) ) {
				$slug_list = explode( ',', ( sanitize_text_field( urldecode( $request->get_param( 'slugs' ) ) ) ) );
			}

			$data = array();
			// Check request is valid and update
			$response = $this->check_request( $request );
			if ( ! is_array( $response ) ) {
				$data['response'] = '';
				$data['error']    = $response;
				$response_code    = 500;
			} else {
				$response_body = $response['body'];
				$response      = json_decode( $response_body, true );

				if ( $response['success'] === true && $response['message'] === 'the token is valid' ) {
					if ( ! empty( $product_list ) ) {
						$data = $this->get_list_products( $product_list );
					} elseif ( ! empty( $slug_list ) ) {
						$data = $this->get_list_slugs( $slug_list );
					} else {
						$data = $this->get_all_products( $show_variations, $limit, $page );
					}
					$data['metadata'] = array(
						'wordpress_version'   => get_bloginfo( 'version' ),
						'php_version'         => $this->php_version(),
						'plugin_version'      => $this->plugin_version,
						'woocommerce_version' => $this->woocommerce_version(),
					);
					$response_code = 200;
				} else {
					$data['response'] = $response_body;
					$data['error']    = $response['error'];
					$response_code    = 401;
				}
			}

			return new WP_REST_Response( $data, $response_code );
		}
	}

	$WC_Products_Extractor = new WC_Products_Extractor;
}
