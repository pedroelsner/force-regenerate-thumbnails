<?php
/*
Plugin Name:  Force Regenerate Thumbnails
Plugin URI:   http://pedroelsner.com/2012/08/forcando-a-atualizacao-de-thumbnails-no-wordpress
Description:  Delete and REALLY force the regenerate thumbnail. Based in plugin: Regenerate Thumbnails - All credits and thanks to Viper007Bond
Version:      1.3
Author:       Pedro Elsner
Author URI:   http://www.pedroelsner.com/
*/


/**
 * Force Regenerate Thumbnails
 * 
 * @since 1.0
 */
class ForceRegenerateThumbnails {

	/**
	 * Register ID of management page
	 * 
	 * @var
	 * @since 1.0
	 */
	var $menu_id;

	/**
	 * User capability
	 * 
	 * @access public
	 * @since 1.0
	 */
	public $capability;

	/**
	 * Plugin initialization
	 * 
	 * @access public
	 * @since 1.0
	 */
	function ForceRegenerateThumbnails() {

		load_plugin_textdomain('force-regenerate-thumbnails', false, '/force-regenerate-thumbnails/localization');

		add_action('admin_menu',                              array(&$this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts',                   array(&$this, 'admin_enqueues'));
		add_action('wp_ajax_regeneratethumbnail',             array(&$this, 'ajax_process_image'));
		add_filter('media_row_actions',                       array(&$this, 'add_media_row_action'), 10, 2);
		add_action('admin_head-upload.php',                   array(&$this, 'add_bulk_actions_via_javascript'));
		add_action('admin_action_bulk_force_regenerate_thumbnails', array(&$this, 'bulk_action_handler'));
		add_action('admin_action_-1',                         array(&$this, 'bulk_action_handler'));

		// Allow people to change what capability is required to use this plugin
		$this->capability = apply_filters('regenerate_thumbs_cap', 'manage_options');
	}

	/**
	 * Register the management page
	 * 
	 * @access public
	 * @since 1.0
	 */
	function add_admin_menu() {
		$this->menu_id = add_management_page(__('Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ), __( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ), $this->capability, 'force-regenerate-thumbnails', array(&$this, 'force_regenerate_interface') );
	}

	/**
	 * Enqueue the needed Javascript and CSS
	 * 
	 * @param string $hook_suffix
	 * @access public
	 * @since 1.0
	 */
	function admin_enqueues($hook_suffix) {

		if ($hook_suffix != $this->menu_id) {
			return;
		}

		// WordPress 3.1 vs older version compatibility
		if (wp_script_is('jquery-ui-widget', 'registered')) {
			wp_enqueue_script('jquery-ui-progressbar', plugins_url('jquery-ui/jquery.ui.progressbar.min.js', __FILE__), array('jquery-ui-core', 'jquery-ui-widget'), '1.8.6');
		} else {
			wp_enqueue_script('jquery-ui-progressbar', plugins_url('jquery-ui/jquery.ui.progressbar.min.1.7.2.js', __FILE__), array('jquery-ui-core'), '1.7.2');
		}
		
		wp_enqueue_style('jquery-ui-regenthumbs', plugins_url('jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__), array(), '1.7.2');
	}

	/**
	 * Add a "Force Regenerate Thumbnails" link to the media row actions
	 *
	 * @param array $actions
	 * @param string $post
	 * @return array
	 * @access public
	 * @since 1.0
	 */
	function add_media_row_action($actions, $post) {

		if ('image/' != substr($post->post_mime_type, 0, 6) || !current_user_can($this->capability))
			return $actions;

		$url = wp_nonce_url( admin_url( 'tools.php?page=force-regenerate-thumbnails&goback=1&ids=' . $post->ID ), 'force-regenerate-thumbnails' );
		$actions['regenerate_thumbnails'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr( __( "Regenerate the thumbnails for this single image", 'force-regenerate-thumbnails' ) ) . '">' . __( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ) . '</a>';

		return $actions;
	}

	/**
	 * Add "Force Regenerate Thumbnails" to the Bulk Actions media dropdown
	 * 
	 * @param array $actions Actions list
	 * @return array
	 * @access public
	 * @since 1.0
	 */
	function add_bulk_actions($actions) {

		$delete = false;
		if (!empty($actions['delete'])) {
			$delete = $actions['delete'];
			unset($actions['delete']);
		}

		$actions['bulk_force_regenerate_thumbnails'] = __('Force Regenerate Thumbnails', 'force-regenerate-thumbnails');

		if ($delete) {
			$actions['delete'] = $delete;
		}

		return $actions;
	}

	/**
	 * Add new items to the Bulk Actions using Javascript
	 * 
	 * @access public
	 * @since 1.0
	 */
	function add_bulk_actions_via_javascript() {

		if (!current_user_can( $this->capability)) {
			return;
		}
		?>
		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="action"] option:last-child').before('<option value="bulk_force_regenerate_thumbnails"><?php echo esc_attr(__('Force Regenerate Thumbnails', 'force-regenerate-thumbnails')); ?></option>');
			});
		</script>
		<?php
	}

	/**
	 * Handles the bulk actions POST
	 * 
	 * @access public
	 * @since 1.0
	 */
	function bulk_action_handler() {

		if (empty($_REQUEST['action']) || ('bulk_force_regenerate_thumbnails' != $_REQUEST['action'] && 'bulk_force_regenerate_thumbnails' != $_REQUEST['action2'])) {
			return;
		}

		if (empty($_REQUEST['media']) || ! is_array($_REQUEST['media'])) {
			return;
		}
		
		check_admin_referer('bulk-media');
		$ids = implode(',', array_map('intval', $_REQUEST['media']));

		wp_redirect(add_query_arg('_wpnonce', wp_create_nonce('force-regenerate-thumbnails'), admin_url('tools.php?page=force-regenerate-thumbnails&goback=1&ids=' . $ids)));
		exit();
	}


	/**
	 * The user interface plus thumbnail regenerator
	 * 
	 * @access public
	 * @since 1.0
	 */
	function force_regenerate_interface() {

		global $wpdb;
		?>

<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap regenthumbs">
	<h2><?php _e('Force Regenerate Thumbnails', 'force-regenerate-thumbnails'); ?></h2>

	<?php

		// If the button was clicked
		if (!empty($_POST['force-regenerate-thumbnails'] ) || !empty($_REQUEST['ids'])) {

			// Capability check
			if (!current_user_can( $this->capability))
				wp_die(__('Cheatin&#8217; uh?'));

			// Form nonce check
			check_admin_referer('force-regenerate-thumbnails');

			// Create the list of image IDs
			if (!empty($_REQUEST['ids'])) {
				$images = array_map('intval', explode(',', trim($_REQUEST['ids'], ',')));
				$ids = implode(',', $images);
			} else {

				// Directly querying the database is normally frowned upon, but all
				// of the API functions will return the full post objects which will
				// suck up lots of memory. This is best, just not as future proof.
				if (!$images = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC")) {
					echo '	<p>' . sprintf(__("Unable to find any images. Are you sure <a href='%s'>some exist</a>?", 'force-regenerate-thumbnails'), admin_url('upload.php?post_mime_type=image')) . "</p></div>";
					return;
				}

				// Generate the list of IDs
				$ids = array();
				foreach ($images as $image) {
					$ids[] = $image->ID;
				}
				$ids = implode(',', $ids);
			}

			echo '	<p>' . __("Please be patient while the thumbnails are regenerated. You will be notified via this page when the regenerating is completed.", 'force-regenerate-thumbnails') . '</p>';

			$count = count($images);
			$text_goback = (!empty($_GET['goback']))
						 ? sprintf(__('To go back to the previous page, <a href="%s">click here</a>.', 'force-regenerate-thumbnails'), 'javascript:history.go(-1)')
						 : '';

			$text_failures = sprintf(__('All done! %1$s image(s) were successfully resized in %2$s seconds and there were %3$s failure(s). To try regenerating the failed images again, <a href="%4$s">click here</a>. %5$s', 'force-regenerate-thumbnails'), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url(wp_nonce_url(admin_url('tools.php?page=force-regenerate-thumbnails&goback=1'), 'force-regenerate-thumbnails') . '&ids=') . "' + rt_failedlist + '", $text_goback);
			$text_nofailures = sprintf(__('All done! %1$s image(s) were successfully resized in %2$s seconds and there were 0 failures. %3$s', 'force-regenerate-thumbnails'), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback);
	?>

	<noscript><p><em><?php _e('You must enable Javascript in order to proceed!', 'force-regenerate-thumbnails') ?></em></p></noscript>

	<div id="regenthumbs-bar" style="position:relative;height:25px;">
		<div id="regenthumbs-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>

	<p><input type="button" class="button hide-if-no-js" name="regenthumbs-stop" id="regenthumbs-stop" value="<?php _e('Abort Process', 'force-regenerate-thumbnails') ?>" /></p>

	<h3 class="title"><?php _e('Process Information', 'force-regenerate-thumbnails'); ?></h3>

	<p>
		<?php printf(__('Total Images: %s', 'force-regenerate-thumbnails'), $count); ?><br />
		<?php printf(__('Successes:: %s', 'force-regenerate-thumbnails'), '<span id="regenthumbs-debug-successcount">0</span>'); ?><br />
		<?php printf(__('Failures: %s', 'force-regenerate-thumbnails'), '<span id="regenthumbs-debug-failurecount">0</span>'); ?>
	</p>

	<ol id="regenthumbs-debuglist">
		<li style="display:none"></li>
	</ol>

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var i;
			var rt_images = [<?php echo $ids; ?>];
			var rt_total = rt_images.length;
			var rt_count = 1;
			var rt_percent = 0;
			var rt_successes = 0;
			var rt_errors = 0;
			var rt_failedlist = '';
			var rt_resulttext = '';
			var rt_timestart = new Date().getTime();
			var rt_timeend = 0;
			var rt_totaltime = 0;
			var rt_continue = true;

			// Create the progress bar
			$("#regenthumbs-bar").progressbar();
			$("#regenthumbs-bar-percent").html("0%");

			// Stop button
			$("#regenthumbs-stop").click(function() {
				rt_continue = false;
				$('#regenthumbs-stop').val("<?php echo $this->esc_quotes(__('Stopping...', 'force-regenerate-thumbnails')); ?>");
			});

			// Clear out the empty list element that's there for HTML validation purposes
			$("#regenthumbs-debuglist li").remove();

			// Called after each resize. Updates debug information and the progress bar.
			function RegenThumbsUpdateStatus(id, success, response) {
				$("#regenthumbs-bar").progressbar("value", (rt_count / rt_total) * 100);
				$("#regenthumbs-bar-percent").html(Math.round((rt_count / rt_total) * 1000) / 10 + "%");
				rt_count = rt_count + 1;

				if (success) {
					rt_successes = rt_successes + 1;
					$("#regenthumbs-debug-successcount").html(rt_successes);
					$("#regenthumbs-debuglist").append("<li>" + response.success + "</li>");
				}
				else {
					rt_errors = rt_errors + 1;
					rt_failedlist = rt_failedlist + ',' + id;
					$("#regenthumbs-debug-failurecount").html(rt_errors);
					$("#regenthumbs-debuglist").append("<li>" + response.error + "</li>");
				}
			}

			// Called when all images have been processed. Shows the results and cleans up.
			function RegenThumbsFinishUp() {
				rt_timeend = new Date().getTime();
				rt_totaltime = Math.round((rt_timeend - rt_timestart) / 1000);

				$('#regenthumbs-stop').hide();

				if (rt_errors > 0) {
					rt_resulttext = '<?php echo $text_failures; ?>';
				} else {
					rt_resulttext = '<?php echo $text_nofailures; ?>';
				}

				$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
				$("#message").show();
			}

			// Regenerate a specified image via AJAX
			function RegenThumbs(id) {
				$.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: "regeneratethumbnail", id: id },
					success: function(response) {
						if (response.success) {
							RegenThumbsUpdateStatus(id, true, response);
						} else {
							RegenThumbsUpdateStatus(id, false, response);
						}

						if (rt_images.length && rt_continue) {
							RegenThumbs(rt_images.shift());
						} else {
							RegenThumbsFinishUp();
						}
					},
					error: function(response) {
						RegenThumbsUpdateStatus(id, false, response);

						if (rt_images.length && rt_continue) {
							RegenThumbs(rt_images.shift());
						} else {
							RegenThumbsFinishUp();
						}
					}
				});
			}

			RegenThumbs(rt_images.shift());
		});
	// ]]>
	</script>
	<?php
		}

