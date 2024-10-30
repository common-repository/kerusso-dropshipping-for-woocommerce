<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Kerusso_Dropshipping_Order_Tracking {

	var $remote_url;
	var $file_name;
	var $file_path;
	var $fields;
	var $columns;
	var $updates;
	
	function __construct() {
		global $kerusso_dropshipping;

		$upload_dir = wp_upload_dir();
		$data_dir = $upload_dir['basedir'].'/kerusso-dropshipping-for-woocommerce';

		$this->remote_url = $kerusso_dropshipping->get_tracking_feed();
		$this->file_name = 'order-tracking.csv';
		$this->file_path = $data_dir . '/' . $this->file_name;

		$this->fields = array(
			'customer id' => 'customer_id',
			'shipdate' => 'datetime',
			'orderid' => 'order_id',
			'trackingcode' => 'tracking_code',
			'comments' => 'comment',
			'status' => 'status',
			'carrier' => 'carrier'
		);

		$this->columns = array();
		$this->updates = array();
	}

	function download_tracking_csv() {
		if (file_exists($this->file_path)) @unlink($this->file_path);

		$http = wp_remote_get($this->remote_url);
		if (isset($http['response']['code']) && $http['response']['code'] != '200') return new WP_Error('KDS_NON200', 'Remote server error. Please try again later.');

		$data = (isset($http['body'])) ? $http['body'] : '';
		if (!$data) return new WP_Error('KDS_EMPTY', 'Failed to retrieve tracking report. Please try again later.');

		if (!file_put_contents($this->file_path, $data)) return new WP_Error('KDS_WRITE_FAIL', 'Failed to write temporary tracking report file. Please check file permissions.');

		return true;
	}

	function extract_tracking_data() {
		$this->updates = array();

		if (($handle = fopen($this->file_path, "r")) === false) return new WP_Error('KDS_FOPEN_FAIL', 'Failed to open tracking report file.');
		if (($headers = fgetcsv($handle)) === false) return new WP_Error('KDS_TRFILE_INVALID', 'Tracking report file is invalid.');
		if (!$headers) return new WP_Error('KDS_TRFILE_NO_HEADERS', 'Tracking report file file headers not found.');

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

				$this->updates[$row_number][$field_name] = $data;
			}
		}

		fclose($handle);

		return true;
	}

	function update_orders() {
		global $kerusso_dropshipping;

		if ($this->updates) {
			foreach ($this->updates as $update) {
				if (strtolower($update['status']) == 'complete') {
					$kerusso_dropshipping->change_status($update['order_id'], 'complete', $update['carrier'], $update['tracking_code'], $update['comment']);
				} elseif (strtolower($update['status']) == 'partial complete') {
					$kerusso_dropshipping->change_status($update['order_id'], 'partial', $update['carrier'], $update['tracking_code'], $update['comment']);
				}
			}

			if (file_exists($this->file_path)) @unlink($this->file_path);
		}

		return true;
	}

	function do_updates() {
		global $kerusso_dropshipping;

		$result = $this->download_tracking_csv();
		if (is_wp_error($result)) return false;

		$result = $this->extract_tracking_data();
		if (is_wp_error($result)) return $result;

		$result = $this->update_orders();
		if (is_wp_error($result)) return $result;

		$kerusso_dropshipping->delete_transients('order');

		return true;
	}
}

?>
