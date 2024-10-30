<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Kerusso_Dropshipping_Import_Products {

	var $remote_url;
	var $file_name;
	var $file_path;
	var $fields;
	var $columns;
	var $products;
	var $kerusso_products;
	var $kerusso_products_status;
	var $kerusso_products_removed;
	var $kerusso_parent_products;
	var $updated_products;
	
	function __construct() {
		$upload_dir = wp_upload_dir();
		$data_dir = $upload_dir['basedir'].'/kerusso-dropshipping-for-woocommerce';

		$this->remote_url = 'http://feed.kerussomarketing.com/inventory/kerusso-websellable.csv';
		$this->file_name = 'kerusso-products.csv';
		$this->file_path = $data_dir . '/' . $this->file_name;

		$this->fields = array(
			'parentsku' => 'psku',
			'variationsku' => 'guid',
			'size' => 'size',
			'productname' => 'name',
			'partdescription' => 'item-name',
			'upccode1' => 'upc',
			'color' => 'color',
			'brand' => 'brand',
			'listprice' => 'msrp',
			'netweight' => 'weight',
			'description' => 'description',
			'imageurl' => 'image1',
			'secondaryimage1' => 'image2',
			'secondaryimage2' => 'image3',
			'secondaryimage3' => 'image4',
			'secondaryimage4' => 'image5',
			'secondaryimage5' => 'image6',
			'status' => 'status',
			'category' => 'category',
			'secondarycategory' => 'secondary-categories',
			'onhandqty' => 'qty',
			'dateadded' => 'date-added'
		);

		$this->columns = array();
		$this->products = array();
		$this->kerusso_products = array();
		$this->kerusso_products_status = array();
		$this->kerusso_products_removed = array();
		$this->kerusso_parent_products = array();
		$this->updated_products = array();
	}

	function download_product_csv() {
		if (file_exists($this->file_path)) unlink($this->file_path);

		$http = wp_remote_get($this->remote_url);
		if (isset($http['response']['code']) && $http['response']['code'] != '200') return new WP_Error('KDS_NON200', 'Remote server error. Please try again later.');

		$data = (isset($http['body'])) ? $http['body'] : '';
		if (!$data) return new WP_Error('KDS_EMPTY', 'Failed to retrieve product data. Please try again later.');

		if (!file_put_contents($this->file_path, $data)) return new WP_Error('KDS_WRITE_FAIL', 'Failed to write temporary product file. Please check file permissions.');

		return true;
	}

	function extract_product_data() {
		$this->products = array();

		if (($handle = fopen($this->file_path, "r")) === false) return new WP_Error('KDS_FOPEN_FAIL', 'Failed to open product file.');
		if (($headers = fgetcsv($handle)) === false) return new WP_Error('KDS_PFILE_INVALID', 'Product file is invalid.');
		if (!$headers) return new WP_Error('KDS_PFILE_NO_HEADERS', 'Product file headers not found.');

		foreach ($headers as $column_number => $header_name) {
			$header_name = strtolower($header_name);
			if (isset($this->fields[$header_name])) $this->columns[$column_number] = $this->fields[$header_name];
		}

		$row_number = 0;
		while (($row = fgetcsv($handle)) !== false) {
			if (!$row) continue;
			$row_number++;

			foreach ($row as $column_number => $data) {
				$field_name = (isset($this->columns[$column_number])) ? $this->columns[$column_number] : '';
				if (!$field_name) continue;

				if ($field_name == 'description') {
					$data = trim($data, ' "');
					$data = str_replace('""', '"', $data);
				}

				$this->products[$row_number][$field_name] = $data;
			}
		}

		fclose($handle);

		foreach ($this->products as $number => $product) {
			if ((!isset($product['item-name']) || !$product['item-name']) && isset($product['name'])) {
				$this->products[$number]['item-name'] = $this->products[$number]['name'];
			}
		}

		return true;
	}

	function get_kerusso_products() {
		global $wpdb;

		$this->kerusso_products_status = array();
		$this->kerusso_products_removed = array();
		$this->kerusso_parent_products = array();

		$products = array();

		$posts = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_kds_catalog_name'");
		if (!$posts) return array();

		$post_ids = array();
		foreach ($posts as $post) {
			$post_ids[] = $post->post_id;
		}
		$post_ids = array_unique($post_ids);
		$id_str = implode(',',$post_ids);

		$posts = $wpdb->get_results("SELECT ID, post_parent FROM $wpdb->posts WHERE post_parent IN ($id_str)");

		if ($posts) {
			foreach ($posts as $post) {
				$post_ids[] = $post->ID;
				$this->kerusso_parent_products[$post->post_parent][] = $post->ID;
			}
			$post_ids = array_unique($post_ids);
			$id_str = implode(',',$post_ids);
		}

		$skus = $wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_sku' AND post_id IN (" . esc_sql($id_str) . ")");
		if (!$skus) return array();

		foreach ($skus as $sku) {
			$products[$sku->post_id] = $sku->meta_value;
		}

		$this->kerusso_products = $products;
	}

	function check_for_discontinued_products() {
		global $kerusso_dropshipping;

		$this->get_kerusso_products();
		if (!$this->kerusso_products) return true;
		if (!$this->products) return true;

		foreach ($this->kerusso_products as $product_id => $sku) {
			if (isset($this->kerusso_parent_products[$product_id])) continue;

			foreach ($this->products as $product) {
				if ($product['guid'] == $sku) {
					if (strtoupper($product['status']) == 'D') {
						break;
					} else {
						$this->kerusso_products_status[$product_id] = true;
						continue(2);
					}
				}
			}

			$this->kerusso_products_status[$product_id] = false;
			$this->disable($product_id);
			$kerusso_dropshipping->stats['disabled']++;
		}

		if ($this->kerusso_parent_products) {
			foreach ($this->kerusso_parent_products as $product_id => $variations) {
				$disable = true;

				if ($variations) {
					foreach ($variations as $variation_id) {
						if (isset($this->kerusso_products_status[$variation_id])) {
							if ($this->kerusso_products_status[$variation_id]) $disable = false;
						}
					}
				}

				if ($disable) {
					$this->kerusso_products_status[$product_id] = false;
					$this->disable($product_id);
				}
			}
		}

		return true;
	}

	function update_inventory_counts() {
		global $kerusso_dropshipping, $wpdb;

		if (!$this->kerusso_products) $this->get_kerusso_products();
		if (!$this->kerusso_products) return true;
		if (!$this->products) return true;

		foreach ($this->products as $product) {
			$product_id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = '" . esc_sql($product['guid']) . "' LIMIT 1");
			if (!$product_id) continue;
			update_post_meta($product_id, '_stock', $product['qty']);
			$kerusso_dropshipping->stats['stock']++;
		}

		return true;
	}

	function set_product_type($post_id, $type = 'simple') {
		global $wpdb;

		$term_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '" . esc_sql($type) . "'");
		if (!$term_id) return false;

		$term_taxonomy_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'product_type'");
		if (!$term_taxonomy_id) return false;

		$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_id, $term_taxonomy_id)");

		return true;
	}

	function create_product_category($term, $parent = 0) {
		global $wpdb;

		$term = trim($term);
		$parent = abs(intval($parent));

		$term_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '" . esc_sql($term) . "'");

		if (!$term_id) {
			$count = 1;
			$slug = sanitize_title($term);

			do {
				if ($count > 1) $slug = sanitize_title($term) . '-' . $count;
				$slug_exists = $wpdb->get_var("SELECT slug FROM $wpdb->terms WHERE slug = '" . esc_sql($slug) . "'");
				$count++;
			} while ($slug_exists);

			if (!$wpdb->query("INSERT INTO $wpdb->terms (name, slug) VALUES ('$term', '$slug')")) return false;
			$term_id = $wpdb->insert_id;
		}

		$term_taxonomy_info = $wpdb->get_row("SELECT term_taxonomy_id, parent FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'product_cat'");

		if (!$term_taxonomy_info) {
			if (!$wpdb->query("INSERT INTO $wpdb->term_taxonomy (term_id, taxonomy, parent) VALUES ($term_id, 'product_cat', $parent)")) return false;
			$term_taxonomy_id = $wpdb->insert_id;
		} elseif ($term_taxonomy_info->parent != $parent) {
			return false;
		} else {
			$term_taxonomy_id = $term_taxonomy_info->term_taxonomy_id;
		}

		return $term_taxonomy_id;
	}

	function find_or_create_category($category_string) {
		global $kerusso_dropshipping;

		$category_string = trim($category_string);
		if (!$category_string) return false;

		while (strpos($category_string, '//') !== false) {
			$category_string = str_replace('//', '/', $category_string);
		}

		$category_tree = explode('/', $category_string);
		if (!$category_tree) return false;

		$parent_id = 0;
		foreach ($category_tree as $category_name) {
			$parent_id = $this->create_product_category($category_name, $parent_id);
			if (!$parent_id) { /* $kerusso_dropshipping->write_to_log('Failed to create categories: ' . $category_string); */ return false; }
		}
		return $parent_id;
	}

	// Modified version of media_sideload_image()
	function upload_image($url, $post_id) {
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$tmp = download_url( $url );
		if( is_wp_error( $tmp ) ){
			return false;
		}

		$file_array = array();

		// Set variables for storage
		// fix file filename for query strings
		preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $url, $matches);
		$file_array['name'] = basename($matches[0]);
		$file_array['tmp_name'] = $tmp;

		// If error storing temporarily, unlink
		if ( is_wp_error( $tmp ) ) {
			@unlink($file_array['tmp_name']);
			return false;
		}

		// do the validation and storage stuff
		$id = media_handle_sideload( $file_array, $post_id );

		// If error storing permanently, unlink
		if ( is_wp_error($id) ) {
			@unlink($file_array['tmp_name']);
		}

		return $id;
	}

	function add_product($product, $is_parent = false) {
		global $kerusso_dropshipping, $wpdb;
		$discount_type = $kerusso_dropshipping->get_discount_type();
		$discount_value = $kerusso_dropshipping->get_discount_value();
		$post_parent = 0;
		$minimum_price = 3.00;

		if (!isset($product['name']) || !$product['name']) return false;
		$is_variation = ($product['psku'] != $product['guid']) ? true : false;

		// Find or create the parent product
		if ($is_variation) {
			$post_parent = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = '" . esc_sql($product['psku']) . "'");
			if (!$post_parent) {
				$parent_product = $product;
				$parent_product['guid'] = $parent_product['psku'];
				$post_parent = $this->add_product($parent_product, true);
				if (!$post_parent) return false;
			}
		}

		// Insert post
		if (!$is_variation) {
			$post_data = array(
				'post_content' => $product['description'],
				'post_title' => $product['name'],
				'post_status' => ($product['status'] == 'D') ? 'private' : 'publish',
				'post_type' => 'product'
			);
			$post_id = wp_insert_post($post_data);
			if (!$post_id) return false;
		} else {
			$post_data = array(
				'post_content' => '',
				'post_title' => $product['name'] . ' Variation',
				'post_status' => ($product['status'] == 'D') ? 'private' : 'publish',
				'post_parent' => $post_parent,
				'post_type' => 'product_variation'
			);
			$post_id = wp_insert_post($post_data);
			if (!$post_id) return false;
		}

		// Set the product type
		if (!$is_variation) {
			if ($is_parent) {
				$this->set_product_type($post_id, 'variable');
			} else {
				$this->set_product_type($post_id, 'simple');
			}
		}

		$price = $product['msrp'];
		if ($discount_type == 'fixed') {
			$price = floatval($price) - abs(floatval($discount_value));
			if ($price < $minimum_price) $price = $minimum_price;
		} elseif ($discount_type == 'percentage') {
			if ($discount_value > 100) $discount_value = 100;
			if ($discount_value < 0) $discount_value = 0;
			$price = $price - ($price * (abs($discount_value) / 100));
			if ($price < $minimum_price) $price = $minimum_price;
		}

		// Set the standard meta fields
		if (!$is_variation) {
			update_post_meta($post_id, '_visibility', 'visible');
			update_post_meta($post_id, 'total_sales', '0');
			update_post_meta($post_id, '_featured', 'no');

			if ($is_parent) {
				$product_attributes = array();
				if ($product['size']) {
					$product_attributes['pa_size'] = array(
						'name' => 'pa_size',
						'value' => '',
						'position' => 0,
						'is_visible' => 0,
						'is_variation' => 1,
						'is_taxonomy' => 1
					);
				}
				if ($product['color']) {
					$product_attributes['pa_color'] = array(
						'name' => 'pa_color',
						'value' => '',
						'position' => 0,
						'is_visible' => 0,
						'is_variation' => 1,
						'is_taxonomy' => 1
					);
				}
				update_post_meta($post_id, '_product_attributes', $product_attributes);
			}
		}

		update_post_meta($post_id, '_sku', $product['guid']);

		if ($is_variation && $product['size']) {
			$size = trim(strtolower($product['size']));
			update_post_meta($post_id, 'attribute_pa_size', $size);

			$term_id = $term_taxonomy_id = 0;
			$the_term = term_exists($size, 'pa_size');

			if (is_array($the_term) && isset($the_term['term_taxonomy_id'])) {
				$term_taxonomy_id = $the_term['term_taxonomy_id'];
			} else {
				if (!is_array($the_term) || !isset($the_term['term_id'])) { 
					wp_insert_term($size, 'pa_size');
					$term_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '" . esc_sql($size) . "'");
				} else {
					$term_id = $the_term['term_id'];
				}
				if ($term_id) $term_taxonomy_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'pa_size'");
			}

			if ($term_taxonomy_id) {
				if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_parent AND term_taxonomy_id = $term_taxonomy_id")) {
					$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_parent, $term_taxonomy_id)");
				}
			}
		}

		if ($is_variation && $product['color']) {
			$color = trim(strtolower($product['color']));
			update_post_meta($post_id, 'attribute_pa_color', $color);

			$term_id = $term_taxonomy_id = 0;
			$the_term = term_exists($color, 'pa_color');

			if (is_array($the_term) && isset($the_term['term_taxonomy_id'])) {
				$term_taxonomy_id = $the_term['term_taxonomy_id'];
			} else {
				if (!is_array($the_term) || !isset($the_term['term_id'])) { 
					wp_insert_term($color, 'pa_color');
					$term_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '" . esc_sql($color) . "'");
				} else {
					$term_id = $the_term['term_id'];
				}
				if ($term_id) $term_taxonomy_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'pa_color'");
			}

			if ($term_taxonomy_id) {
				if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_parent AND term_taxonomy_id = $term_taxonomy_id")) {
					$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_parent, $term_taxonomy_id)");
				}
			}
		}

		$stock = ($is_parent) ? '' : $product['qty'];

		update_post_meta($post_id, '_virtual', 'no');
		update_post_meta($post_id, '_downloadable', 'no');
		update_post_meta($post_id, '_weight', $product['weight']);
		update_post_meta($post_id, '_length', '');
		update_post_meta($post_id, '_width', '');
		update_post_meta($post_id, '_height', '');
		update_post_meta($post_id, '_manage_stock', 'yes');
		update_post_meta($post_id, '_stock_status', 'instock');
		update_post_meta($post_id, '_backorders', 'no');
		update_post_meta($post_id, '_stock', $stock);
		update_post_meta($post_id, '_regular_price', $product['msrp']);
		update_post_meta($post_id, '_sale_price', '');
		update_post_meta($post_id, '_sale_price_dates_from', '');
		update_post_meta($post_id, '_sale_price_dates_to', '');
		update_post_meta($post_id, '_price', $price);
		update_post_meta($post_id, '_download_limit', '');
		update_post_meta($post_id, '_download_expiry', '');
		update_post_meta($post_id, '_downloadable_files', '');

		// Set the featured image
		if ($product['image1']) {
			$thumbnail_id = $this->upload_image($product['image1'], $post_id);
			if ($thumbnail_id) update_post_meta($post_id, '_thumbnail_id', $thumbnail_id);
		}

		if (!$is_variation) {
			// Set the additional fields
			if (!isset($product['item-name']) || !$product['item-name']) $product['item-name'] = 'Undefined';
			update_post_meta($post_id, '_kds_catalog_name', $product['item-name']);

			if ($product['upc']) update_post_meta($post_id, 'upc', $product['upc']);
			if ($product['brand']) update_post_meta($post_id, 'brand', $product['brand']);

			$categories_set = array();

			// Set the category
			if ($product['category']) {
				$product['category'] = apply_filters('kerusso_dropshipping_category_filter', $product['category']);
				$term_taxonomy_id = $this->find_or_create_category($product['category']);
				if ($term_taxonomy_id) {
					if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_id AND term_taxonomy_id = $term_taxonomy_id")) {
						$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_id, $term_taxonomy_id)");
						$categories_set[$term_taxonomy_id] = true;
					}
				}
			}

			// Set the secondary categories
			if ($product['secondary-categories']) {
				$secondary_categories = explode(';', $product['secondary-categories']);
				foreach ($secondary_categories as $secondary_cat) {
					$secondary_cat = apply_filters('kerusso_dropshipping_category_filter', $secondary_cat);
					$term_taxonomy_id = $this->find_or_create_category($secondary_cat);
					if ($term_taxonomy_id && !isset($categories_set[$term_taxonomy_id])) {
						if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_id AND term_taxonomy_id = $term_taxonomy_id")) {
							$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_id, $term_taxonomy_id)");
							$categories_set[$term_taxonomy_id] = true;
						}
					}
				}
			}

			// Set the secondary images
			$image_ids = array();

			for ($i = 2; $i < 7; $i++) {
				if ($product['image'.$i]) {
					$image_id = $this->upload_image($product['image'.$i], $post_id);
					if ($image_id) $image_ids[] = $image_id;
				}
			}

			if ($image_ids) update_post_meta($post_id, '_product_image_gallery', implode(',', $image_ids));
		}

		if (!$is_parent) $kerusso_dropshipping->stats['added']++;
		return $post_id;
	}

	function update_product($product_id, $product, $is_parent = false) {
		global $kerusso_dropshipping, $wpdb;
		$discount_type = $kerusso_dropshipping->get_discount_type();
		$discount_value = $kerusso_dropshipping->get_discount_value();
		$post_parent = 0;
		$minimum_price = 3.00;

		$this->updated_products[$product_id] = true;

		if (!isset($product['name']) || !$product['name']) return false;
		$is_variation = ($product['psku'] != $product['guid']) ? true : false;

		// Find and update the parent product
		if ($is_variation) {
			$post_parent = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = '" . esc_sql($product['psku']) . "'");
			if ($post_parent && !isset($this->updated_products[$post_parent])) {
				$parent_product = $product;
				$parent_product['guid'] = $parent_product['psku'];
				$post_parent = $this->update_product($post_parent, $parent_product, true);
			}
		}

		// Update post title & content
		if (!$is_variation) {
			$post_data = array(
				'ID' => $product_id,
				'post_content' => $product['description'],
				'post_title' => $product['name'],
			);
			wp_update_post($post_data);
		} else {
			$post_data = array(
				'ID' => $product_id,
				'post_content' => '',
				'post_title' => $product['name'] . ' Variation',
			);
			wp_update_post($post_data);
		}

		$post_id = $product_id;

		$price = $product['msrp'];
		if ($discount_type == 'fixed') {
			$price = floatval($price) - abs(floatval($discount_value));
			if ($price < $minimum_price) $price = $minimum_price;
		} elseif ($discount_type == 'percentage') {
			if ($discount_value > 100) $discount_value = 100;
			if ($discount_value < 0) $discount_value = 0;
			$price = $price - ($price * (abs($discount_value) / 100));
			if ($price < $minimum_price) $price = $minimum_price;
		}

		// Set the standard meta fields
		if (!$is_variation) {
			update_post_meta($post_id, '_visibility', 'visible');

			if ($is_parent) {
				$product_attributes = array();
				if ($product['size']) {
					$product_attributes['pa_size'] = array(
						'name' => 'pa_size',
						'value' => '',
						'position' => 0,
						'is_visible' => 0,
						'is_variation' => 1,
						'is_taxonomy' => 1
					);
				}
				if ($product['color']) {
					$product_attributes['pa_color'] = array(
						'name' => 'pa_color',
						'value' => '',
						'position' => 0,
						'is_visible' => 0,
						'is_variation' => 1,
						'is_taxonomy' => 1
					);
				}
				update_post_meta($post_id, '_product_attributes', $product_attributes);
			}
		}

		if ($is_variation) {
			$update_size = false;
			$previous_size = get_post_meta($post_id, 'attribute_pa_size', true);

			if ($previous_size) {
				$previous_size = trim(strtolower($previous_size));
				$size = trim(strtolower($product['size']));
				if ($previous_size != $size) {
					$update_size = true;

					$the_term = term_exists($previous_size, 'pa_size');
					$term_taxonomy_id = (is_array($the_term) && isset($the_term['term_taxonomy_id'])) ? $the_term['term_taxonomy_id'] : 0;

					if ($term_taxonomy_id) $wpdb->query("DELETE FROM $wpdb->term_relationships WHERE object_id = $post_parent AND term_taxonomy_id = $term_taxonomy_id");
				}
			}

			if ($update_size && $product['size']) {
				$size = trim(strtolower($product['size']));
				update_post_meta($post_id, 'attribute_pa_size', $size);

				$term_id = $term_taxonomy_id = 0;
				$the_term = term_exists($size, 'pa_size');

				if (is_array($the_term) && isset($the_term['term_taxonomy_id'])) {
					$term_taxonomy_id = $the_term['term_taxonomy_id'];
				} else {
					if (!is_array($the_term) || !isset($the_term['term_id'])) { 
						wp_insert_term($size, 'pa_size');
						$term_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '" . esc_sql($size) . "'");
					} else {
						$term_id = $the_term['term_id'];
					}
					if ($term_id) $term_taxonomy_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'pa_size'");
				}

				if ($term_taxonomy_id) {
					if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_parent AND term_taxonomy_id = $term_taxonomy_id")) {
						$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_parent, $term_taxonomy_id)");
					}
				}
			}

			$update_color = false;
			$previous_color = get_post_meta($post_id, 'attribute_pa_color', true);

			if ($previous_color) {
				$previous_color = trim(strtolower($previous_color));
				$color = trim(strtolower($product['color']));
				if ($previous_color != $color) {
					$update_color = true;

					$the_term = term_exists($previous_color, 'pa_color');
					$term_taxonomy_id = (is_array($the_term) && isset($the_term['term_taxonomy_id'])) ? $the_term['term_taxonomy_id'] : 0;

					if ($term_taxonomy_id) $wpdb->query("DELETE FROM $wpdb->term_relationships WHERE object_id = $post_parent AND term_taxonomy_id = $term_taxonomy_id");
				}
			}

			if ($update_color && $product['color']) {
				$color = trim(strtolower($product['color']));
				update_post_meta($post_id, 'attribute_pa_color', $color);

				$term_id = $term_taxonomy_id = 0;
				$the_term = term_exists($color, 'pa_color');

				if (is_array($the_term) && isset($the_term['term_taxonomy_id'])) {
					$term_taxonomy_id = $the_term['term_taxonomy_id'];
				} else {
					if (!is_array($the_term) || !isset($the_term['term_id'])) { 
						wp_insert_term($color, 'pa_color');
						$term_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '" . esc_sql($color) . "'");
					} else {
						$term_id = $the_term['term_id'];
					}
					if ($term_id) $term_taxonomy_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'pa_color'");
				}

				if ($term_taxonomy_id) {
					if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_parent AND term_taxonomy_id = $term_taxonomy_id")) {
						$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_parent, $term_taxonomy_id)");
					}
				}
			}
		}

		$stock = ($is_parent) ? '' : $product['qty'];

		update_post_meta($post_id, '_virtual', 'no');
		update_post_meta($post_id, '_downloadable', 'no');
		update_post_meta($post_id, '_weight', $product['weight']);
		update_post_meta($post_id, '_manage_stock', 'yes');
		update_post_meta($post_id, '_stock_status', 'instock');
		update_post_meta($post_id, '_backorders', 'no');
		update_post_meta($post_id, '_stock', $stock);
		update_post_meta($post_id, '_regular_price', $product['msrp']);
		update_post_meta($post_id, '_price', $price);
		update_post_meta($post_id, '_download_limit', '');
		update_post_meta($post_id, '_download_expiry', '');
		update_post_meta($post_id, '_downloadable_files', '');

		// Remove the existing product images
		$featured_image = get_post_meta($post_id, '_thumbnail_id', true);
		$secondary_images = get_post_meta($post_id, '_product_image_gallery', true);

		$product_images = array();
		if ($secondary_images) $product_images = explode(',', $scondary_images);
		if ($featured_image) $product_images[] = $featured_image;

		if ($product_images) {
			foreach ($product_images as $product_image) {
				wp_delete_attachment($product_image, true);
			}
		}

		// Set the featured image
		if ($product['image1']) {
			$thumbnail_id = $this->upload_image($product['image1'], $post_id);
			if ($thumbnail_id) update_post_meta($post_id, '_thumbnail_id', $thumbnail_id);
		}

		if (!$is_variation) {
			// Set the additional fields
			if (!isset($product['item-name']) || !$product['item-name']) $product['item-name'] = 'Undefined';
			update_post_meta($post_id, '_kds_catalog_name', $product['item-name']);

			if ($product['upc']) update_post_meta($post_id, 'upc', $product['upc']);
			if ($product['brand']) update_post_meta($post_id, 'brand', $product['brand']);

			$categories_set = array();

			// Remove the existing category associations
			$relationship_query = $wpdb->get_results("SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id = $post_id AND term_taxonomy_id IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'product_cat')");
			if ($relationship_query) {
				foreach ($relationship_query as $term_relationship) {
					$categories[] = $term_relationship->term_taxonomy_id;
				}
				$cat_ids = array_unique($categories);
				$cat_str = implode(',',$cat_ids);

				if ($cat_str) {
					$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE object_id = $post_id AND term_taxonomy_id IN ($cat_str)");
					$wpdb->query("DELETE FROM $wpdb->term_taxonomy WHERE taxonomy = 'product_cat' AND term_taxonomy_id IN ($cat_str) AND term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM $wpdb->term_relationships)");
				}
			}

			// Set the category
			if ($product['category']) {
				$product['category'] = apply_filters('kerusso_dropshipping_category_filter', $product['category']);
				$term_taxonomy_id = $this->find_or_create_category($product['category']);
				if ($term_taxonomy_id) {
					if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_id AND term_taxonomy_id = $term_taxonomy_id")) {
						$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_id, $term_taxonomy_id)");
						$categories_set[$term_taxonomy_id] = true;
					}
				}
			}

			// Set the secondary categories
			if ($product['secondary-categories']) {
				$secondary_categories = explode(';', $product['secondary-categories']);
				foreach ($secondary_categories as $secondary_cat) {
					$secondary_cat = apply_filters('kerusso_dropshipping_category_filter', $secondary_cat);
					$term_taxonomy_id = $this->find_or_create_category($secondary_cat);
					if ($term_taxonomy_id && !isset($categories_set[$term_taxonomy_id])) {
						if (!$wpdb->get_var("SELECT object_id FROM $wpdb->term_relationships WHERE object_id = $post_id AND term_taxonomy_id = $term_taxonomy_id")) {
							$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ($post_id, $term_taxonomy_id)");
							$categories_set[$term_taxonomy_id] = true;
						}
					}
				}
			}

			// Set the secondary images
			$image_ids = array();

			for ($i = 2; $i < 7; $i++) {
				if ($product['image'.$i]) {
					$image_id = $this->upload_image($product['image'.$i], $post_id);
					if ($image_id) $image_ids[] = $image_id;
				}
			}

			if ($image_ids) update_post_meta($post_id, '_product_image_gallery', implode(',', $image_ids));
		}

		if (!$is_parent) $kerusso_dropshipping->stats['updated']++;
		return $post_id;
	}

	function import_products() {
		global $kerusso_dropshipping;
		if (!$this->kerusso_products) $this->get_kerusso_products();
		if (!$this->products) return true;

		foreach ($this->products as $product_key => $product) {
			if ($this->kerusso_products) {
				foreach ($this->kerusso_products as $product_id => $sku) {
					if ($product['guid'] == $sku) {
						if (strtoupper($product['status']) == 'A') $this->enable($product_id);
						unset($this->products[$product_key]);
						$kerusso_dropshipping->stats['enabled']++;
						continue(2);
					}
				}
			}
			if ($kerusso_dropshipping->is_brand_enabled($product['brand'])) $this->add_product($product);
		}

		if ($this->kerusso_parent_products) {
			foreach ($this->kerusso_parent_products as $product_id => $variations) {
				$enable = false;

				if ($variations) {
					foreach ($variations as $variation_id) {
						if (isset($this->kerusso_products_status[$variation_id])) {
							if ($this->kerusso_products_status[$variation_id]) $enable = true;
						}
					}
				}

				if ($enable) {
					$this->kerusso_products_status[$product_id] = true;
					$this->enable($product_id);
				}
			}
		}

		return true;
	}

	function update_product_data() {
		global $kerusso_dropshipping;
		if (!$this->kerusso_products) $this->get_kerusso_products();
		if (!$this->products) return true;

		foreach ($this->products as $product_key => $product) {
			if ($this->kerusso_products) {
				foreach ($this->kerusso_products as $product_id => $sku) {
					if ($product['guid'] == $sku) {
						$this->update_product($product_id, $product);
						continue(2);
					}
				}
			}
		}

		return true;
	}

	function remove_disabled_brands() {
		global $kerusso_dropshipping, $wpdb;

		$brands = $kerusso_dropshipping->brands;

		$remove_all = true;
		$keep_all = true;
		$enabled_brand_str = '';
		$disabled_brand_str = '';

		foreach ($brands as $brand) {
			if ($kerusso_dropshipping->is_brand_enabled($brand)) {
				$remove_all = false;
				if ($enabled_brand_str) $enabled_brand_str .= ',';
				$enabled_brand_str .= "'" . esc_sql($brand) . "'";
			} else {
				$keep_all = false;
				if ($disabled_brand_str) $disabled_brand_str .= ',';
				$disabled_brand_str .= "'" . esc_sql($brand) . "'";
			}
		}

		if ($keep_all && $kerusso_dropshipping->is_brand_enabled('other')) return true;

		$posts = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_kds_catalog_name'");
		if ($posts) {
			$post_ids = array();
			foreach ($posts as $post) {
				$post_ids[] = $post->post_id;
			}
			$post_ids = array_unique($post_ids);
			$id_str = implode(',',$post_ids);

			if ($kerusso_dropshipping->is_brand_enabled('other')) {
				$posts = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE post_id IN ($id_str) AND meta_key = 'brand' AND meta_value IN ($disabled_brand_str)");
			} else {
				if (!$remove_all) {
					$posts = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE post_id IN ($id_str) AND meta_key = 'brand' AND meta_value NOT IN ($enabled_brand_str)");
				}
			}

			if ($posts) {
				$post_ids = array();
				foreach ($posts as $post) {
					$post_ids[] = $post->post_id;
				}
				$post_ids = array_unique($post_ids);
				$id_str = implode(',',$post_ids);

				$posts = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_parent IN ($id_str)");

				if ($posts) {
					foreach ($posts as $post) {
						$post_ids[] = $post->ID;
					}
					$post_ids = array_unique($post_ids);
					$id_str = implode(',',$post_ids);
				}

				$term_taxonomy_ids = array();
				$tt_query = $wpdb->get_results("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'product_cat'");
				if ($tt_query) {
					foreach ($tt_query as $term_taxonomy) {
						$term_taxonomy_ids[] = $term_taxonomy->term_taxonomy_id;
					}
					$tt_ids = array_unique($term_taxonomy_ids);
					$tt_str = implode(',',$tt_ids);
				}

				$categories = array();
				if ($term_taxonomy_ids) {
					$relationship_query = $wpdb->get_results("SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id IN ($id_str) AND term_taxonomy_id IN ($tt_str)");
					if ($relationship_query) {
						foreach ($relationship_query as $term_relationship) {
							$categories[] = $term_relationship->term_taxonomy_id;
						}
						$cat_ids = array_unique($categories);
						$cat_str = implode(',',$cat_ids);
					}
				}

				$skus = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND post_id IN (" . esc_sql($id_str) . ")");
				if ($skus) {
					foreach ($skus as $sku) {
						wp_delete_post($sku->post_id, true);
						$kerusso_dropshipping->stats['removed']++;
					}
				}

				if ($categories) {
					$wpdb->query("DELETE FROM $wpdb->term_taxonomy WHERE taxonomy = 'product_cat' AND term_taxonomy_id IN ($cat_str) AND term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM $wpdb->term_relationships)");
					$wpdb->query("DELETE FROM $wpdb->terms WHERE term_id NOT IN (SELECT term_id FROM $wpdb->term_taxonomy)");
				}
			}
		}

		return true;
	}

	function enable($product_id) {
		global $wpdb;
		$wpdb->query("UPDATE $wpdb->posts SET post_status = 'publish' WHERE ID = " . esc_sql($product_id));
		return true;
	}

	function disable($product_id) {
		global $wpdb;
		$wpdb->query("UPDATE $wpdb->posts SET post_status = 'private' WHERE ID = " . esc_sql($product_id));
		return true;
	}

	function disable_all() {
		global $wpdb;
		if (!$this->kerusso_products) $this->get_kerusso_products();
		if (!$this->kerusso_products) return true;

		$ids = implode(',', array_keys($this->kerusso_products));
		$wpdb->query("UPDATE $wpdb->posts SET post_status = 'private' WHERE ID IN (" . esc_sql($ids) . ")");
		return true;
	}

	function has_lock() {
		$lock = get_option('kds_product_lock');

		if ($lock) {
			if (strtotime($lock) > time()) return true;
		}

		return false;
	}

	function get_lock() {
		$lock = get_option('kds_product_lock');

		if ($lock) {
			if (strtotime($lock) > time()) return new WP_Error('KDS_PLOCK', 'A lock is already set for product updates.');
		}

		update_option('kds_product_lock', date('Y-m-d H:i:s', time() + 1800));
		return true;
	}
	
	function release_lock() {
		update_option('kds_product_lock', 0);
		return true;
	}

	function do_daily_updates() {
		global $kerusso_dropshipping;

		@set_time_limit(0);
		$kerusso_dropshipping->reset_stats();

		$result = $this->get_lock();
		if (is_wp_error($result)) { return $result; }

		$result = $this->download_product_csv();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->extract_product_data();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->check_for_discontinued_products();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->update_inventory_counts();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$kerusso_dropshipping->delete_transients('product');

		$this->release_lock();

		return true;
	}

	function do_full_import() {
		global $kerusso_dropshipping, $wpdb;

		@set_time_limit(0);
		$kerusso_dropshipping->reset_stats();

		$result = $this->get_lock();
		if (is_wp_error($result)) { return $result; }

		$result = $this->download_product_csv();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->extract_product_data();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->check_for_discontinued_products();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->update_inventory_counts();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->import_products();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$kerusso_dropshipping->delete_transients('product');

		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name = 'product_cat_children'");

		$product_cats = get_terms( 'product_cat', array( 'hide_empty' => false, 'fields' => 'id=>parent' ) );
		_wc_term_recount( $product_cats, get_taxonomy( 'product_cat' ), true, false );

		$product_tags = get_terms( 'product_tag', array( 'hide_empty' => false, 'fields' => 'id=>parent' ) );
		_wc_term_recount( $product_tags, get_taxonomy( 'product_tag' ), true, false );

		$this->release_lock();

		return true;
	}

	function refresh_products() {
		global $kerusso_dropshipping, $wpdb;

		@set_time_limit(0);
		$kerusso_dropshipping->reset_stats();
		$this->updated_products = array();

		$result = $this->get_lock();
		if (is_wp_error($result)) { return $result; }

		$result = $this->download_product_csv();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->extract_product_data();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->remove_disabled_brands();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->check_for_discontinued_products();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->update_product_data();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$result = $this->import_products();
		if (is_wp_error($result)) { $this->release_lock(); return $result; }

		$kerusso_dropshipping->delete_transients('product');

		$wpdb->query("DELETE FROM $wpdb->terms WHERE term_id NOT IN (SELECT term_id FROM $wpdb->term_taxonomy)");
		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name = 'product_cat_children'");

		$product_cats = get_terms( 'product_cat', array( 'hide_empty' => false, 'fields' => 'id=>parent' ) );
		_wc_term_recount( $product_cats, get_taxonomy( 'product_cat' ), true, false );

		$product_tags = get_terms( 'product_tag', array( 'hide_empty' => false, 'fields' => 'id=>parent' ) );
		_wc_term_recount( $product_tags, get_taxonomy( 'product_tag' ), true, false );

		$this->release_lock();

		return true;
	}
}

?>