		// No button click? Display the form.
		else {
	?>
	<form method="post" action="">
		<?php wp_nonce_field('force-regenerate-thumbnails') ?>

		<h3>All Thumbnails</h3>

		<p><?php printf(__("Pressing the follow button, you can regenerate thumbnails for all images that you have uploaded to your blog.", 'force-regenerate-thumbnails'), admin_url('options-media.php')); ?></p>

		<p>
			<noscript><p><em><?php _e('You must enable Javascript in order to proceed!', 'force-regenerate-thumbnails') ?></em></p></noscript>
			<input type="submit" class="button-primary hide-if-no-js" name="force-regenerate-thumbnails" id="force-regenerate-thumbnails" value="<?php _e('Regenerate All Thumbnails', 'force-regenerate-thumbnails') ?>" />
		</p>

		</br>
		<h3>Specific Thumbnails</h3>

		<p><?php printf(__("You can regenerate all thumbnails for specific images from the <a href='%s'>Media</a> page. (WordPress 3.1+ only)", 'force-regenerate-thumbnails'), admin_url('upload.php')); ?></p>
	</form>
	<?php
		} // End if button
	?>
</div>

<?php
	}

	/**
	 * Process a single image ID (this is an AJAX handler)
	 * 
	 * @access public
	 * @since 1.0
	 */
	function ajax_process_image() {

		// Don't break the JSON result
		@error_reporting(0);

		header('Content-type: application/json');

		$id = (int) $_REQUEST['id'];
		$image = get_post($id);

		if (!$image || 'attachment' != $image->post_type || 'image/' != substr($image->post_mime_type, 0, 6)) {
			die(json_encode(array('error' => sprintf(__('<span style="color: #FF3366;">Failed: %s is an invalid image ID.</span>', 'force-regenerate-thumbnails'), esc_html($_REQUEST['id'])))));
            return; 
        }

		if (!current_user_can($this->capability)) {
			$this->die_json_error_msg($image->ID, __('<span style="color: #FF3366;">Your user account does not have permission to regenerate images</span>', 'force-regenerate-thumbnails'));
            return;
        }

		$fullsizepath = get_attached_file($image->ID);

		if (false === $fullsizepath || !file_exists($fullsizepath)) {
			$this->die_json_error_msg($image->ID, sprintf( __('<span style="color: #FF3366;">The originally uploaded image file cannot be found at %s</span>', 'force-regenerate-thumbnails'), '<code>' . esc_html($fullsizepath) . '</code>'));
            return;
        }
        
        // 5 minutes per image should be PLENTY
		@set_time_limit(900);
        
        
        
        /**
         * Grant image deleted
         * @since 1.1
         */
        $message = '';
        
        
        /**
         * Fix for format JPEG
         * 12-11-2012 11h AM
         */
        $array_path = explode(DIRECTORY_SEPARATOR, $fullsizepath);
        $array_file = explode('.', $array_path[count($array_path)-1]);
        
        $imageFormat = $array_file[count($array_file)-1];
        
        unset($array_path[count($array_path)-1]);
        unset($array_file[count($array_file)-1]);
        
        $imagePath = implode(DIRECTORY_SEPARATOR, $array_path) . DIRECTORY_SEPARATOR . implode('.', $array_file);
        
        
        /**
         * Continue
         */
        $dirPath = explode(DIRECTORY_SEPARATOR, $imagePath);
        $imageName = sprintf("%s-", $dirPath[count($dirPath)-1]);
        unset($dirPath[count($dirPath)-1]);
        $dirPath = sprintf("%s%s", implode(DIRECTORY_SEPARATOR, $dirPath), DIRECTORY_SEPARATOR);
        
        // Read and delete files
        $dir  = opendir($dirPath);
        $files = array();
        while ($file = readdir($dir)) {            
            if (!(strrpos($file, $imageName) === false)) {
                $thumbnail = explode($imageName, $file);
                if ($thumbnail[0] == "") {
                    $thumbnailFormat = substr($thumbnail[1], -4);
                    $thumbnail = substr($thumbnail[1], 0, strlen($thumbnail[1]) - 4);
                    $thumbnail = explode('x', $thumbnail);
                    if (count($thumbnail) == 2) {
                        if (is_numeric($thumbnail[0]) && is_numeric($thumbnail[1])) {
                            $message .= sprintf('<br /> - ' . __("Thumbnail: %sx%s was deleted.", 'force-regenerate-thumbnails'), $thumbnail[0], $thumbnail[1]);
                            @unlink($dirPath . $imageName . $thumbnail[0] . 'x' . $thumbnail[1] . $thumbnailFormat);
                        }
                    }
                }
            }
        }
        
        
        /**
         * Regenerate
         */
		$metadata = wp_generate_attachment_metadata($image->ID, $fullsizepath);

		if (is_wp_error($metadata)) {
			$this->die_json_error_msg($image->ID, $metadata->get_error_message());
            return;
        }

		if (empty($metadata)) {
			$this->die_json_error_msg($image->ID, __('<span style="color: #FF3366;">Unknown failure reason.</span>', 'force-regenerate-thumbnails'));
            return;
        }
        
		wp_update_attachment_metadata($image->ID, $metadata);
		
        $message = sprintf(__('<b>&quot;%1$s&quot; (ID %2$s): All thumbnails was successfully regenerated in %3$s seconds.</b>', 'force-regenerate-thumbnails'), esc_html(get_the_title($image->ID)), $image->ID, timer_stop()) . $message;
		die(json_encode(array('success' => $message)));
	}

	/**
	 * Helper to make a JSON error message
	 *
	 * @param integer $id
	 * @param string #message
	 * @access public
	 * @since 1.0
	 */
	function die_json_error_msg($id, $message) {
		die(json_encode(array('error' => sprintf(__('&quot;%1$s&quot; (ID %2$s) failed to resize. The error message was: %3$s', 'force-regenerate-thumbnails'), esc_html(get_the_title($id)), $id, $message))));
	}

	/**
	 * Helper function to escape quotes in strings for use in Javascript
	 *
	 * @param string #message
	 * @return string
	 * @access public
	 * @since 1.0
	 */
	function esc_quotes($string) {
		return str_replace('"', '\"', $string);
	}
}


/**
 * Start
 */
function ForceRegenerateThumbnails() {
	global $ForceRegenerateThumbnails;
	$ForceRegenerateThumbnails = new ForceRegenerateThumbnails();
}
add_action('init', 'ForceRegenerateThumbnails');

?>