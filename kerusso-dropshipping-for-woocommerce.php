<?php
/**
  * Plugin Name: Kerusso Dropshipping for WooCommerce
  * Description: A quick and easy one-click install allows you to start selling over 1000 Kerusso products with no manual work required. This plugin will add our products, update stock levels, pull orders and return tracking codes automatically.
  * Version: 1.0.4
  * Author: Kerusso
  * Author URI: http://www.kerusso.com/
  * Text Domain: kerusso-dropshipping-for-woocommerce
  * Domain Path: /languages
  * License: GNU General Public License v3.0
  * License URI: http://www.gnu.org/licenses/gpl-3.0.html
  */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once('classes/import-products.php');
require_once('classes/export-orders.php');
require_once('classes/order-tracking.php');

class Kerusso_Dropshipping {

	var $options;
	var $has_dependencies;
	var $stats;
	var $brands;

	function __construct() {
		$this->get_options();
		$this->has_dependencies = true;
		$this->reset_stats();
		$this->brands = array('Kerusso', 'Cherished Girl', 'NDP', 'Promise Keepers');

		$upload_dir = wp_upload_dir();
		$data_dir = $upload_dir['basedir'].'/kerusso-dropshipping-for-woocommerce';
		if (!file_exists($data_dir)) wp_mkdir_p($data_dir);
	}

	function check_dependencies() {
		if (!class_exists('WooCommerce')) $this->has_dependencies = false;
	}

	function reset_stats() {
		$this->stats = array('added' => 0, 'updated' => 0, 'enabled' => 0, 'disabled' => 0, 'removed' => 0, 'stock' => 0);
	}

	function is_setup() {
		if (!isset($this->options['company_id']) || !$this->options['company_id']) return false;
		if (!isset($this->options['username']) || !$this->options['username']) return false;
		if (!isset($this->options['password']) || !$this->options['password']) return false;
		if (!isset($this->options['tracking_feed']) || !$this->options['tracking_feed']) return false;
		if (!preg_match('/^http/i', $this->options['tracking_feed'])) return false;
		return true;
	}

	function activate() {
		$this->create_attribute_taxonomies();
		wp_schedule_event(time(), 'daily', 'kerusso_dropshipping_daily_sync');
		wp_schedule_event(time(), 'hourly', 'kerusso_dropshipping_hourly_sync');
	}

