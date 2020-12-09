<?php
/*
Plugin Name: FacetWP - BigCommerce
Description: BigCommerce integration for FacetWP
Version: 0.2.1
Author: FacetWP, LLC
Author URI: https://facetwp.com/
GitHub URI: facetwp/facetwp-bigcommerce
*/

defined( 'ABSPATH' ) or exit;


class FacetWP_BigCommerce_Addon
{

    public $term_lookup = [];
    public $product_source_data = [];


    function __construct() {
        if ( ! function_exists( 'bigcommerce' ) ) {
            return;
        }

        // hooks
        add_filter( 'facetwp_facet_sources', array( $this, 'register_facet_sources' ) );
        add_filter( 'facetwp_indexer_row_data', array( $this, 'build_index_data' ), 10, 2 );
    }


    /**
     * Add choices to the "Data Source" dropdown
     */
    function register_facet_sources( $sources ) {
        $choices = [
            'bigcommerce/type' => 'Product Type',
            'bigcommerce/weight' => 'Product Weight',
            'bigcommerce/width' => 'Product Width',
            'bigcommerce/depth' => 'Product Depth',
            'bigcommerce/height' => 'Product Height',
            'bigcommerce/calculated_price' => 'Price', // factors in variants
            'bigcommerce/cost_price' => 'Price (Cost)',
            'bigcommerce/retail_price' => 'Price (Retail)',
            'bigcommerce/sale_price' => 'Price (Sale)',
            'bigcommerce/categories' => 'Product Categories', // array of BC term IDs (needs lookup)
            'bigcommerce/brand' => 'Brand', // brand_id (needs lookup)
            'bigcommerce/in_stock' => 'In Stock?', // inventory_level > 0
            'bigcommerce/sku' => 'SKU',
            'bigcommerce/upc' => 'UPC',
            'bigcommerce/condition' => 'Condition',
            'bigcommerce/rating' => 'Rating'
        ];

        // include custom fields + options
        $options = $this->get_bigcommerce_options();

        foreach ( $options as $key => $label ) {
            $type = substr( $key, 0, strpos( $key, '-' ) );
            $choices[ "bigcommerce/$key" ] = $label . " ($type)";
        }

        $sources['bigcommerce'] = [
            'label' => 'BigCommerce',
            'choices' => $choices,
            'weight' => 10
        ];

        return $sources;
    }


