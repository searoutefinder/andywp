<?php
/*
Plugin Name: Hyamap Google Maps based store locator plugin
Pugin URI: https://ezmapdesign.com
Description: Display locations of registered Hyamatrix providers on a single Google Map including radius search and other features by dropping a shortcode into a WP page  
Author: Tamas Hajdu EV.
Author URI: https://ezmapdesign.com/
Version: 1.0
*/

class HyaMap {
	public function __construct(){
		$this->constant();
		global $wpdb;

		//Add the custom post type		
		/*add_action('init', array($this,'custom_post_type_hypl_contractor'), 0);
		add_action('init', array($this,'custom_post_type_pa_contractor'), 0);		
		add_action('init', array($this,'custom_post_type_hya_contractor'), 0);*/
		add_action('init', array($this,'cpt_hyacontractor'), 0);

		//add_action('init', array($this,'pub_rewrite_rules'));
		add_filter('post_type_link', array($this,'pub_permalink'), 10, 4);

		add_action('save_post',array($this,'save_post_callback'), 10);
		add_action('trashed_post',array($this, 'trash_post_callback'), 0);
		add_action('post_updated',array($this, 'post_update_callback'), 10, 6);
		add_action('updated_post_meta', array($this, 'post_update_callback'), 10, 6);


		/*Add the admin menu to the sidebar*/
		add_action('admin_menu', array($this, 'mcm_map_options_page'));
		
		add_action( 'admin_enqueue_scripts', array($this,'init_admin_script') );
		add_action('wp_ajax_geosearch', array($this,'processAjaxRequest'));
		add_action('wp_ajax_nopriv_geosearch', array($this,'processAjaxRequest'));
		
		/*Check if sync table is present, if not, then create it*/
		$table_name = $wpdb->prefix.'posts_geo';

		if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
     		//table not in database. Create new table
     		$charset_collate = $wpdb->get_charset_collate();
 
		    $sql = "CREATE TABLE $table_name (
		        id mediumint(9) NOT NULL AUTO_INCREMENT,
		        post_id BIGINT NULL UNIQUE,
		        post_type VARCHAR(32) NULL,
		        lat FLOAT(10,6) NULL,
		        lng FLOAT(10,6) NULL,
		        UNIQUE KEY id (id)
		    ) {$charset_collate};";     		
     		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
     		dbDelta( $sql );
		}	

		/*Generate [hyamap] shortcode which initializes the map*/
		add_shortcode( 'hyamap', array( $this, 'hyamap_init' )  );
		
		$this->options = new stdClass();
		$this->options->mapfile = HYA_DIR . 'config/config.js';
		$this->options->logo = HYA_URL . 'images/logo.png';
		$this->options->banner = HYA_URL . 'images/sidebar_banner_small.png';

	}

	protected function constant(){	
		define( 'HYA_VERSION',   '1.0' );
		define( 'HYA_DIR',       plugin_dir_path( __FILE__ ) );
		define( 'HYA_URL',       plugin_dir_url( __FILE__ ) );
		define( 'HYA_SERVER', 'localhost');
		define( 'HYA_DB', 'hyamatrix');
		define( 'HYA_DBUSER', 'hyamatrix');
		define( 'HYA_DBPW', '');
		define( 'HYA_GOOGLEKEY', 'AIzaSyA3dydif84LccNJoxT2tXHJz1f_5wkwxAo');
	}


	public function pub_rewrite_rules()
	{
	    global $wp_rewrite;

	    $wp_rewrite->add_rewrite_tag( '%trtmt%', '([^&]+)', 'salon_treatment_type');
	}

	public function pub_permalink($permalink, $post, $leavename){
	     //var_dump($permalink);
	     if ( strpos( $permalink, '%trtmt%' ) !== false ) {


	        $treatments = get_post_meta($post->ID, 'salon_treatment_type', true);
			global $wp;
			var_dump($permalink);

			$fullURL = home_url(add_query_arg(array(),$wp->request));
			$urlParts = explode("/", $fullURL);

			var_dump(in_array($treatments, $urlParts[3]));
			

	         $rewritecode = array(
	               '%trtmt%',
	               '%postname%',
	         );

	         $rewritereplace = array(
	               array_pop($treatments),
	               $post->post_name
	         );

	         $permalink = str_replace($rewritecode, $rewritereplace, $permalink); 

	         var_dump($permalink);   
	    }
	    return $permalink;
	}	


	public function getMySqlConnection(){
		$servername = "localhost";
		$database = "wp";
		$username = "root";
		$password = "";

		$conn = new mysqli($servername, $username, $password, $database);

		if (!$conn) {

		    die("Connection failed: " . mysqli_connect_error());

		}				

		return $conn;
		
	}
	public function post_sync($new_status, $old_status, $post){
	    global $post;
	    global $wpdb;
	    
	    $table_name = $wpdb->prefix.'posts_geo';

        if ( $old_status == 'publish'  &&  $new_status != 'trash' ) {
                //delete($post->ID);
        }
        if ( $old_status != 'publish'  &&  $new_status == 'publish' ) {
		    switch(true){
		    	case ($post->post_type == "photoaging"):
		    		$latitude = get_post_meta($post->ID, 'salon_latitude',true);
					if($latitude==''){ $latitude = $_POST['salon_latitude']; }
		    		$longitude = get_post_meta($post->ID,'salon_longitude',true);
					if($longitude==''){ $longitude = $_POST['salon_longitude']; }				
		    		$wpdb->insert( $table_name, array("post_id" => $post->ID, "post_type" => $post->post_type, "lat" => floatval($latitude), "lng" => floatval($longitude) ), array("%s", "%s", "%f", "%f") );
		    	break;
		    }
        }	    	
	}
	public function post_update_callback($post){
	    global $post;
	    global $wpdb;

		$table_name = $wpdb->prefix.'posts_geo';

	    switch(true){
	    	case ($post->post_type == "photoaging"):
	    		$latitude = get_post_meta( $post->ID, "salon_latitude", true);
	    		$longitude = get_post_meta( $post->ID, "salon_longitude", true);
	    		$wpdb->update( 
	    			$table_name, 
	    			array("lat" => floatval($latitude), "lng" => floatval($longitude) ),
	    			array("post_id" => $post->ID),
	    			array("post_type" => $post->type),
	    			array("%s", "%s", "%f", "%f") 
	    		);	    		
	    	break;
	    }
	}
	public function save_post_callback($post_id){
	    global $post;
	    global $wpdb;

		$table_name = $wpdb->prefix.'posts_geo';

	    switch(true){
	    	case ($post->post_type == "photoaging"):
	    		$latitude = get_post_meta($post_id, 'salon_latitude',true);
				if($latitude==''){ $latitude = $_POST['salon_latitude']; }
	    		$longitude = get_post_meta($post_id,'salon_longitude',true);
				if($longitude==''){ $longitude = $_POST['salon_longitude']; }				
	    		$wpdb->insert( $table_name, array("post_id" => $post_id, "post_type" => $post->post_type, "lat" => floatval($latitude), "lng" => floatval($longitude) ), array("%s", "%s", "%f", "%f") );
	    	break;
	    }		
	}
	public function trash_post_callback($post_id){
	    global $wpdb;
	    global $post;
	    $table_name = $wpdb->prefix.'posts_geo';
	    switch(true){
	    	case ( in_array($post->post_type, array("photoaging", "hyaluronplasztika", "hexcosm")) ):
	    		$wpdb->delete( $table_name, array( 'post_id' => $post_id ) );
	    	break;
	    }
	}
	public function cpt_hyacontractor(){
	    register_post_type('hm_cosmetologist',
	        [
	            'labels'      => [
	                'name'          => __('Cosmetologists'),
	                'singular_name' => __('Cosmetologist'),
	            ],
	            'description' => 'Individual pages for cosmetologists',
			    'supports' => array( ),
			    'taxonomies' => array( 'location' ),
			    'hierarchical' => false,
			    'public' => true,
			    'show_ui' => true,
			    'show_in_menu' => true,
			    'menu_position' => 6,
			    'show_in_admin_bar' => true,
			    'show_in_nav_menus' => true,
			    'can_export' => true,
			    'has_archive' => false,		
			    'exclude_from_search' => false,
			    'publicly_queryable' => true,			    
			    'capability_type' => 'page',
			    //'rewrite' => array( 'slug' => '/hexamatrix-kezeles', 'with_front' => false, 'pages' => false)	
			    'rewrite' => array( 'slug' => '/%trtmt%', 'with_front' => false, 'pages' => false)	            
	        ]
	    );
	    flush_rewrite_rules();		
	}
	public function custom_post_type_pa_contractor(){
	    register_post_type('photoaging',
	        [
	            'labels'      => [
	                'name'          => __('Cosmetologists - Photo Aging'),
	                'singular_name' => __('Cosmetologists - Photo Aging'),
	            ],
	            'description' => 'Individual pages for Photo Aging cosmetologists',
			    'supports' => array( ),
			    'taxonomies' => array( 'location' ),
			    'hierarchical' => false,
			    'public' => true,
			    'show_ui' => true,
			    'show_in_menu' => true,
			    'menu_position' => 6,
			    'show_in_admin_bar' => true,
			    'show_in_nav_menus' => true,
			    'can_export' => true,
			    'has_archive' => false,		
			    'exclude_from_search' => false,
			    'publicly_queryable' => true,
			    'with_front' => false, 
			    'capability_type' => 'page',
			    'query_var' => 'photo-aging',
			    'rewrite' => array( 'slug' => '/photo-aging')	            
	        ]
	    );
	}
	public function custom_post_type_hypl_contractor(){
	    register_post_type('hyaluronplasztika',
	        [
	            'labels'      => [
	                'name'          => __('Cosmetologists - Hyaluronplasztika'),
	                'singular_name' => __('Cosmetologists - Hyaluronplasztika'),
	            ],
	            'description' => 'Individual pages for Hyaluronplasztika cosmetologists',
			    'supports' => array( ),
			    'taxonomies' => array( 'location' ),
			    'hierarchical' => false,
			    'public' => true,
			    'show_ui' => true,
			    'show_in_menu' => true,
			    'menu_position' => 4,
			    'show_in_admin_bar' => true,
			    'show_in_nav_menus' => true,
			    'can_export' => true,
			    'has_archive' => false,		
			    'exclude_from_search' => false,
			    'publicly_queryable' => true,
			    'with_front' => false, 
			    'capability_type' => 'page',
			    'query_var' => 'hyaluronplasztika-kezeles',
			    'rewrite' => array( 'slug' => '/hyaluronplasztika-kezeles')            

	        ]
	    );
	}
	public function custom_post_type_hya_contractor(){
	    register_post_type('hexacosm',
	        [
	            'labels'      => [
	                'name'          => __('Cosmetologists - Hexamatrix'),
	                'singular_name' => __('Cosmetologists - Hexamatrix'),
	            ],
	            'description' => 'Individual pages for Hexamatrix cosmetologists',
			    'supports' => array( ),
			    'hierarchical' => false,
			    'public' => true,
			    'show_ui' => true,
			    'show_in_menu' => true,
			    'menu_position' => 5,
			    'show_in_admin_bar' => true,
			    'show_in_nav_menus' => true,
			    'can_export' => true,
			    'has_archive' => false,		
			    'exclude_from_search' => false,
			    'publicly_queryable' => true,
			    'with_front' => false, 
			    'capability_type' => 'page',
			    'query_var' => 'cosmetologists',
			    'rewrite' => array( 'slug' => '/hexamatrix-kezeles')	            
	        ]
	    );			        	    
	}
	public function hyamap_init(){
		ob_start();
		include 'templates/map.php';
		wp_enqueue_style( 'wp-map-frontend-icons-style', 'https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css', false, '1.0', 'all' );
		wp_enqueue_style( 'wp-map-frontend-style', HYA_URL . 'css/hyamap.css', false, '3.03', 'all' );
		wp_enqueue_script( 'google-maps', '//maps.googleapis.com/maps/api/js?libraries=places&key=' . HYA_GOOGLEKEY, array(), false, true );
		wp_enqueue_script( 'wp-map-frontend', HYA_URL . 'js/modules/module.map.js', array('jquery'), 15, '1.02', true );
		wp_localize_script( 'wp-map-frontend', 'hyajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

		/*wp_enqueue_script( 'wp-map-frontend-config', MCM_URL . 'config/config.js', array(), false, true );
		wp_enqueue_script( 'wp-map-frontend-init', MCM_URL . 'js/init.js', array('jquery'), false, true );	*/

		$html = ob_get_clean();
		return $html;
	}

	public function listAllHexa(){	
		$posts = get_posts([
		  	'post_type' => 'hexacosm',		  	
		  	'post_status' => 'publish',
		  	'order'    => 'ASC'	  	  		  	 
		]);		
		var_dump($posts);
	}

	public function addNewDataFromCSV($lines, $postType){
		for($i=0;$i<count($lines);$i++){
			$postID = wp_insert_post(array(
				"post_title" => $lines[$i][3],
				"post_name" => $lines[$i][3],			
				"post_type" => $postType,
				"comment_status" => "closed",
				"post_status" => "draft"			
			));

			add_post_meta($postID, "salon_address", $lines[$i][1]);
			add_post_meta($postID, "salon_name", $lines[$i][2]);
			add_post_meta($postID, "cosmetologist_name", $lines[$i][3]);
			add_post_meta($postID, "salon_telephone", $lines[$i][6]);
			add_post_meta($postID, "salon_image", $lines[$i][8]);
			add_post_meta($postID, "salon_latitude", $lines[$i][9]);
			add_post_meta($postID, "salon_longitude", $lines[$i][10]);
			add_post_meta($postID, "salon_url", $lines[$i][4]);
		}

		return true;				
	}	

	public function processAjaxRequest() {
		global $wpdb;
		if ( isset( $_POST["hya_params"] ) ) {

			$params = json_decode(stripslashes($_POST["hya_params"]));
			

			$table_name = 'wp_posts_geo';

			//$result = $wpdb->query('DROP TABLE ' . $table_name);
			//$result = $wpdb->query('TRUNCATE TABLE ' . $table_name);
			
			//$result = $wpdb->get_results("SELECT * FROM () a JOIN " . $wpdb->posts . " p ON a.post_id = p.id ORDER BY distance ASC");
			
			$result = $wpdb->get_results("SELECT a.*, " . $wpdb->posts . ".* FROM (SELECT *, 
( 6379 * acos( cos( radians(" . $params->latitude . ") ) * cos( radians( lat ) ) * cos( radians( lng ) - radians(" . $params->longitude . ") ) + sin( radians(" . $params->latitude . ") ) * sin(radians(lat)) ) ) AS distance 
FROM wp_posts_geo
HAVING distance < " . $params->radius . ") a JOIN " . $wpdb->posts . " ON a.post_id = " . $wpdb->posts . ".id");
			

		    $response = new stdClass();
		    $response->type = "FeatureCollection";
		    $response->features = array();

			for($i=0;$i<count($result);$i++){
				$source = get_post_meta($result[$i]->post_id);
				$item = new stdClass();
				$item->type = "Feature";
				$item->properties = new stdClass();
				$item->properties->salon_address = $source["salon_address"][0];
				$item->properties->salon_name = $source["salon_name"][0];
				$item->properties->cosmetologist_name = $source["cosmetologist_name"][0];
				$item->properties->salon_url = $source["salon_url"][0];
				$item->properties->salon_telephone = $source["salon_telephone"][0];
				$item->properties->salon_image = $source["salon_image"][0];
				if(count($source["salon_treatment_type"]) > 0){
					$item->properties->salon_treatment_type = unserialize($source["salon_treatment_type"][0]);
				}
				else
				{
					$item->properties->salon_treatment_type = array();	
				}
				$item->properties->distance = $result[$i]->distance;

				$item->geometry = new stdClass();
				$item->geometry->type = "Point";
				$item->geometry->coordinates = array( floatval($source["salon_longitude"][0]), floatval($source["salon_latitude"][0]) );

				array_push($response->features, $item);
			}

			wp_send_json($response);
			die();
		}
	}

	public function admin_screen()
	{
		include 'templates/admin.php';	
	}

	public function mcm_map_options_page() {
		add_menu_page('HyaMap', 'HyaMap Plugin', 'manage_options', 'hyamap', array($this, 'admin_screen'), HYA_URL . 'images/mcmlogo_small.png');
	}

	public function init_admin_script(){
		if(isset($_GET['page']) && $_GET['page'] == 'hyamap'){
			//echo "Hyamap admin";
			/*wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style( 'wp-map-style', MCM_URL . 'css/jquery.qtip.min.css', false, '1.0', 'all' );
			wp_enqueue_script( 'raphael-script', 'https://cdnjs.cloudflare.com/ajax/libs/raphael/2.2.7/raphael.min.js', array(), false, true );
			wp_enqueue_script( 'qtip-script', 'https://cdnjs.cloudflare.com/ajax/libs/qtip2/2.1.1/jquery.qtip.min.js', array(), false, true );						
			wp_register_script('wp-map-config', MCM_URL . 'config/config.js', false, filemtime( MCM_DIR .'config/config.js' ), true);
			wp_enqueue_script( 'wp-map-config', MCM_URL . 'config/config.js', array(), false, true );			
			wp_enqueue_script( 'wp-map-script', MCM_URL . 'js/mcm.clickable.map.js', array('jquery'), false, true );					
			wp_enqueue_script( 'wp-map-admininit', MCM_URL . 'js/admin-init.js', array('jquery', 'wp-color-picker'), false, true );	
			wp_localize_script( 'wp-map-admininit', 'the_ajax_script', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );*/			
		}
	}				
}

$hyaMap = new HyaMap();
 
?>