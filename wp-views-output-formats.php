<?php
/*
Plugin Name: Views Output Formats - JSON / XML Export
Plugin URI: http://wordpress.org/extend/plugins/views-output-formats
Description: Provides JSON and XML output formats for Toolset Views
Version: 2.1
Author: khromov
Author URI: http://khromov.wordpress.com
License: GPL2
*/

class Views_Output_Formats
{
	const text_domain = "vof";

	function __construct()
	{
		/**
		 * Translation support
		 **/
		load_plugin_textdomain(self::text_domain, false, basename( dirname( __FILE__ ) ) . '/languages' );

		/**
		 * Register hooks, languages etc
		 **/
		register_activation_hook(__FILE__ , array( 'Views_Output_Formats', 'activate' ));
		register_deactivation_hook(__FILE__ , array( 'Views_Output_Formats', 'deactivate' ));

		/**
		 * Register menu item
		 */
		add_action('admin_menu', array( &$this, 'register_admin_menus'));

		/**
		 * Add URL endpoints for accessing different formats
		 */
		add_filter('query_vars', array(&$this, 'register_vars'));
		add_action('wp', array(&$this, 'register_endpoints')); //pre_get_posts is faster, but we can't use some functions there...
	}

	function register_vars($vars)
	{
		$vars[] = 'vof_id';
		$vars[] = 'vof_format';
		$vars[] = 'vof_token';
		return $vars;
	}

	function register_endpoints($query)
	{
		//Sanity checks
		if(	get_query_var('vof_id') !== '' 		&&
			get_query_var('vof_token') !== '' 	&&
			get_option('vof_token') !== false 	&&
			(get_query_var('vof_token') === get_option('vof_token') || get_query_var('vof_token') === $this->get_view_vof_token((int)get_query_var('vof_id'))) &&
			get_post_type((int)get_query_var('vof_id')) === 'view' &&
			function_exists('wpv_filter_get_posts')
		)
		{
			//Pick format
			$format = (get_query_var('vof_format') === 'xml') ? 'xml' : 'json';

			/* @var WP_Views $WP_Views */
			global $WP_Views;

			//Get the View query type
			$view_settings = $WP_Views->get_view_settings((int)get_query_var('vof_id'));

			//Prepare string for output
			$output = '';

			//Prepare string for data
			$result = array();

			//Set headers
			if($format === 'json')
				header('Content-type: application/json');
			else
				header('Content-Type: text/xml');

			//Taxonomy query
			if($view_settings['query_type'][0] === 'taxonomy')
			{
				$result['taxonomies'] = $WP_Views->taxonomy_query($view_settings);

				if($format === 'json')
					$output =  json_encode($result);
				else
					$output = Views_Output_Formats_XMLSerializer::generateValidXmlFromArray($result, '', 'taxonomy');
			}
			else if($view_settings['query_type'][0] === 'users') //User query
			{
				$result['users'] = $WP_Views->users_query($view_settings);

				//Filter sensitive information
				foreach($result['users'] as &$user)
				{
					$user->user_pass = '';
					$user->user_activation_key = '';
				}

				if($format === 'json')
					$output =  json_encode($result);
				else
					$output = Views_Output_Formats_XMLSerializer::generateValidXmlFromArray($result, '', 'users');
			}
			else //Posts query
			{
				//Query results. This is done differently from Taxonomy and Users queries
				$query_result = wpv_filter_get_posts((int)get_query_var('vof_id'));

				//Add in custom fields
				$posts_fixed['post'] = $this->views_output_merge_custom_fields($query_result->posts);

				//TODO: Add in taxonomies
				//$posts_fixed['post'] = views_output_merge_taxonomies($query_result->posts);

				//Finalize array
				$posts_finished = array('posts' => $posts_fixed);

				if($format === 'json')
				{
					$posts_json = array('posts' => $this->views_output_merge_custom_fields($query_result->posts));
					$output = json_encode($posts_json);
				}
				else
				{
					//Do some additional transformation to get the output format we want
					$posts_tmp = array();

					//Special condition for no results
					if(sizeof($query_result->posts)!==0)
					{
						$i = 0;
						foreach($posts_finished as $post)
						{
							foreach($post['post'] as $post_inner)
							{
								$posts_tmp['posts'][$i] = $post_inner;
								$i++;
							}
						}
					}
					else
						$posts_tmp['posts'] = array();

					echo Views_Output_Formats_XMLSerializer::generateValidXmlFromArray($posts_tmp, '', 'post');
				}
			}

			//Print output
			echo $output;

			//Early termination
			die();
		}
	}

	function register_admin_menus()
	{
		add_submenu_page( 'options-general.php', __( "Views Output Formats", self::text_domain), __( "Views Output Formats", self::text_domain ), 'manage_options', 'vof', array( &$this, 'admin_main' ));
	}

