<?php
/**
 * @package square_sync_sync
 *
 * Plugin Name: Square Sync
 * Plugin URI: http://squaresync.ryandonkersgoed.com
 * Description: Loads Square Inventory into wordpress page or post using shortcode.
 * Author: Ryan Donkersgoed
 * Author URI: http://www.ryandonkersgoed.com
 * Version: 0.2
 * 
 */

//Constants
define( 'SQUARE_SYNC_VERSION',  '0.2' );
define( 'SQUARE_SYNC_IMAGE', '700' );
define( 'SQUARE_SYNC_CROP', '200' );
define( 'SQUARE_SYNC_MAX_SIZE', 800 );
define( 'SQUARE_SYNC_TOKEN_LENGTH', 22 );

/**
 * square_sync_activate
 *
 * activates square sync plugin and sets db options
 */
function square_sync_activate() {

    //check version
    $square_sync_version = get_option( 'square_sync_version' );
    if ( $square_sync_version != SQUARE_SYNC_VERSION) {

        //update the version value
        update_option( 'square_sync_version', SQUARE_SYNC_VERSION );

        //set default on options
        update_option( 'square_sync_image_x', SQUARE_SYNC_IMAGE );
        update_option( 'square_sync_image_y', SQUARE_SYNC_IMAGE );
        update_option( 'square_sync_crop_x', SQUARE_SYNC_CROP );
        update_option( 'square_sync_crop_y', SQUARE_SYNC_CROP );
        update_option( 'square_sync_cache', FALSE );
        update_option( 'square_sync_link', FALSE );
        update_option( 'square_sync_experimental', FALSE );

    }//end if

}//end square_sync_activate
register_activation_hook( __FILE__, 'square_sync_activate' );

/**
 * square_sync_uninstall
 *
 * uninstalls square sync plugin and options
 */
function square_sync_uninstall() {

    //remove cache
    square_sync_clear_cache();

    //remove options
    delete_option( 'square_sync_version' );
    delete_option( 'square_sync_access_token' );
    delete_option( 'square_sync_use_image' );
    delete_option( 'square_sync_image_x' );
    delete_option( 'square_sync_image_y' );
    delete_option( 'square_sync_crop_x' );
    delete_option( 'square_sync_crop_y' );
    delete_option( 'square_sync_cache' );
    delete_option( 'square_sync_set_category' );
    delete_option( 'square_sync_link' );
    delete_option( 'square_sync_experimental' );

}//end square_sync_uninstall
register_uninstall_hook( __FILE__, 'square_sync_uninstall' );

/*
 * square_sync_styles
 *
 * loads external css stylesheets
 */
function square_sync_styles() {

    //load style
    wp_enqueue_style( 'squaresync', plugins_url( 'square-sync.css', __FILE__ ), array(), '0.1', 'all' );

}//end square_sync_styles
add_action( 'wp_enqueue_scripts', 'square_sync_styles' );

/**
 * load_square
 *
 * replaces square shortcode with product listing
 */