	function load_plugin_textdomain() {
		load_plugin_textdomain('kerusso-dropshipping-for-woocommerce', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	function create_attribute_taxonomies() {
		global $wpdb;

		$label = $wpdb->get_var("SELECT attribute_label FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = 'size'");

		if (!$label) {
			$wpdb->query("INSERT INTO {$wpdb->prefix}woocommerce_attribute_taxonomies (attribute_name, attribute_label, attribute_type, attribute_orderby) VALUES ('size', 'Size', 'text', 'menu_order')");
		}

		$label = $wpdb->get_var("SELECT attribute_label FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = 'color'");

		if (!$label) {
			$wpdb->query("INSERT INTO {$wpdb->prefix}woocommerce_attribute_taxonomies (attribute_name, attribute_label, attribute_type, attribute_orderby) VALUES ('color', 'Color', 'text', 'menu_order')");
		}

		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name = '_transient_wc_attribute_taxonomies'");
	}

	function deactivate() {
		wp_clear_scheduled_hook('kerusso_dropshipping_daily_sync');
		wp_clear_scheduled_hook('kerusso_dropshipping_hourly_sync');

		$products = new Kerusso_Dropshipping_Import_Products;
		$products->disable_all();
	}

	function config_page() {
		if (!$this->has_dependencies) {
?>
		<div class="wrap">
		<h2>Kerusso Dropshipping</h2>
		<p>Kerusso Dropshipping <?php echo esc_html__('requires', 'kerusso-dropshipping-for-woocommerce'); ?> <a target="_blank" href="/wp-admin/plugin-install.php?tab=plugin-information&plugin=woocommerce">WooCommerce</a>. <?php echo esc_html__('Please install it and then reload this page to continue.', 'kerusso-dropshipping-for-woocommerce'); ?></p>
		</div>
<?php
		} elseif (!isset($_POST['full_settings']) && !$this->is_setup()) {
?>
		<div class="wrap">
		<h2>Kerusso Dropshipping</h2>

			<?php
			$updated = false;

			if ($_POST && $_POST['action'] == 'Save') {
				check_admin_referer('kerusso_dropshipping');
				$company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : '';
				$username = isset($_POST['username']) ? stripslashes($_POST['username']) : '';
				$password = isset($_POST['password']) ? stripslashes($_POST['password']) : '';
				$tracking_feed = isset($_POST['tracking_feed']) ? strtolower(stripslashes($_POST['tracking_feed'])) : '';
				if (!preg_match('/^http/i', $tracking_feed)) $tracking_feed = '';

				$options = $this->options;
				$options['company_id'] = $company_id;
				$options['username'] = $username;
				$options['password'] = $password;
				$options['tracking_feed'] = $tracking_feed;

				$this->update_options($options);
				$updated = true;
			} else {
				$company_id = $this->get_company_id();
				$username = $this->get_username();
				$password = $this->get_password();
				$tracking_feed = $this->get_tracking_feed();
			}
			?>

			<?php if ($updated): ?>
				<div class="updated"><?php echo esc_html__('Your settings have been updated.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
			<?php endif; ?>

			<?php if ($updated && $this->is_setup()): ?>
			<p><a href=""><?php echo esc_html__('Click here to continue.', 'kerusso-dropshipping-for-woocommerce'); ?></a></p>
			<?php else: ?>
			<p><?php echo esc_html__('To get started with Kerusso Dropshipping, first', 'kerusso-dropshipping-for-woocommerce'); ?> <a target="_blank" href="https://www.kerusso.com/wholesale/woo-commerce-dropshipping-sign-up/"><?php echo esc_html__('complete an application', 'kerusso-dropshipping-for-woocommerce'); ?></a>. <?php echo esc_html__('After your application has been approved, we will email you the details to enter into the form below. Then you can begin using the plugin right away.', 'kerusso-dropshipping-for-woocommerce'); ?></p>

			<form method="post" action="">
			<?php wp_nonce_field('kerusso_dropshipping'); ?>
			<table class="form-table">
			<tr valign="top">
				<th scope="row"><label for="company_id"><?php echo esc_html__('Company ID', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="company_id" class="regular-text" value="<?php echo esc_attr($company_id); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="username"><?php echo esc_html__('Username', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="username" class="regular-text" value="<?php echo esc_attr($username); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="password"><?php echo esc_html__('Password', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="password" class="regular-text" value="<?php echo esc_attr($password); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="tracking_feed"><?php echo esc_html__('Tracking Feed URL', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="tracking_feed" class="regular-text" value="<?php echo esc_attr($tracking_feed); ?>" /></td>
			</tr>
			</table>
			<br>
			<input type="submit" name="action" value="Save" class="button button-primary" />
			</form>
			<?php endif; ?>
		</div>
<?php
		} else {
?>
		<div class="wrap">
		<h2>Kerusso Dropshipping</h2>

			<?php
			$updated = false;

			if ($_POST && $_POST['action'] == 'Save') {
				check_admin_referer('kerusso_dropshipping');
				$company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : '';
				$username = isset($_POST['username']) ? stripslashes($_POST['username']) : '';
				$password = isset($_POST['password']) ? stripslashes($_POST['password']) : '';
				$tracking_feed = isset($_POST['tracking_feed']) ? strtolower(stripslashes($_POST['tracking_feed'])) : '';
				if (!preg_match('/^http/i', $tracking_feed)) $tracking_feed = '';
				$automatic_import = (isset($_POST['automatic_import']) && $_POST['automatic_import']) ? 1 : 0;
				$brand_options = array();
				$brand_options['other'] = (isset($_POST['brand_other']) && $_POST['brand_other']) ? 1 : 0;
				foreach ($this->brands as $brand) {
					$brand = str_replace(' ', '-', strtolower($brand));
					$brand_options[$brand] = (isset($_POST['brand_'.$brand]) && $_POST['brand_'.$brand]) ? 1 : 0;
				}
				$discount_type = isset($_POST['discount_type']) ? stripslashes($_POST['discount_type']) : 'fixed';
				if ($discount_type != 'fixed' && $discount_type != 'percentage') $discount_type = 'fixed';
				if ($discount_type == 'percentage') {
					$discount_value = $discount_percentage = isset($_POST['discount_percentage']) ? $this->number_format(floatval($_POST['discount_percentage'])) : '0.00';
					$discount_dollar_amount = '0.00';
				} else {
					$discount_percentage = '0.00';
					$discount_value = $discount_dollar_amount = isset($_POST['discount_dollar_amount']) ? $this->number_format(floatval($_POST['discount_dollar_amount'])) : '0.00';
				}
				$email_option = (isset($_POST['email_option']) && $_POST['email_option']) ? 1 : 0;
				$email_address = isset($_POST['email_address']) ? stripslashes($_POST['email_address']) : '';
				$logging_option = (isset($_POST['logging_option']) && $_POST['logging_option']) ? 1 : 0;

				$options = $this->options;
				$options['company_id'] = $company_id;
				$options['username'] = $username;
				$options['password'] = $password;
				$options['tracking_feed'] = $tracking_feed;
				$options['automatic_import'] = $automatic_import;
				$options['brand_options'] = $brand_options;
				$options['discount_type'] = $discount_type;
				$options['discount_value'] = $discount_value;
				$options['email_option'] = $email_option;
				$options['email_address'] = $email_address;
				$options['logging_option'] = $logging_option;

				$this->update_options($options);
				$updated = true;
			} else {
				if ($_POST && $_POST['action'] == 'Refresh Products') {
					$products = new Kerusso_Dropshipping_Import_Products;
					$import_result = ($products->has_lock()) ? false : true;
					if ($import_result) wp_schedule_single_event(time(), 'kerusso_dropshipping_refresh_products');
				} elseif ($_POST && $_POST['action'] == 'Run Product Import') {
					$products = new Kerusso_Dropshipping_Import_Products;
					$import_result = ($products->has_lock()) ? false : true;
					if ($import_result) wp_schedule_single_event(time(), 'kerusso_dropshipping_manual_import', array(true));
				} elseif ($_POST && $_POST['action'] == 'Update Inventory') {
					$products = new Kerusso_Dropshipping_Import_Products;
					$import_result = ($products->has_lock()) ? false : true;
					if ($import_result) wp_schedule_single_event(time(), 'kerusso_dropshipping_manual_import', array(false));
				}

				$company_id = $this->get_company_id();
				$username = $this->get_username();
				$password = $this->get_password();
				$tracking_feed = $this->get_tracking_feed();
				$automatic_import = $this->get_automatic_import_option();
				$discount_type = $this->get_discount_type();
				if ($discount_type == 'percentage') {
					$discount_dollar_amount = '0.00';
					$discount_percentage = $this->number_format($this->get_discount_value());
				} else {
					$discount_dollar_amount = $this->number_format($this->get_discount_value());
					$discount_percentage = '0.00';
				}
				$email_option = $this->get_email_option();
				$email_address = $this->get_email_address();
				$logging_option = $this->get_logging_option();
			}
			?>

			<?php if ($updated): ?>
				<div class="updated"><?php echo esc_html__('Your settings have been updated.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
			<?php elseif ($_POST && $_POST['action'] == 'Refresh Products'): ?>
				<?php if (isset($import_result) && $import_result): ?>
				<div class="updated"><?php echo esc_html__('The product refresh process has started.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
				<?php else: ?>
				<div class="error"><?php echo esc_html__('The product refresh process could not start. Another process is already running.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
				<?php endif; ?>
			<?php elseif ($_POST && $_POST['action'] == 'Run Product Import'): ?>
				<?php if (isset($import_result) && $import_result): ?>
				<div class="updated"><?php echo esc_html__('The import process has started.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
				<?php else: ?>
				<div class="error"><?php echo esc_html__('The import process could not start. Another process is already running.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
				<?php endif; ?>
			<?php elseif ($_POST && $_POST['action'] == 'Update Inventory'): ?>
				<?php if (isset($import_result) && $import_result): ?>
				<div class="updated"><?php echo esc_html__('The update process has started.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
				<?php else: ?>
				<div class="error"><?php echo esc_html__('The update process could not start. Another process is already running.', 'kerusso-dropshipping-for-woocommerce'); ?></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="">
			<?php wp_nonce_field('kerusso_dropshipping'); ?>
			<div style="float:right"><input type="submit" name="action" value="Update Inventory" class="button button-primary" /> <input type="submit" name="action" value="Run Product Import" class="button button-primary" /> <input type="submit" name="action" value="Refresh Products" class="button button-primary" /></div>
			<div style="clear:both"></div>

			<h3><?php echo esc_html__('Plugin Options', 'kerusso-dropshipping-for-woocommerce'); ?></h3>
			<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('New Products', 'kerusso-dropshipping-for-woocommerce'); ?></th>
				<td><fieldset><legend class="screen-reader-text"><span><?php echo esc_html__('New Products', 'kerusso-dropshipping-for-woocommerce'); ?></span></legend><label for="automatic_import">
				<input name="automatic_import" type="checkbox" value="1" <?php if ($automatic_import) echo 'checked="checked" '; ?>/>
				<?php echo esc_html__('Import new products automatically', 'kerusso-dropshipping-for-woocommerce'); ?></label>
				</fieldset></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__('Brands to Import', 'kerusso-dropshipping-for-woocommerce'); ?></th>
				<td><fieldset><legend class="screen-reader-text"><span><?php echo esc_html__('Brands to Import', 'kerusso-dropshipping-for-woocommerce'); ?></span></legend>
				<?php foreach ($this->brands as $brand): ?>
				<label for="brand_<?php echo str_replace(' ', '-', strtolower($brand)); ?>"><input name="brand_<?php echo str_replace(' ', '-', strtolower($brand)); ?>" type="checkbox" id="brand_<?php echo str_replace(' ', '-', strtolower($brand)); ?>" <?php if ($this->is_brand_enabled($brand)) { echo 'checked="checked" '; } ?>value="1" /> <?php echo $brand; ?></label><br />
				<?php endforeach; ?>
				<label for="brand_other"><input name="brand_other" type="checkbox" id="brand_other" <?php if ($this->is_brand_enabled('other')) { echo 'checked="checked" '; } ?> value="1" /> <?php echo esc_html__('Other brands', 'kerusso-dropshipping-for-woocommerce'); ?></label>
				</fieldset></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('Price Modifier', 'kerusso-dropshipping-for-woocommerce'); ?></th>
				<td>
					<fieldset><legend class="screen-reader-text"><span><?php echo esc_html__('Price Modifier', 'kerusso-dropshipping-for-woocommerce'); ?></span></legend>
					<label><input type="radio" name="discount_type" <?php if ($discount_type == 'fixed') echo 'checked="checked" '; ?>value="fixed"/> <?php echo esc_html__('Fixed', 'kerusso-dropshipping-for-woocommerce'); ?>:<span class="screen-reader-text"> <?php echo esc_html__('enter a dollar amount in the following field', 'kerusso-dropshipping-for-woocommerce'); ?></span></label>
					<label for="discount_dollar_amount" class="screen-reader-text"><?php echo esc_html__('Dollar amount', 'kerusso-dropshipping-for-woocommerce'); ?>:</label>$<input type="text" name="discount_dollar_amount" placeholder="3.00" value="<?php echo esc_attr($discount_dollar_amount); ?>" class="small-text" /> <?php echo esc_html__('off MSRP', 'kerusso-dropshipping-for-woocommerce'); ?><br>
					<label><input type="radio" name="discount_type" <?php if ($discount_type == 'percentage') echo 'checked="checked" '; ?>value="percentage"/> <?php echo esc_html__('Percentage', 'kerusso-dropshipping-for-woocommerce'); ?>:<span class="screen-reader-text"> <?php echo esc_html__('enter a percentage in the following field', 'kerusso-dropshipping-for-woocommerce'); ?></span></label>
					<label for="discount_percentage" class="screen-reader-text"><?php echo esc_html__('Percentage', 'kerusso-dropshipping-for-woocommerce'); ?>:</label><input type="text" name="discount_percentage" placeholder="20" value="<?php echo esc_attr($discount_percentage); ?>" class="small-text" />% <?php echo esc_html__('off MSRP', 'kerusso-dropshipping-for-woocommerce'); ?><br>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('Email Alerts', 'kerusso-dropshipping-for-woocommerce'); ?></th>
				<td><fieldset><legend class="screen-reader-text"><span><?php echo esc_html__('Email Alerts', 'kerusso-dropshipping-for-woocommerce'); ?></span></legend><label for="email_option">
				<input name="email_option" type="checkbox" value="1" <?php if ($email_option) echo 'checked="checked" '; ?>/>
				<?php echo esc_html__('Send emails when products are added to your store, etc.', 'kerusso-dropshipping-for-woocommerce'); ?></label>
				</fieldset></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="email_address"><?php echo esc_html__('Address to Notify', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="email_address" class="regular-text" value="<?php echo esc_attr($email_address); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('Log Errors', 'kerusso-dropshipping-for-woocommerce'); ?></th>
				<td><fieldset><legend class="screen-reader-text"><span><?php echo esc_html__('Log Errors', 'kerusso-dropshipping-for-woocommerce'); ?></span></legend><label for="email_option">
				<input name="logging_option" type="checkbox" value="1" <?php if ($logging_option) echo 'checked="checked" '; ?>/>
				<?php echo esc_html__('Save errors to a log file', 'kerusso-dropshipping-for-woocommerce'); ?></label>
				</fieldset></td>
			</tr>
			</table>
			<br>
			<h3><?php echo esc_html__('Basic Configuration', 'kerusso-dropshipping-for-woocommerce'); ?></h3>
			<table class="form-table">
			<tr valign="top">
				<th scope="row"><label for="company_id"><?php echo esc_html__('Company ID', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="company_id" class="regular-text" value="<?php echo esc_attr($company_id); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="username"><?php echo esc_html__('Username', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="username" class="regular-text" value="<?php echo esc_attr($username); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="password"><?php echo esc_html__('Password', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="password" class="regular-text" value="<?php echo esc_attr($password); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="tracking_feed"><?php echo esc_html__('Tracking Feed URL', 'kerusso-dropshipping-for-woocommerce'); ?></label></th>
				<td><input type="text" name="tracking_feed" class="regular-text" value="<?php echo esc_attr($tracking_feed); ?>" /></td>
			</tr>
			</table>
			<br>
			<input type="hidden" name="full_settings" value="1" />
			<input type="submit" name="action" value="Save" class="button button-primary" />
			</form>
			<br>
			<p><?php echo esc_html__('Note: You will be charged a flat rate shipping fee of $4 for US orders and $9 for international orders. Please make the appropriate adjustments to your shipping rates.', 'kerusso-dropshipping-for-woocommerce'); ?></p>
		</div>
<?php
		}
	}

	function setup_menu() {
	    	add_menu_page('Kerusso Dropshipping', 'Kerusso', 'manage_options', 'kerusso-dropshipping', array($this, 'config_page'), plugin_dir_url(__FILE__) . 'kerusso-menu.png');
	}

	function get_options() {
		$data = get_option('kerusso_dropshipping');
		$this->options = json_decode($data, true);
	}

	function update_options($options) {
		$this->options = $options;
		$data = json_encode($this->options);
		return update_option('kerusso_dropshipping', $data);
	}

	function get_company_id() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['company_id'])) ? $this->options['company_id'] : '';
	}

	function get_username() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['username'])) ? $this->options['username'] : '';
	}

	function get_password() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['password'])) ? $this->options['password'] : '';
	}

	function get_tracking_feed() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['tracking_feed'])) ? $this->options['tracking_feed'] : '';
	}

	function get_automatic_import_option() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['automatic_import'])) ? $this->options['automatic_import'] : false;
	}

	function get_discount_type() {
		if (!$this->options) $this->get_options();
		$discount_type = (isset($this->options['discount_type'])) ? $this->options['discount_type'] : 'fixed';
		if ($discount_type != 'fixed' && $discount_type != 'percentage') $discount_type = 'fixed';
		return $discount_type;
	}

	function get_discount_value() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['discount_value'])) ? $this->options['discount_value'] : 0.00;
	}

	function get_email_option() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['email_option'])) ? $this->options['email_option'] : true;
	}

	function get_email_address() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['email_address']) && $this->options['email_address']) ? $this->options['email_address'] : get_option('admin_email');
	}

	function get_logging_option() {
		if (!$this->options) $this->get_options();
		return (isset($this->options['logging_option'])) ? $this->options['logging_option'] : true;
	}

	function is_brand_enabled($brand) {
		if (!$brand) $brand = 'other';
		$brand = str_replace(' ', '-', strtolower($brand));
		if (!$this->options) $this->get_options();
		if (!isset($this->options['brand_options'])) return true;
		if (isset($this->options['brand_options'][$brand])) return $this->options['brand_options'][$brand];
		return $this->options['brand_options']['other'];
	}

	function number_format($number) {
		return number_format($number, 2, '.', '');
	}

	function get_items_in_order($order_id) {
		global $wpdb;

		$items = array();

		$metas = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN (SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'line_item' AND order_id = {$order_id})");
		if (!$metas) return $items;

		foreach ($metas as $meta) {
			$item_id = $meta->order_item_id;
			switch ($meta->meta_key) {
				case '_qty':
					$items[$item_id]['qty'] = $meta->meta_value;
					break;
				case '_product_id':
					$items[$item_id]['parent_id'] = $meta->meta_value;
					if (!isset($items[$item_id]['product_id'])) $items[$item_id]['product_id'] = $meta->meta_value;
					break;
				case '_variation_id':
					$items[$item_id]['product_id'] = $meta->meta_value;
					break;
				case 'pa_size':
					$items[$item_id]['size'] = $meta->meta_value;
					break;
				case 'pa_color':
					$items[$item_id]['color'] = $meta->meta_value;
					break;
			}
		}
		unset($metas);

		foreach ($items as $item_id => $item) {
			$metas = $wpdb->get_results("SELECT * FROM $wpdb->postmeta WHERE post_id = " . $item['product_id']);
			foreach ($metas as $meta) {
				switch ($meta->meta_key) {
					case '_sku':
						$items[$item_id]['sku'] = $meta->meta_value;
						break;
					case '_kds_catalog_name':
						$items[$item_id]['name'] = $meta->meta_value;
						$items[$item_id]['is_kerusso'] = 1;
						break;
				}
			}
		}

		foreach ($items as $item_id => $item) {
			if ($item['parent_id'] == $item['product_id']) continue;

			$metas = $wpdb->get_results("SELECT * FROM $wpdb->postmeta WHERE post_id = " . $item['parent_id']);
			foreach ($metas as $meta) {
				switch ($meta->meta_key) {
					case '_kds_catalog_name':
						$items[$item_id]['name'] = $meta->meta_value;
						$items[$item_id]['is_kerusso'] = 1;
						break;
				}
			}
		}

		return $items;
	}

	function is_mixed_order($order_id) {
		$items = $this->get_items_in_order($order_id);
		if (!$items) return false;

		foreach ($items as $item) {
			if (!isset($item['is_kerusso']) || !$item['is_kerusso']) return true;
		}

		return false;
	}

	function add_private_note($order_id, $note) {
		if (!function_exists('wc_get_order')) return false;
		$order = new WC_Order;
		$order->get_order($order_id);
		$note = apply_filters('kerusso_dropshipping_private_note_filter', $note);
		return $order->add_order_note($note, false);
	}

	function add_public_note($order_id, $note) {
		if (!function_exists('wc_get_order')) return false;
		$order = new WC_Order;
		$order->get_order($order_id);
		$note = apply_filters('kerusso_dropshipping_public_note_filter', $note);
		return $order->add_order_note($note, true);
	}

	function change_status($order_id, $status, $carrier = '', $tracking_code = '', $comment = '') {
		if ($status == 'sent') {
			$this->add_private_note($order_id, 'This order has been submitted to Kerusso for processing.');
			wp_update_post(array('ID' => $order_id, 'post_status' => 'wc-on-hold'));
			update_post_meta($order_id, '_kds_status', 'sent');
		} elseif ($status == 'partial') {
			$this->add_public_note($order_id, "One or more of your items has shipped via {$carrier} (tracking number {$tracking_code}). Other items from your order are still being prepared for shipment.\r\n\r\n{$comment}");
			update_post_meta($order_id, '_kds_status', 'partial');
		} elseif ($status == 'complete') {
			if (!$this->is_mixed_order($order_id)) {
				$this->add_public_note($order_id, "One or more of your items has shipped via {$carrier} (tracking number {$tracking_code}). This completes your order.\r\n\r\n{$comment}");
				$this->add_private_note($order_id, 'Kerusso has finished processing this order, however other non-Kerusso items are present and may still require fulfillment.');
				update_post_meta($order_id, '_kds_status', 'completed');
				wp_update_post(array('ID' => $order_id, 'post_status' => 'wc-processing'));
			} else {
				$this->add_public_note($order_id, "One or more of your items has shipped via {$carrier} (tracking number {$tracking_code}). This completes your order.\r\n\r\n{$comment}");
				$this->add_private_note($order_id, 'Kerusso has finished processing this order and changed its status to completed.');
				update_post_meta($order_id, '_kds_status', 'completed');
				update_post_meta($order_id, '_completed_date', date('Y-m-d H:i:s'));
				wp_update_post(array('ID' => $order_id, 'post_status' => 'wc-completed'));
			}
		} else {
			return false;
		}
	}

	function delete_transients($type = 'product') {
		$type = strtolower($type);
		if ($type == 'product' || $type == 'all') wc_delete_product_transients();
		if ($type == 'order' || $type == 'all') wc_delete_shop_order_transients();
		return true;
	}

	function write_to_log($message) {
		if (!$this->get_logging_option()) return true;
		if (!class_exists('WC_Logger')) return false;
		$logger = new WC_Logger;
		$logger->add('kerusso-dropshipping', __($message, 'kerusso-dropshipping-for-woocommerce'));
		return true;
	}

	function clear_log() {
		if (!class_exists('WC_Logger')) return false;
		$logger = new WC_Logger;
		$logger->clear('kerusso-dropshipping');
		return true;
	}

	function send_email_notification($content) {
		if (!$this->get_email_option()) return true;
		$to = $this->get_email_address();
		$subject = apply_filters('kerusso_dropshipping_notification_subject', __('Kerusso Dropshipping notification', 'kerusso-dropshipping-for-woocommerce'));
		$content = apply_filters('kerusso_dropshipping_notification_content', $content);
		return wp_mail($to, $subject, $content);
	}

	function daily_sync($override = null) {
		if (!$this->has_dependencies) return false;
		if (!$this->is_setup()) return false;

		$full_import = $this->get_automatic_import_option();
		if ($override !== null) $full_import = $override;

		$products = new Kerusso_Dropshipping_Import_Products;
		if ($full_import) {
			$result = $products->do_full_import();
		} else {
			$result = $products->do_daily_updates();
		}

		if (is_wp_error($result)) {
			$this->write_to_log($result->get_error_message());
			if ($full_import) {
				$this->send_email_notification(__('The Kerusso Dropshipping plugin attempted to do a product import, but the process failed. See the error log for more information.', 'kerusso-dropshipping-for-woocommerce'));
			} else {
				$this->send_email_notification(__('The Kerusso Dropshipping plugin attempted to do an inventory update, but the process failed. See the error log for more information.', 'kerusso-dropshipping-for-woocommerce'));
			}
		} else {
			$stats_info = "\r\n\r\nProducts added: {$this->stats['added']}\r\nProducts enabled: {$this->stats['enabled']}\r\nProducts disabled: {$this->stats['disabled']}\r\nStock updates: {$this->stats['stock']}";
			if ($full_import) {
				$this->send_email_notification(__('The Kerusso Dropshipping plugin successfully completed the product import process.', 'kerusso-dropshipping-for-woocommerce') . $stats_info);
			} else {
				$this->send_email_notification(__('The Kerusso Dropshipping plugin successfully completed the inventory update process.', 'kerusso-dropshipping-for-woocommerce') . $stats_info);
			}
		}
	}

	function hourly_sync() {
		if (!$this->has_dependencies) return false;
		if (!$this->is_setup()) return false;

		$orders = new Kerusso_Dropshipping_Export_Orders;
		$result = $orders->do_export();

		if (is_wp_error($result)) {
			$this->write_to_log($result->get_error_message());
		}

		$tracking = new Kerusso_Dropshipping_Order_Tracking;
		$result = $tracking->do_updates();

		if (is_wp_error($result)) {
			$this->write_to_log($result->get_error_message());
		}
	}

	function refresh_products() {
		if (!$this->has_dependencies) return false;
		if (!$this->is_setup()) return false;

		$products = new Kerusso_Dropshipping_Import_Products;
		$result = $products->refresh_products();

		if (is_wp_error($result)) {
			$this->write_to_log($result->get_error_message());
			$this->send_email_notification(__('The Kerusso Dropshipping plugin attempted to do a product refresh, but the process failed. See the error log for more information.', 'kerusso-dropshipping-for-woocommerce'));
		} else {
			$stats_info = "\r\n\r\nProducts added: {$this->stats['added']}\r\nProducts updated: {$this->stats['updated']}\r\nProducts removed: {$this->stats['removed']}\r\nProducts enabled: {$this->stats['enabled']}\r\nProducts disabled: {$this->stats['disabled']}";
			$this->send_email_notification(__('The Kerusso Dropshipping plugin successfully completed the product refresh process.', 'kerusso-dropshipping-for-woocommerce') . $stats_info);
		}
	}
}

$kerusso_dropshipping = new Kerusso_Dropshipping;
add_action('plugins_loaded', array($kerusso_dropshipping, 'check_dependencies'));
add_action('plugins_loaded', array($kerusso_dropshipping, 'load_plugin_textdomain'));
add_action('admin_menu', array($kerusso_dropshipping, 'setup_menu'));
add_action('kerusso_dropshipping_hourly_sync', array($kerusso_dropshipping, 'hourly_sync'));
add_action('kerusso_dropshipping_daily_sync', array($kerusso_dropshipping, 'daily_sync'));
add_action('kerusso_dropshipping_manual_import', array($kerusso_dropshipping, 'daily_sync'), 10, 1);
add_action('kerusso_dropshipping_refresh_products', array($kerusso_dropshipping, 'refresh_products'));
register_activation_hook(__FILE__, array($kerusso_dropshipping, 'activate'));
register_deactivation_hook(__FILE__, array($kerusso_dropshipping, 'deactivate'));
?>
