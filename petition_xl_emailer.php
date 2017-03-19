<?php

/**
 *
 * @link              http://zakaryhughes.com
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Petition XLS Emailer
 * Plugin URI:        https://github.com/ZakaryH
 * Description:       Allow users to find local Canadian municipal representatives, email them, store users' data, later write that data to a spreadsheet and email representatives again with attached sheet
 * Version:           1.0.0
 * Author:            Zak Hughes
 * Author URI:        http://zakaryhughes.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-pxe
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) or exit;


global $pxe_db_version;
$pxe_db_version = '1.0';


// WP ajax hooks attached to form submission
add_action( 'wp_ajax_nopriv_pxe_main_process_async', 'pxe_main_process' );
add_action( 'wp_ajax_pxe_main_process_async', 'pxe_main_process' );

// plugin activation hooks
register_activation_hook( __FILE__, 'pxe_install' );
register_activation_hook( __FILE__, 'pxe_install_data' );

// TODO totally reformat these tables
// think about what data they need to hold and interact with each other
function pxe_install() {
	global $wpdb;
	global $pxe_db_version;

	$table_name = $wpdb->prefix . 'pxe_users';
	$table_name_two = $wpdb->prefix . 'pxe_reps';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		name tinytext NOT NULL,
		text text NOT NULL,
		url varchar(55) DEFAULT '' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	$sql2 = "CREATE TABLE $table_name_two (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		name tinytext NOT NULL,
		text text NOT NULL,
		url varchar(55) DEFAULT '' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
	dbDelta( $sql2 );

	add_option( 'pxe_db_version', $pxe_db_version );
}

function pxe_install_data() {
	global $wpdb;
	
	$welcome_name = 'Mr. WordPress';
	$welcome_text = 'Congratulations, you just completed the installation!';
	
	$table_name = $wpdb->prefix . 'pxe_users';
	
	$wpdb->insert( 
		$table_name, 
		array( 
			'time' => current_time( 'mysql' ), 
			'name' => $welcome_name, 
			'text' => $welcome_text, 
		) 
	);
}

// TODO rename or split up functionality
// right now this does way too much, hard to see what's going on
function pxe_main_process() {
	$data = array();
	// TODO sanitize
	$postal_code = $_POST['postalCode'];
	$user_email = $_POST['email'];
	$user_name = $_POST['name'];
	// template needs to be an array of options
	$user_messages = $_POST['messages'];


	$location = pxe_get_geo_coords( $postal_code );

	// TODO make sure to be able to handle false case
	$rep_set = pxe_get_reps( $location->lat, $location->lng );
	// TODO INSERT to a table the POSTAL CODE, NAME, EMAIL, and use the rep info
	// TODO send an email using specified template, and use the rep info 
	// TODO if email fails, notify user
	// if ( pxe_send_email( $rep_set ) ) {
	// } else {
	// 	echo json_encode( array(
	// 		"error" => true,
	// 		"msg" => "something went wrong"
	// 		) );
	// }
	pxe_send_email( $rep_set, $user_messages, $user_name );
	// prepare the output
	$output = array_values( $rep_set );
	echo json_encode($output);
	pxe_write_to_table( $user_name, $postal_code );
	die();	
}

function pxe_get_geo_coords ( $postal_code ) {
	$url = 'https://maps.googleapis.com/maps/api/geocode/json?components=postal_code:' . $postal_code;
	
	$request = wp_remote_get( esc_url_raw( $url ) , array( 'timeout' => 120) );
	// TODO better error handling?
	if ( is_wp_error( $request ) ) {
		return false;
	}
	$body = wp_remote_retrieve_body( $request );
	$response = json_decode($body);
	$location = $response->results[0]->geometry->location;
	return $location;
}

/*
* @param lat float geographic latitude value
* @param long float geographic longitude value
* @return - bool or array of associative arrays
*/
function pxe_get_reps ( $lat, $long ) {
	$url = 'https://represent.opennorth.ca/representatives/?point=' . $lat . ',' . $long;
	$request = wp_remote_get( esc_url_raw( $url ) );

	if ( is_wp_error( $request ) ) {
		return false;
	}

	$api_response = json_decode( wp_remote_retrieve_body( $request ), true );
	// grab only the rep objects from response
	$api_response = $api_response['objects'];
	// filter the array
	// TODO make this alterable by the plugin admin, also add a plugin admin
	return array_filter( $api_response, function( $v ) {
		if ( $v['elected_office'] !== "Mayor") {
			return $v;
		}
	});
}
// Cron job in here?