function square_sync_load() {

    //get listings
    $items_list = square_sync_list_items();
    
    if ( get_option( 'square_sync_link' ) ) {
        
        //get merchant info
        $merchant = square_sync_get_merchant();

    }//end if
    
    if ( get_option( 'square_sync_set_category' ) ) {
        
        //get categories
        $category_list = square_sync_list_categories();

        //key categories
        $categories = array();
        foreach ( $category_list as $category ) {
            $categories[$category->name] = '';    
        }//end foreach

    }//end if

    //for each listing create details
    $shop = '<ul>';
    foreach ( $items_list as $details ) {

        //if image available and not private 
        if ( ( get_option( 'square_sync_use_image' ) == FALSE || isset( $details->master_image->url ) ) && strcasecmp( $details->visibility, "PRIVATE") != 0 ) {

            //process master image to cache if not done so
            if ( isset( $details->master_image->url ) ) {

                //get upload directory
                $upload_dir = wp_upload_dir();
                $item_image = $upload_dir['basedir'] . '/square/' . $details->master_image->id;
                $item_image_path = $details->master_image->url; //on fail use remote
                $item_imagethumb_path = $item_image_path;
                
                //make sure square directory created
                if ( !is_readable( $upload_dir['basedir'] . '/square' ) && !mkdir( $upload_dir['basedir'] . '/square' ) ) {

                    //not found and couldn't create - use remote
                    update_option( 'square_sync_cache', FALSE );
                    return FALSE;

                } else {

                    //if cache doesn't exist
                    if ( !is_readable( $item_image . '_cache_R.jpg' ) || !is_readable( $item_image . '_cache_R_thumb.jpg' ) ) {
                
                        //if no copy get file from square
                        if ( is_readable( $item_image . '_cache.jpg' ) || copy( $details->master_image->url, $item_image . '_cache.jpg' ) ) {
                        
                            //copied now resize
                            $item_image_path = $upload_dir['baseurl'] . '/square/' . $details->master_image->id;
                            $item_imagethumb_path = $item_image_path;

                            //resize image for cache
                            $image = wp_get_image_editor( $item_image . '_cache.jpg' );
                            if ( is_wp_error( $image ) ) {
                                
                                //use large image if forced to
                                $item_image_path .= '_cache.jpg';
                                $item_imagethumb_path = $item_image_path;

                            } else {
                                
                                //resize sizes to options
                                $image->resize( intval( get_option( 'square_sync_image_x' ) ), intval( get_option( 'square_sync_image_y' ) ), false );
                                $image->save( $item_image . '_cache_R.jpg' );
                                $item_image_path .= '_cache_R.jpg';
                                $image->resize( intval( get_option( 'square_sync_crop_x' ) ), intval( get_option( 'square_sync_crop_y' ) ), true );
                                $image->save( $item_image . '_cache_R_thumb.jpg' );
                                $item_imagethumb_path .= '_cache_R_thumb.jpg';

                            }//end if
                            update_option( 'square_sync_cache', TRUE );

                        } else { 

                            //couldn't copy - use remote
                            update_option( 'square_sync_cache', FALSE );
                            return FALSE;

                        }//end if

                    } else {
                        
                        //cache exists use
                        $item_image_path = $upload_dir['baseurl'] . '/square/' . $details->master_image->id . '_cache_R.jpg';
                        $item_imagethumb_path = $upload_dir['baseurl'] . '/square/' . $details->master_image->id . '_cache_R_thumb.jpg';
                        update_option( 'square_sync_cache', TRUE );
                    
                    }//end if

                }//end if

            } else {
                
                //product has no image use default
                $item_image_path = plugins_url( 'assets/no_image.png', __FILE__ );
                $item_imagethumb_path = $item_image_path;

            }//end if

            //get lowest variation price
            $num_variations = 0;
            $lowest_price = 0;
            foreach ( $details->variations as $unique ) {

                //lowest variation price
                if ( !isset( $lowest_price ) || $unique->price_money->amount < $lowest_price || $lowest_price == 0 ) {
                    $lowest_price = $unique->price_money->amount;
                }//end if

                $num_variations++;
            
            }//end foreach

            
            //if variations
            $price = '$';
            if ( $num_variations > 1 ) {

                $price = 'Starting at $';

            }//end if

            //create market link
            $item_link_open = '';
            $item_link_close = '';
            if ( get_option( 'square_sync_link' ) && isset( $market->market_url ) ) {

                $item_link_open = '<a href="' . $merchant->market_url;
                
                if ( get_option( 'square_sync_experimental' ) ) {
                    
                    $item_link_open .= '/' . strtolower( preg_replace( "/[^A-Za-z]/", '-', preg_replace( "/[^A-Za-z\s]/", '', trim( $details->name ) ) ) );

                }//end if

                $item_link_open .= '" class="square_sync_item_link" name="' . $details->name . '" >';

                $item_link_close = '</a>';

            }//end if

            //create item
            $item = '<li class="square_sync_item collection">' . $item_link_open . '
                                <img src="' . $item_imagethumb_path .'" />
                                ' . $details->name . ' <br /> ' . $price . substr( (string)$lowest_price, 0, -2) . '.' . substr( (string)$lowest_price, -2, 2) . $item_link_close . '
                            </li>';

            //organize into categories if option
            $category = $details->category->name;
            if ( get_option( 'square_sync_set_category' ) && isset( $category ) ) {
              
                $categories[$category] .= $item;

            } else {
                
                $shop .= $item;
            
            }//end if

       }//end if

    }//end foreach

    //create category output
    if ( get_option( 'square_sync_set_category' ) ) {

        $shop = "";
        foreach ( $categories as $category => $items ) {
        
            $shop .= '<h3>' . $category . '</h3>';
            $shop .= '<ul>';
            $shop .= $items;
            $shop .= '</ul>';

        }//end foreach

    } else {
        
        $shop .= '</ul>';

    }//end if

    return $shop;

}//end load_square