    /**
     * Index the custom BigCommerce data sources
     */
    function build_index_data( $rows, $params ) {
        $source = $params['defaults']['facet_source'];
        $post_id = $params['defaults']['post_id'];

        // Only target BigCommerce
        if ( 'bigcommerce_product' != get_post_type( $post_id ) ) {
            return $rows;
        }

        if ( 0 === strpos( $source, 'bigcommerce/' ) ) {
            $source = str_replace( 'bigcommerce/', '', $source );

            $source_data = get_post_meta( $post_id, 'bigcommerce_source_data', true );
            $source_data = json_decode( $source_data, true );

            // Categories
            if ( 'categories' == $source ) {
                $mappings = $this->get_bigcommerce_term_mappings();
                foreach ( $source_data['categories'] as $bc_term_id ) {
                    if ( isset( $mappings[ $bc_term_id ] ) ) {
                        $cat_data = $mappings[ $bc_term_id ];
                        $new_row = $params['defaults'];
                        $new_row['facet_value'] = $cat_data['slug'];
                        $new_row['facet_display_value'] = $cat_data['name'];
                        $new_row['term_id'] = $cat_data['term_id'];
                        $rows[] = $new_row;
                    }
                }
            }

            // Brand
            elseif ( 'brand' == $source ) {
                $mappings = $this->get_bigcommerce_term_mappings();
                $brand_id = $source_data['brand_id'];
                if ( isset( $mappings[ $brand_id ] ) ) {
                    $cat_data = $mappings[ $brand_id ];
                    $new_row = $params['defaults'];
                    $new_row['facet_value'] = $cat_data['slug'];
                    $new_row['facet_display_value'] = $cat_data['name'];
                    $new_row['term_id'] = $cat_data['term_id'];
                    $rows[] = $new_row;
                }
            }

            // Options
            elseif ( 0 === strpos( $source, 'option-' ) ) {
                $option_display_name = str_replace( 'option-', '', $source );
                foreach ( $source_data['variants'] as $variant ) {
                    foreach ( $variant['option_values'] as $option_value ) {
                        if ( $option_value['option_display_name'] == $option_display_name ) {
                            $new_row = $params['defaults'];
                            $new_row['facet_value'] = $option_value['label'];
                            $new_row['facet_display_value'] = $option_value['label'];
                            $rows[] = $new_row;
                        }
                    }
                }
            }

            // Custom fields
            elseif ( 0 === strpos( $source, 'cf-' ) ) {
                $cf_name = str_replace( 'cf-', '', $source );
                foreach ( $source_data['custom_fields'] as $custom_field ) {
                    if ( $custom_field['name'] == $cf_name ) {
                        $new_row = $params['defaults'];
                        $new_row['facet_value'] = $custom_field['value'];
                        $new_row['facet_display_value'] = $custom_field['value'];
                        $rows[] = $new_row;
                    }
                }
            }

            // Normal fields
            else {
                if ( 'rating' == $source ) {
                    $value = get_post_meta( $post_id, 'bigcommerce_rating', true );
                }
                elseif ( 'in_stock' == $source ) {
                    $value = ( 0 < $source_data['inventory_level'] ) ? 'In Stock' : 'Out of stock';
                }
                elseif ( isset( $source_data[ $source ] ) ) {
                    $value = $source_data[ $source ];
                }

                $new_row = $params['defaults'];
                $new_row['facet_value'] = $value;
                $new_row['facet_display_value'] = $value;
                $rows[] = $new_row;
            }
        }

        return $rows;
    }


    /**
     * Figure out how BC term IDs correspond to WP term IDs
     * @return array associate array (BC term_id = key)
     */
    function get_bigcommerce_term_mappings() {
        if ( ! empty( $this->term_lookup ) ) {
            return $this->term_lookup;
        }

        global $wpdb;

        $sql = "
        SELECT tm.meta_value AS bc_term_id, tm.term_id, t.slug, t.name
        FROM $wpdb->termmeta tm
        INNER JOIN $wpdb->terms t ON t.term_id = tm.term_id AND tm.meta_key = 'bigcommerce_id'
        ";

        $results = $wpdb->get_results( $sql );
        $mappings = [];

        foreach ( $results as $result ) {
            $mappings[ $result->bc_term_id ] = [
                'term_id' => $result->term_id,
                'slug' => $result->slug,
                'name' => $result->name,
            ];
        }

        $this->term_lookup = $mappings;

        return $mappings;
    }


    /**
     * Loop through all products and grab all options
     * @return array Associate array with option key => label
     */
    function get_bigcommerce_options() {
        global $wpdb;

        $options = [];

        $sql = "
        SELECT meta_key, meta_value
        FROM {$wpdb->prefix}postmeta
        WHERE meta_key IN ('bigcommerce_options_data', 'bigcommerce_custom_fields')";
        $results = (array) $wpdb->get_results( $sql );

        foreach ( $results as $result ) {

            // custom fields are serialized
            if ( 'bigcommerce_custom_fields' == $result->meta_key ) {
                $data = unserialize( $result->meta_value );
                foreach ( $data as $row ) {
                    $options[ 'cf-' . $row['name'] ] = $row['name'];
                }
            }
            // options are JSON encoded
            else {
                $data = json_decode( $result->meta_value );
                foreach ( $data as $row ) {
                    $options[ 'option-' . $row->display_name ] = $row->display_name; // TODO $row->ID is unpredictable
                }
            }
        }

        return $options;
    }
}

new FacetWP_BigCommerce_Addon();
