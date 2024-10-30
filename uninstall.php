<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

@set_time_limit(0);

// Delete options
delete_option('kerusso_dropshipping');
delete_option('kds_product_lock');

// Delete Kerusso products & product categories
global $wpdb;

$posts = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_kds_catalog_name'");
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
		}
	}

	if ($categories) {
		$wpdb->query("DELETE FROM $wpdb->term_taxonomy WHERE taxonomy = 'product_cat' AND term_taxonomy_id IN ($cat_str) AND term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM $wpdb->term_relationships)");
		$wpdb->query("DELETE FROM $wpdb->terms WHERE term_id NOT IN (SELECT term_id FROM $wpdb->term_taxonomy)");
	}
}

?>