//load square via shortcode
add_shortcode( 'squaresync', 'square_sync_load' );

/**
 * square_sync_get_merchant
 *
 * gets array of merchant details from square via REST
 * Returns: Array
 */
function square_sync_get_merchant() {
   return json_decode( square_sync_api_request( '' ) );
}//end square_sync_get_merchant

/**
 * square_sync_list_items
 *
 * gets array of items from square via REST
 * Returns: Array
 */
function square_sync_list_items() {
   return json_decode( square_sync_api_request( '/items' ) );
}//end square_sync_list_items

/**
 * square_sync_list_categories
 *
 * gets array of items from square via REST
 * Returns: Array
 */
function square_sync_list_categories() {
   return json_decode( square_sync_api_request( '/categories' ) );
}//end square_sync_list_items

/**
 * square_sync_api_request
 *
 * request JSON object via wp_remote from square api
 * Returns: JSON Object
 */
function square_sync_api_request( $square_sync_request ) {

    //setup url request
    $url = "https://connect.squareup.com/v1/me" . $square_sync_request;

    //remote request args
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . get_option( 'square_sync_access_token' ),
            'Accept' => 'application/json'
        ),
        'sslverify' => false
    );

    //send request
    $request = wp_remote_request( $url , $args);
    
    //if no errors
    if ( !is_wp_error( $request ) ) {

        //if 200 response
        if ( $request['response']['code'] == 200 ) {

            $request_body = $request['body'];

        } else {
           
            //decode json error response and return
            $square_sync_error = json_decode( $request['body'] );
            wp_die( 'Square API Error: ' . esc_html( $square_sync_error->message ) );

        }//end if

    } else {

        //return error on request
        wp_die( 'Square API Error!' );

    }//end if
    
    return $request_body;
}//end square_sync_api_request

/**
 * square_sync_clear_cache
 *
 * removes cache
 */
function square_sync_clear_cache() {

    //get upload dir
    $upload_dir = wp_upload_dir();
    $cache = $upload_dir['basedir'] . '/square';
    $cleared = FALSE;

    //if error removing cache
    if ( is_readable( $cache ) ) {
        
        //files to clear
        $cache_files = glob( $cache . '/*' );
        if ( $cache_files ) {
        
            foreach ( glob( $cache . '/*' ) as $file ) {
            
                if ( unlink( $file ) ) {

                    //success
                    $cleared = TRUE;
                    update_option( 'square_sync_cache', FALSE );

                } else {

                    //error
                    echo '<div class="updated fade"><p><strong>'. __( 'Error clearing ' . esc_html( $file ), 'squaresync' ) .'</strong></p></div>';

                }//end if

            }//end foreach

        } else {
        
            echo '<div class="updated fade"><p><strong>'. __( 'No cache to clear!', 'squaresync' ) .'</strong></p></div>';
        
        }//end if

    } else {
    
        //error
        echo '<div class="updated fade"><p><strong>'. __( 'Error clearing cache!, Check permissions on upload directory ' . esc_html( $cache ), 'squaresync' ) .'</strong></p></div>';
    
    }//end if

    if ( $cleared ) {

        echo '<div class="updated fade"><p><strong>'. __( 'Cache Cleared Successfully', 'squaresync' ) .'</strong></p></div>';

    }//end if

}//end square_sync_clear_cache

/**
 * square_sync_menu
 *
 * init settings menu
 */
function square_sync_menu() {

        add_options_page( __( 'Square Sync Options', 'squaresync' ), __( 'Square Sync', 'squaresync' ), 'manage_options', basename( __FILE__ ), 'square_sync_options_page' );

}//end square_sync_menu
add_action( 'admin_menu', 'square_sync_menu' );

