<?php
/*
Plugin Name: Taxonomy Tools
Description: Modify or re-map your taxonomy or category structure
Version: 1.1
Author: Matt Gibbs
Author URI: https://facetwp.com/
License: GPL2
*/

class Taxonomy_Tools
{

    public $url;


    function __construct() {
        add_action( 'init', array( $this, 'init' ) );
    }


    function init() {
        $this->url = plugins_url( 'taxonomy-tools' );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
    }


    /**
     * Admin menu
     */
    function admin_menu() {
        add_options_page( 'Taxonomy Tools', 'Taxonomy Tools', 'manage_options', 'taxonomy-tools', array( $this, 'page_taxonomy_tools' ) );
    }


    /**
     * Save taxonomy changes
     */
    function save_taxonomy( $post_data ) {
        global $wpdb;

        if ( empty( $post_data['taxonomy_from'] ) ) {
            return 'Please select a taxonomy to migrate from.';
        }
        else {
            $taxonomy_from = (array) $post_data['taxonomy_from'];
        }

        if ( empty( $post_data['taxonomy_to'] ) ) {
            return 'Please select a taxonomy to migrate to.';
        }
        else {
            $taxonomy_to = (int) $post_data['taxonomy_to'];
        }

        $merge_terms = empty( $post_data['merge_terms'] ) ? false : true;

        // Loop through each old term_taxonomy_id
        foreach ( $taxonomy_from as $term_taxonomy_id ) {

            // Sanitize the taxonomy ID
            $term_taxonomy_id = (int) $term_taxonomy_id;

            // Store the old taxonomy item's data
            $old = $wpdb->get_row( "SELECT term_taxonomy_id, term_id, parent FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = '$term_taxonomy_id' LIMIT 1" );

            // Point children taxonomy items to the new parent
            $wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET parent = '$taxonomy_to' WHERE parent = $old->term_taxonomy_id" );

            // Reset post-to-taxonomy relationships (also avoid MySQL "Duplicate Entry" errors)
            $object_ids = $wpdb->get_col( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = '$taxonomy_to'" );
            $object_ids = empty( $object_ids ) ? 0 : implode( ',', $object_ids );
            $wpdb->query( "UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = '$taxonomy_to' WHERE term_taxonomy_id = '$old->term_taxonomy_id' AND object_id NOT IN ($object_ids)" );

            // Update taxonomy count
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = '$taxonomy_to'" );
            $wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET count = '$count' WHERE term_taxonomy_id = '$taxonomy_to' LIMIT 1" );

            // Merge/delete the old taxonomy items
            if ( $merge_terms ) {

                // Delete the old taxonomy item
                $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = '$old->term_taxonomy_id' LIMIT 1" );

                // Is the term being used elsewhere?
                $term_exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = '$old->term_id' LIMIT 1" );

                // If not, delete the term
                if ( 1 > $term_exists ) {
                    $wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id = '$old->term_id' LIMIT 1" );
                }
            }
            else {
                // Reset the old taxonomy item's count
                $wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET count = 0 WHERE term_taxonomy_id = '$old->term_taxonomy_id' LIMIT 1" );
            }
        }

        return 'Migration successful';
    }


    /**
     * Get all WP terms
     */
    function get_all_terms() {
        global $wpdb;

        $output = array();

        $sql = "
        SELECT t.name, tt.term_taxonomy_id, tt.taxonomy, tt.count
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
        ORDER BY t.name";
        $results = $wpdb->get_results( $sql );
        foreach ( $results as $result ) {
            $output[ $result->term_taxonomy_id ] = "$result->name ($result->taxonomy, $result->count items)";
        }

        return $output;
    }


    /**
     * Admin settings screen
     */
    function page_taxonomy_tools() {
        if ( ! current_user_can( 'manage_options' ) ) {
            exit;
        }

        if ( ! empty( $_POST ) && isset( $_POST['tt_nonce'] ) ) {
            if ( wp_verify_nonce( $_POST['tt_nonce'], 'tt_nonce' ) ) {
                $message = $this->save_taxonomy( $_POST );
            }
            else {
                $message = 'Bad token';
            }
        }

        $all_terms = $this->get_all_terms();
?>

<div class="wrap">
    <h1>Taxonomy Tools</h1>

    <?php if ( isset( $message ) ) : ?>
    <div class="updated"><p><?php echo $message; ?></p></div>
    <?php endif; ?>

    <form method="post" action="">
        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row">Current Taxonomy</th>
                    <td>
                        <select name="taxonomy_from[]" multiple="multiple" style="width:400px; height:150px">
                        <?php foreach ( $all_terms as $id => $name ) : ?>
                            <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">New Taxonomy</th>
                    <td>
                        <select name="taxonomy_to" style="width:400px">
                            <option value="">-- Select one --</option>
                        <?php foreach ( $all_terms as $id => $name ) : ?>
                            <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Options</th>
                    <td>
                        <input type="checkbox" name="merge_terms" value="1" checked="checked" /> &nbsp;
                        Merge old taxonomy terms?
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"></th>
                    <td>
                        <input type="hidden" name="tt_nonce" value="<?php echo wp_create_nonce( 'tt_nonce' ); ?>" />
                        <input type="submit" class="button" value="Save Mapping" />
                    </td>
                </tr>
            </tbody>
        </table>
    </form>
</div>

<?php
    }
}

new Taxonomy_Tools();