	function admin_main()
	{
		$views = $this->get_all_views();
		$views_html_output = '<table>';
		/* WP_Query $views */
		foreach($views->get_posts() as $view)
		{
			/* @var WP_Post $view */
			$views_html_output .= '
									<tr>
										<td>
											'. $view->post_title .'
										</td>
										<td>
											<a href="'. get_bloginfo('url') .'/?vof_id='.$view->ID.'&vof_format=xml&vof_token='. $this->get_view_vof_token($view->ID) .'">XML</a>
											|
											<a href="'. get_bloginfo('url') .'/?vof_id='.$view->ID.'&vof_format=json&vof_token='. $this->get_view_vof_token($view->ID) .'">JSON</a>
										</td>
									</tr>
									';
		}

		$views_html_output .= '</table>';
		ob_start();
		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2>
				<?php _e( 'Views Output Formats', 'dfwmdt' ); ?>
			</h2>

			<div class="info" style="padding-top: 10px;">
				<?php _e( 'From this screen you can get the URL links for all your Views in XML or JSON formats.', self::text_domain ); ?>
				<p>
					<?php _e('Your global secret', self::text_domain); ?> <abbr title="<?php _e('The token can be reset by deactivating and reactivating the plugin.', self::text_domain); ?>"><?php _e('API token',self::text_domain); ?> </abbr> <?php _e('is:', self::text_domain); ?> <strong><?php echo get_option('vof_token'); ?></strong> <br/>
					<?php _e('Each View also has an individual API token that is valid for that view alone.', self::text_domain); ?> <br/>
				</p>
				<p>
					<?php echo $views_html_output; ?>
				</p>
			</div>
		</div>
		<?php
		echo ob_get_clean();
	}

	/**
	 * Returns a list of all views
	 */
	function get_all_views()
	{
		$args = array('post_type' => 'view', 'order' => 'ASC', 'posts_per_page' => -1, 'post_status' => 'publish');
		return new WP_Query($args);
	}

	/** Adds custom fields to the post and returns it */
	function views_output_merge_custom_fields($posts)
	{
		$posts = array_map(array(&$this, 'views_add_custom_fields_for_post_map'), $posts);
		return $posts;
	}

	function views_add_custom_fields_for_post_map($post, $exclude_hidden_fields = 1)
	{
		//Aggregate custom fields for the post
		$custom_fields = get_post_custom($post->ID);

		$post->custom_fields = array();

		//Main Custom Field loop
		foreach($custom_fields as $key => $value)
		{
			if(sizeof($custom_fields[$key]) === 1)
				$post->custom_fields[$key] = $value[0];
			else
			{
				for($i = 0; $i < sizeof($value); $i++)
					$post->custom_fields[$key]['value_'.($i+1)] = $value[$i];
			}

			//Add in thumbnail url if it exists
			if($key === '_thumbnail_id' && isset($value[0]) && intval($value[0]) !== 0)
			{
				$featured_image_tmp = wp_get_attachment_image_src(intval($value[0]), 'full');
				$post->custom_fields['_thumbnail_url'] = $featured_image_tmp[0];
			}
		}
		return $post;
	}

	/**
	 * Return unique token for a View
	 *
	 * The token is derived from the master token, which should
	 * never be used publicly.
	 */
	function get_view_vof_token($view_id)
	{
		//Use the secondary token to generate an unique token for a specific view
		return md5((string)$view_id . get_option('vof_secondary_token') . get_option('vof_token'));
	}

	/**
	 * Plugin activation function
	 **/
	static function activate()
	{
		//Create a random API token
		$token = md5(wp_generate_password());
		$secondary_token = md5(wp_generate_password());
		add_option('vof_token', $token);
		add_option('vof_secondary_token', $secondary_token);
	}

	/**
	 * Plugin deactivation function
	 */
	static function deactivate()
	{
		//If we re-initialize the plugin, the API keys will be changed
		delete_option('vof_token');
		delete_option('vof_secondary_token');
	}
}

class Views_Output_Formats_XMLSerializer
{
	//functions adopted from http://www.sean-barton.co.uk/2009/03/turning-an-array-or-object-into-xml-using-php/
	public static function generateValidXmlFromObj(stdClass $obj, $node_block='nodes', $node_name='node')
	{
		return self::generateValidXmlFromArray(get_object_vars($obj), $node_block, $node_name);
	}

	public static function generateValidXmlFromArray($array, $node_block='nodes', $node_name='node')
	{
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>';

		//$xml .= '<' . $node_block . '>';
		$xml .= self::generateXmlFromArray($array, $node_name);
		//$xml .= '</' . $node_block . '>';

		return $xml;
	}

	private static function generateXmlFromArray($array, $node_name)
	{
		$xml = '';

		if (is_array($array) || is_object($array))
		{
			foreach ($array as $key => $value)
			{
				//Check for invalid XML element names
				$key = (sanitize_key($key) === '') ? 'field' : sanitize_key($key);

				if (is_numeric($key))
				{
					$key = $node_name;
				}

				//Special case for outer wrapper
				//if($node_name === false)
				//	$xml .= self::generateXmlFromArray($value, $node_name);
				//else
				$xml .= '<' . $key . '>' . self::generateXmlFromArray($value, $node_name) . '</' . $key . '>';
			}
		}
		else
		{
			$xml = htmlspecialchars($array, ENT_QUOTES);
		}

		return $xml;
	}
}

//Init plugin
$vof = new Views_Output_Formats();