/**
 * square_sync_options_page
 *
 * create  admin settings page
 */
function square_sync_options_page() {
    
    //if user allowed
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'Error! Unable to process query because of permissions.', 'squaresync' ) );
    }//end if

    //process form post
    if ( isset( $_POST['square_sync_clear_cache'] ) ) {

        square_sync_clear_cache();

    }//end if

    //if page saved
    if ( isset( $_POST['submit'] ) ) {

        //if authorization token for square submitted
        if ( isset( $_POST[ 'square_sync_token' ] ) ) {

            //clean token
            $square_sync_token = wp_filter_nohtml_kses( sanitize_text_field( $_POST[ 'square_sync_token' ] ) );
            if ( strlen( $square_sync_token ) <= SQUARE_SYNC_TOKEN_LENGTH ) {

                update_option( 'square_sync_access_token', $square_sync_token );

            } else {

                echo '<div class="updated fade"><p><strong>'. __( 'Validation Error: Invalid Access Token.', 'squaresync' ) .'</strong></p></div>';

            }//end if
            $updated = TRUE;

        }//end if

        //if only image product submitted
        if ( isset( $_POST[ 'square_sync_use_image' ] ) && strcasecmp( sanitize_text_field( $_POST[ 'square_sync_use_image' ] ), "checked" ) == 0 ) {

            update_option( 'square_sync_use_image', TRUE );
            $updated = TRUE;

        } else {
        
            update_option( 'square_sync_use_image', FALSE );
            $updated = TRUE;

        }//end if
        
        //if order products by categories submitted
        if ( isset( $_POST[ 'square_sync_set_category' ] ) && strcasecmp( sanitize_text_field( $_POST[ 'square_sync_set_category' ] ), "checked" ) == 0 ) {

            update_option( 'square_sync_set_category', TRUE );
            $updated = TRUE;

        } else {
        
            update_option( 'square_sync_set_category', FALSE );
            $updated = TRUE;

        }//end if
    
        //if crop x submitted
        if ( isset( $_POST[ 'square_sync_crop_x' ] ) ) {

            $square_sync_crop_x = wp_filter_nohtml_kses( sanitize_text_field( $_POST[ 'square_sync_crop_x' ] ) );

            if ( intval( $square_sync_crop_x ) && (int)$square_sync_crop_x <= SQUARE_SYNC_MAX_SIZE) {

                update_option( 'square_sync_crop_x', $square_sync_crop_x );

            } else {
             
                echo '<div class="updated fade"><p><strong>'. __( 'Validation Error: Crop width should be less than 800px and requires an integer.', 'squaresync' ) .'</strong></p></div>';

            }//end if
            $updated = TRUE;

        } else {
        
            update_option( 'square_sync_crop_x', 450 );
            $updated = TRUE;

        }//end if

        //if crop y submitted
        if ( isset( $_POST[ 'square_sync_crop_y' ] ) ) {

            $square_sync_crop_y = wp_filter_nohtml_kses( sanitize_text_field( $_POST[ 'square_sync_crop_y' ] ) );

            if ( intval( $square_sync_crop_y ) && (int)$square_sync_crop_y <= SQUARE_SYNC_MAX_SIZE ) {

                update_option( 'square_sync_crop_y', $square_sync_crop_y );

            } else {
            
                echo '<div class="updated fade"><p><strong>'. __( 'Validation Error: Crop height should be less than 800px and requires an integer.', 'squaresync' ) .'</strong></p></div>';

            }//end if
            $updated = TRUE;

        } else {
        
            update_option( 'square_sync_crop_y', 450 );
            $updated = TRUE;

        }//end if
       
        //if set link
        if ( isset( $_POST[ 'square_sync_link' ] ) && strcasecmp( sanitize_text_field( $_POST[ 'square_sync_link' ] ), "checked" ) == 0 ) {

            update_option( 'square_sync_link', TRUE );
            $updated = TRUE;

        } else {
        
            update_option( 'square_sync_link', FALSE );
            $updated = TRUE;

        }//end if

        //if experimental
        if ( isset( $_POST[ 'square_sync_experimental' ] ) && strcasecmp( sanitize_text_field( $_POST[ 'square_sync_experimental' ] ), "checked" ) == 0 ) {

            update_option( 'square_sync_experimental', TRUE );
            //make sure link also set
            update_option( 'square_sync_link', TRUE );
            $updated = TRUE;

        } else {
        
            update_option( 'square_sync_experimental', FALSE );
            $updated = TRUE;

        }//end if

        //attempt to create cache
        if ( !get_option( 'square_sync_cache' ) ) {
        
            if ( !square_sync_load() ) {

                echo '<div class="updated fade"><p><strong>'. __( 'Cache couldn\'t be created.', 'squaresync' ) .'</strong></p></div>';

            } else {
            
                echo '<div class="updated fade"><p><strong>'. __( 'Cache created.', 'squaresync' ) .'</strong></p></div>';
            
            }//end if

        }//end if

        //update
        if ( $updated ) {

            echo '<div class="updated fade"><p><strong>'. __( 'Options saved.', 'squaresync' ) .'</strong></p></div>';

        }//end if

    }//end if

    // print the Options Page
    ?>
    <div class="wrap">
        <div id="icon-options-general" class="icon32"><br /></div><h2><?php _e( 'Square Sync Options', 'squaresync' ); ?></h2>
        <p>NOTE: Product links only work for US Squareup accounts with the Square Market(online store) activated!</p>
        <form name="square_sync_options_form" method="post" action="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="clear_cache"></label><?php _e('Clear Cache?', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_clear_cache" name="square_sync_clear_cache" type="submit" class="button-primary" value="<?php _e( 'CLEAR', 'squaresync' ); ?>" />
                    </td>
                 </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="square_sync_access_token"></label><?php _e('Square Merchant Access Token', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_token" name="square_sync_token" type="password" size="25" value="<?php echo esc_html( get_option( 'square_sync_access_token' ) ); ?>" class="regular-text code" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="square_sync_set_category"></label><?php _e('Product Order By Category?', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_set_category" name="square_sync_set_category" type="checkbox" <?php if ( get_option( 'square_sync_set_category' ) ) { echo "checked"; } ?> class="code" value="checked" />
                    </td>
                 </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="square_sync_use_image"></label><?php _e('Only Allow Products With Images', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_use_image" name="square_sync_use_image" type="checkbox" <?php if ( get_option( 'square_sync_use_image' ) ) { echo "checked"; } ?> class="code" value="checked" />
                    </td>
                 </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="square_sync_crop"></label><?php _e('Thumb Crop Size', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_crop_x" name="square_sync_crop_x" type="text" size="10" value="<?php echo esc_html( get_option( 'square_sync_crop_x' ) ); ?>" class="regular-text code" />&nbsp;Width(px)
                        <input id="square_sync_crop_y" name="square_sync_crop_y" type="text" size="10" value="<?php echo esc_html( get_option( 'square_sync_crop_y' ) ); ?>" class="regular-text code" />&nbsp;Height(px)
                    </td>
                 </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="square_sync_link"></label><?php _e('Link to Square Market Store?', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_link" name="square_sync_link" type="checkbox" <?php if ( get_option( 'square_sync_link' ) ) { echo "checked"; } ?> class="code" value="checked" />
                    </td>
                 </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="square_sync_experimental"></label><?php _e('Link to direct Square Market Product? (experimental)', 'squaresync'); ?>
                    </th>
                    <td>
                        <input id="square_sync_experimental" name="square_sync_experimental" type="checkbox" <?php if ( get_option( 'square_sync_experimental' ) ) { echo "checked"; } ?> class="code" value="checked" />
                    </td>
                 </tr>
        </table>
        
        <p class="submit">
                <input type="submit" name="submit" id="submit" class="button-primary" value="<?php _e( 'Save Changes', 'squaresync' ); ?>" />
        </p>
    
        </form>
    </div>
<?php
    
}//end square_sync_options_page

/**
 * square_sync_settings_link
 *
 */
function square_sync_settings_link ( $links ) {

    $settings = array(
        '<a href="' . admin_url( 'options-general.php?page=square-sync.php' ) . '">Settings</a>'
    );

    return array_merge( $links, $settings );

}//end square_sync_settings_link
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'square_sync_settings_link' );

?>
