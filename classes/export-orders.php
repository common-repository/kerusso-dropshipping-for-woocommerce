<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Kerusso_Dropshipping_Export_Orders {

	var $transfer_type;
	var $remote_host;
	var $remote_port;
	var $remote_file_name;
	var $username;
	var $password;
	var $file_name;
	var $file_path;
	var $fields;
	var $orders;
	
	function __construct() {
		global $kerusso_dropshipping;

		$upload_dir = wp_upload_dir();
		$data_dir = $upload_dir['basedir'].'/kerusso-dropshipping-for-woocommerce';

		$this->transfer_type = 'ftp';
		$this->remote_host = 'ftp.kerussods.com';
		$this->remote_port = 21;
		$this->remote_file_name = sanitize_title($_SERVER['SERVER_NAME']).'-'.date('YmdHis').'.csv';
		$this->username = $kerusso_dropshipping->get_username();
		$this->password = $kerusso_dropshipping->get_password();
		$this->file_name = $this->remote_file_name;
		$this->file_path = $data_dir . '/' . $this->file_name;

		$this->fields = array(
			'company_id',
			'order_id',
			'date_purchased',
			'customers_email_address',
			'name',
			'delivery_address1',
			'delivery_address2',
			'delivery_city',
			'delivery_state',
			'delivery_zipcode',
			'country',
			'design_description',
			'sku',
			'size',
			'product_quantity',
			'gift_message',
			'Insured_Order',
			'shipping_method'
		);

		$this->orders = array();
	}

	function extract_new_orders() {
		global $wpdb, $kerusso_dropshipping;

		$company_id = $kerusso_dropshipping->get_company_id();
		$order_group = array();
		$new_orders = array();
		$order_items = array();

		// Query for orders in the processing state
		$orders = $wpdb->get_results("SELECT id, post_date FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status = 'wc-processing'");
		if ($orders) {
			$count = 0;
			$set = 0;

			// Build initial order data
			foreach ($orders as $order) {
				$new_orders[$order->id] = array();
				$new_orders[$order->id]['company_id'] = $company_id;
				$new_orders[$order->id]['order_id'] = $order->id;
				$new_orders[$order->id]['date_purchased'] = date('m/d/Y H:i', strtotime($order->post_date));
				$new_orders[$order->id]['name'] = '';
				$new_orders[$order->id]['gift_message'] = '';
				$new_orders[$order->id]['Insured_Order'] = 'no';

				if ($count >= 50) { $count = 0; $set++; }
				$order_group[$set][] = $order->id;
				$count++;
			}

			// Remove any orders that have already been processed
			foreach ($order_group as $group) {
				$list = implode(',', $group);
				$processed = $wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_kds_status' AND post_id IN (" . esc_sql($list) . ")");
				if ($processed) {
					foreach ($processed as $processed_order) {
						unset($new_orders[$processed_order->post_id]);
					}
				}
			}
		}

		unset($order_group);
		$new_order_ids = array_keys($new_orders);

		if ($new_order_ids) {
			foreach ($new_order_ids as $new_order_id) {
				$count = 0;
				$set = 0;
				$new_order_group = array();
				foreach ($orders as $order) {
					if ($count >= 50) { $count = 0; $set++; }
					$new_order_group[$set][] = $new_order_id;
					$count++;
				}
			}

			foreach ($new_order_group as $group) {
				$list = implode(',', $group);
				// Query for additional order data
				$order_data = $wpdb->get_results("SELECT * FROM $wpdb->postmeta WHERE post_id IN (" . esc_sql($list) . ")");
				if ($order_data) {
					foreach ($order_data as $data) {
						switch ($data->meta_key) {
							case '_billing_email':
								$new_orders[$data->post_id]['customers_email_address'] = $data->meta_value;
								break;
							case '_shipping_first_name':
								$new_orders[$data->post_id]['name'] = trim($data->meta_value . ' ' . $new_orders[$data->post_id]['name']);
								break;
							case '_shipping_last_name':
								$new_orders[$data->post_id]['name'] = trim($new_orders[$data->post_id]['name'] . ' ' . $data->meta_value);
								break;
							case '_shipping_address1':
								$new_orders[$data->post_id]['delivery_address1'] = $data->meta_value;
								break;
							case '_shipping_address2':
								$new_orders[$data->post_id]['delivery_address2'] = $data->meta_value;
								break;
							case '_shipping_city':
								$new_orders[$data->post_id]['delivery_city'] = $data->meta_value;
								break;
							case '_shipping_state':
								$new_orders[$data->post_id]['delivery_state'] = $data->meta_value;
								break;
							case '_shipping_postcode':
								$new_orders[$data->post_id]['delivery_zipcode'] = $data->meta_value;
								break;
							case '_shipping_country':
								$new_orders[$data->post_id]['country'] = $data->meta_value;
								break;
							case '_order_shipping':
								$new_orders[$data->post_id]['shipping_method'] = 3;
								break;
						}
					}
				}
			}

			// Convert each Kerusso item into its own element in the array
			foreach ($new_orders as $post_id => $order) {
				$items = $kerusso_dropshipping->get_items_in_order($post_id);
				if (!$items) { $kerusso_dropshipping->write_to_log('Export: failed to get items in order #' . $post_id); continue; }

				$item_count = 0;
				foreach ($items as $item) {
					$this_order = $new_orders[$post_id];
					$this_order['design_description'] = '';
					$this_order['sku'] = '';
					$this_order['size'] = '';
					$this_order['product_quantity'] = 0;

					$is_kerusso = false;
					foreach ($item as $item_key => $item_value) {
						switch ($item_key) {
							case 'name':
								$this_order['design_description'] = $item_value;
								break;
							case 'sku':
								$this_order['sku'] = $item_value;
								break;
							case 'size':
								$this_order['size'] = $item_value;
								break;
							case 'qty':
								$this_order['product_quantity'] = $item_value;
								break;
							case 'is_kerusso':
								if ($item_value) $is_kerusso = true;
								break;
						}
					}
					if (!$is_kerusso) continue;

					$order_items[$post_id . '-' . $item_count] = $this_order;
					$item_count++;
				}
			}
		}

		$this->orders = $order_items;
		return true;
	}

	function prepare_order_csv_file() {
		if (!$this->orders) return true;

		$output = '';
		foreach ($this->fields as $field) {
			if ($output) $output .= ',';
			$output .= $field;
		}

		foreach ($this->orders as $row) {
			if (!isset($row['sku']) || !$row['sku']) continue;

			$output .= "\r\n";
			$line = '';
			foreach ($this->fields as $field) {
				if ($line) $line .= ',';
				if (isset($row[$field])) $line .= '"' . str_replace('"', '""', $row[$field]) . '"';
			}
			$output .= $line;
		}

		if (!file_put_contents($this->file_path, $output)) return new WP_Error('KDS_WRITE_FAIL', 'Failed to write temporary order file. Please check file permissions.');

		return true;
	}

	function send_csv_to_remote_server() {
		if ($this->orders && file_exists($this->file_path)) {
			$ftp = ftp_connect($this->remote_host, $this->remote_port);
			if (!$ftp) return WP_Error('KDS_FTP_CONN_FAIL', 'Failed to connect to remote server. The server may be temporarily unavailable.');

			$login = @ftp_login($ftp, $this->username, $this->password);
			if (!$login) return WP_Error('KDS_FTP_LOGIN_FAIL', 'Could not login to remote server. Please check username and password.');

			if (!ftp_put($ftp, $this->remote_file_name, $this->file_path, FTP_ASCII)) return WP_ERROR('KDS_FTP_WRITE_FAIL', 'Could not write orders file to remote server.');
			@ftp_put($ftp, 'archive/'.$this->remote_file_name, $this->file_path, FTP_ASCII);

			ftp_close($ftp);
		}

		return true;
	}

	function mark_orders_as_sent() {
		global $kerusso_dropshipping;
		$order_ids = array();

		if ($this->orders) {
			foreach ($this->orders as $order) {
				$order_ids[] = $order['order_id'];
			}

			$order_ids = array_unique($order_ids);

			foreach ($order_ids as $order_id) {
				$kerusso_dropshipping->change_status($order_id, 'sent');
			}

			if (file_exists($this->file_path)) @unlink($this->file_path);
		}

		return true;
	}

	function do_export() {
		global $kerusso_dropshipping;

		$result = $this->extract_new_orders();
		if (is_wp_error($result)) return $result;

		$result = $this->prepare_order_csv_file();
		if (is_wp_error($result)) return $result;

		$result = $this->send_csv_to_remote_server();
		if (is_wp_error($result)) return $result;

		$result = $this->mark_orders_as_sent();
		if (is_wp_error($result)) return $result;

		$kerusso_dropshipping->delete_transients('order');

		return true;
	}
}

?>