// write user and rep info to table(s)
function pxe_write_to_table ( $name, $postal_code ) {
	global $wpdb;
	
	$welcome_name = $name;
	$welcome_text = $postal_code;
	
	$table_name = $wpdb->prefix . 'pxe_users';
	
	$wpdb->insert( 
		$table_name, 
		array( 
			'time' => current_time( 'mysql' ), 
			'name' => $welcome_name, 
			'text' => $welcome_text, 
		) 
	);
}

/*
* @param rep_set array : an array of associateive arrays of representatives
* @param messages number : the value for the corresponding message
* @return - TODO
*/
// TODO look at making this reusable?
function pxe_send_email( $rep_set, $messages, $username ) {
	// TODO possible implement interpolation to insert custom names
	$message_template = pxe_get_template_email( $messages, $username );
	// send out 3 emails
	foreach ($rep_set as $key => $value) {
		$rep_email = $rep_set[$key]['email'];
		$rep_name = $rep_set[$key]['name'];
		$rep_office = $rep_set[$key]['elected_office'];
		// TODO grab templates based on the user input
		// send that template to the rep, which gets passed in
		$to = 'yegfootball@gmail.com';
		$subject = 'YEG Soccer Petition';
		// $body = 'name: ' . $rep_name . ' email: ' . $rep_email . 'elected office: ' . $rep_office;
		$body = $message_template;
		$headers[] = 'Content-Type: text/html';
		// $headers[] = 'Cc: zakhughesweb@gmail.com';
		$headers[] = 'charset=UTF-8';

		// $headers = array( 'Content-Type: text/html; charset=UTF-8; Cc: zakhughesweb@gmail.com;' );
		
		add_filter( 'wp_mail_from_name', "Steve Jobs");
		// TODO swap to use PHPMailer instead of the php mail
		// attachments etc.
		wp_mail( $to, $subject, $body, $headers );
	}
}

/*
* @param messages number : an id for the template message
* @return - string - email message template
*/
// return the template corresponding to the message id
function pxe_get_template_email( $messages, $username ) {
	// look to see what messages are selected, grab and return all the corresponding messages
	// concatentate the messages for now?
	// TODO MAKE THIS REFERENCE A TEMPLATE OR SOMETHING, DO NOT MAKE PEOPLE EDIT THIS
	// ASKING FOR TROUBLE
	// TODO implement HTML tags and formatting for the email and the template
	// loop through the template id array and make a block of all the messages
	$message = "<p>This email was sent to you by YEG Soccer on behalf of :" . $username . " that has identified they live in your constituency.</p>";
	$message .= "<p>Dear representative, I am a supporter of soccer and of YEG Soccer, I believe that the City, Province and Federal government need to do more to support the Worlds Beautiful Game.  There are inherent benefits to soccer for our society including health, public safety, leadership, and gender equality – and the good news is that 44% of all Canadian children are already big fans!  Help us use soccer as positive influence, it is already there, it is already popular we just need your support to use its already far reach to benefit our community even further.</p>";

	foreach ($messages as $msg_id) {
		switch ( $msg_id ) {
			case 'msg_1':
				$message .= "<p>Edmonton has a successful Professional Soccer Club called FC Edmonton founded in 2010, by Tom and Dave Fath. They have coordinated important events for our city including a memorial for Constable Daniel Woodall (a big soccer fan).  Their logo was designed with Edmonton colors in mind, and play in an Edmonton Eskimos branded facility.  We believe they need more support to be as successful as they should be in our sports crazy city.</p>";
				break;
			case 'msg_2':
				$message .= "<p>As it stands today soccer is implicitly not allowed in City of Edmonton recreational facility gyms.  We believe the new template for recreational facilities needs to have provisions for citizens to practice and play the overwhelmingly popular sport of Soccer.</p>";
				break;
			case 'msg_3':
				$message .= "<p>We are already extremely behind in capacity for soccer facilities and we need to catch up in order to meet demand.  We also need to ensure that the success of facilities are not based on capacity alone, the facilities need to be accessible, affordable and of a high quality.  To achieve this ambitious but necessary result we need a comprehensive, well thought out and community engaged plan that will allow us to address issues such as boarded vs non.</p>";
				break;
			case 'msg_4':
				$message .= "<p>I support collaboration with local Soccer clubs to meet the needs of the sport in Edmonton. There are several local clubs that are looking to develop indoor facilities for their teams because of the significant lack of indoor facilities in the Edmonton area.  They need your support.  There are a number of open minded leagues, clubs, facility operators that are willing to coordinate to serve the greater community in collaboration with government entities to make things happen in our wonderful winter city.</p>";
				break;
			default:
				$message .= "This message sent by YEG Soccer on the behalf of the petitioner.";
				break;
		}

	}
	$message = $message . "<p>Sent at: " . current_time( 'mysql' ) . "</p>";
	return $message;
}
// enqueue scripts 
add_action( 'wp_enqueue_scripts', 'pxe_enqueue_scripts' );
function pxe_enqueue_scripts() {
	if ( is_page( '14' ) ) {
		wp_enqueue_script( 'main', plugins_url( '/main.js', __FILE__ ), array('jquery'), '1.0', true );

	}
}

// enqueue styles
add_action( 'wp_enqueue_scripts', 'pxe_enqueue_styles' );
function pxe_enqueue_styles() {
	if ( is_page( '14' ) ) {
		wp_enqueue_style( 'style', plugins_url( '/style.css', __FILE__ ) );
	}
}

// shortcode for user input form
add_shortcode('show_pxe_form', 'pxe_create_form');
// TODO add custom input HTML structure
function pxe_create_form(){
?>
<form class="rep-petition-form">
	<div class="load-container"></div>
	<div class="form-half">
		<div class="form-group">
			<label for="user_name">Name</label>
			<input class="form-control" type="text" id="user_name" name="user_name" autocomplete="off" placeholder="Your name">
		</div>
		<div class="form-group">
			<label for="user_email">Email</label>
			<input class="form-control" type="email" id="user_email" name="user_email" autocomplete="off" placeholder="Your email">
		</div>
		<div class="form-group">
			<label for="postal_code">Postal Code</label>
			<input class="form-control" type="text" id="postal_code" name="postal_code" autocomplete="off" placeholder="Your postal code">
		</div>
		<input type="submit" value="Submit" class="btn btn-warning btn-block">
	</div>
	<div class="form-half">
		<h4>I Support...</h4>
		<div class="form-group">
			<input checked="true" type="checkbox" id="template_message_one" value="msg_1" data-msg="Edmonton has a successful Professional Soccer Club called FC Edmonton founded in 2010, by Tom and Dave Fath. They have coordinated important events for our city including a memorial for Constable Daniel Woodall (a big soccer fan).  Their logo was designed with Edmonton colors in mind, and play in an Edmonton Eskimos branded facility.  We believe they need more support to be as successful as they should be in our sports crazy city.">
			<label class="inline-label" for="template_message_one">FC Edmonton</label>
		</div>

		<div class="form-group">
			<input checked="true" type="checkbox" id="template_message_two" value="msg_2" data-msg="As it stands today soccer is implicitly not allowed in City of Edmonton recreational facility gyms.  We believe the new template for recreational facilities needs to have provisions for citizens to practice and play the overwhelmingly popular sport of Soccer.">
			<label class="inline-label" for="template_message_two">City of Edmonton including indoor soccer fields in their Recreational Facility</label>
		</div>

		<div class="form-group">
			<input checked="true" type="checkbox" id="template_message_three" value="msg_3" data-msg="We are already extremely behind in capacity for soccer facilities and we need to catch up in order to meet demand.  We also need to ensure that the success of facilities are not based on capacity alone, the facilities need to be accessible, affordable and of a high quality.  To achieve this ambitious but necessary result we need a comprehensive, well thought out and community engaged plan that will allow us to address issues such as boarded vs non.">
			<label class="inline-label" for="template_message_three">the commission of a 10 year plan for soccer facilities in the City of Edmonton</label>
		</div>

		<div class="form-group">
			<input checked="true" type="checkbox" id="template_message_four" value="msg_4" data-msg="I support collaboration with local Soccer clubs to meet the needs of the sport in Edmonton. There are several local clubs that are looking to develop indoor facilities for their teams because of the significant lack of indoor facilities in the Edmonton area.  They need your support.  There are a number of open minded leagues, clubs, facility operators that are willing to coordinate to serve the greater community in collaboration with government entities to make things happen in our wonderful winter city.">
			<label class="inline-label" for="template_message_four">collaboration with local soccer clubs to plan, build and maintain indoor soccer facilities</label>
		</div>
		<div class="petition-message-display">
			<p>Dear representative, I am a supporter of soccer and of YEG Soccer, I believe that the City, Province and Federal government need to do more to support the Worlds Beautiful Game.  There are inherent benefits to soccer for our society including health, public safety, leadership, and gender equality – and the good news is that 44% of all Canadian children are already big fans!  Help us use soccer as positive influence, it is already there, it is already popular we just need your support to use its already far reach to benefit our community even further.</p>
		</div>
	</div>
	<div id="petition-error-div"></div>
<!-- 	<div class="form-submit-container">
		<input type="submit" value="Submit" class="btn btn-warning btn-block">
	</div> -->
</form>
<div id="rep-info-display" class="rep-petition-form">
</div>
<?php
}