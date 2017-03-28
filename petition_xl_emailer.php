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

/* 
* enqueue scripts
*/
add_action( 'wp_enqueue_scripts', 'pxe_enqueue_scripts' );
function pxe_enqueue_scripts() {
	if ( is_page( '14' ) ) {
		wp_enqueue_script( 'main', plugins_url( '/main.js', __FILE__ ), array('jquery'), '1.0', true );
	}
}

/* 
* enqueue styles
*/
add_action( 'wp_enqueue_scripts', 'pxe_enqueue_styles' );
function pxe_enqueue_styles() {
	if ( is_page( '14' ) ) {
		wp_enqueue_style( 'style', plugins_url( '/style.css', __FILE__ ) );
	}
}

// WP ajax hooks attached to form submission
add_action( 'wp_ajax_nopriv_pxe_main_process_async', 'pxe_main_process' );
add_action( 'wp_ajax_pxe_main_process_async', 'pxe_main_process' );

// plugin activation hooks
register_activation_hook( __FILE__, 'pxe_activation' );
// register_activation_hook( __FILE__, 'pxe_install_data' );

/* 
* initialize necessary plugin tables, and CRON event
*/
function pxe_activation() {
	global $wpdb;
	global $pxe_db_version;

	$table_name = $wpdb->prefix . 'pxe_petitioners_newer';
	$table_name_two = $wpdb->prefix . 'pxe_representatives_newer';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		p_id tinyint(11) NOT NULL AUTO_INCREMENT,
		p_name varchar(255) NOT NULL,
		mp_district varchar(255) NOT NULL,
		mla_district varchar(255) NOT NULL,
		council_district varchar(255) NOT NULL,
		new_entry tinyint(1) DEFAULT 1 NOT NULL,
		message varchar(255) NOT NULL,
		postal varchar(255) NOT NULL,
		PRIMARY KEY  (p_id)
	) $charset_collate;";

	// TODO look at the rep_id, it's not really being used, and changes a lot
	// the office_and_districts is unique, and sort of serves that purpose
	$sql2 = "CREATE TABLE $table_name_two (
		rep_id mediumint(9) NOT NULL AUTO_INCREMENT,
		office_and_district varchar(180) NOT NULL,
		rep_name varchar(255) NOT NULL,
		district_name varchar(255) NOT NULL,
		elected_office varchar(255) NOT NULL,
		email varchar(255) NOT NULL,
		PRIMARY KEY  (rep_id),
		UNIQUE KEY office_and_district (office_and_district)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
	dbDelta( $sql2 );

	add_option( 'pxe_db_version', $pxe_db_version );

	// add the cron task
	if (! wp_next_scheduled( 'pxe_weekly_event'  )) {
		wp_schedule_event( time(), '5min', 'pxe_weekly_event' );
	}
}

// add new time intervals (FOR TESTING)
function pxe_cron_schedule_beta($schedules){
    if(!isset($schedules["5min"])){
        $schedules["5min"] = array(
            'interval' => 5*60,
            'display' => __('Once every 5 minutes'));
    }
    if(!isset($schedules["30min"])){
        $schedules["30min"] = array(
            'interval' => 30*60,
            'display' => __('Once every 30 minutes'));
    }
    return $schedules;
}
add_filter('cron_schedules','pxe_cron_schedule_beta');

// add the cron process
add_action( 'pxe_weekly_event', 'pxe_cron_process' );

// register plugin deactivation function
register_deactivation_hook( __FILE__, 'pxe_deactivation' );

// clear plugin CRON event on deactivation
// TODO delete tables on deactivation too?
function pxe_deactivation() {
	wp_clear_scheduled_hook( 'pxe_weekly_event' );
}

// the actual script executed by CRON (wp-cron)
function pxe_cron_process() {
	/* NOTE dependency on another plugin such as wp-mailfrom-ii
	*	due to an issue with WP Mail and CRON, causing the "from" domain
	*	or SERVER_NAME to be undefined when called with true CRON
	*/
	$to = 'yegfootball@gmail.com';
	$subject = 'YEG Soccer Petition';
	$body = '<b>This message sent hourly by WP CRON</b>';
	$headers[] = 'Content-Type: text/html';
	$headers[] = 'charset=UTF-8';
	wp_mail( $to, $subject, $body, $headers );
}

// initialize data
// not really needed, mostly for testing
// function pxe_install_data() {
// 	global $wpdb;
	
// 	$welcome_name = 'Mr. WordPress';
// 	$welcome_text = 'This is a new string please work!';
	
// 	$table_name = $wpdb->prefix . 'pxe_users';
	
// 	$wpdb->insert( 
// 		$table_name, 
// 		array( 
// 			'time' => current_time( 'mysql' ), 
// 			'name' => $welcome_name, 
// 			'text' => $welcome_text, 
// 		) 
// 	);
// }

// TODO rename or split up functionality
// right now this does way too much, hard to see what's going on
// main script called by the AJAX submission of plugin form
function pxe_main_process() {
	// TODO sanitize
	// strip tags, check postal format & email format?
	$_POST['name'] = strip_tags($_POST['name']);

	$petitioner_data = array(
		'postal_code' => $_POST['postalCode'],
		'email' => $_POST['email'],
		'name' => $_POST['name'],
		'messages' => $_POST['messages'],
		);

	$location = pxe_get_geo_coords( $petitioner_data['postal_code'] );

	// TODO make sure to be able to handle false case
	$rep_set = pxe_get_reps( $location->lat, $location->lng );
	$petitioner_data = add_districts( $rep_set, $petitioner_data );

	pxe_send_email( $rep_set, $petitioner_data );
	// prepare the output
	$output = array_values( $rep_set );
	echo json_encode($output);
	// write both the user and all 3 reps to table
	// TODO need to pass the region for this petitioner, grab that from the rep_set
	pxe_insert_petitioner( $petitioner_data );
	// working, but need to iron out the REPLACE vs INSERT vs EXISTS logic
	foreach ($rep_set as $rep_data) {
		pxe_insert_representative( $rep_data );
	}
	die();	
}

// use Google maps API to geocode postal code
// returns assoc array containing longitude and latitude
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

/*
*
*
*/
function add_districts ( $rep_set, $petitioner_data) {
	foreach ( $rep_set as $rep_data ) {
		$petitioner_data[$rep_data['elected_office']] = $rep_data['district_name'];
	}

	return $petitioner_data;
}

// write petitioner info to table
// TODO examine the district_name property of each rep, will need to use
// probably have to make 1 or 3 new columns to hold the district information
function pxe_insert_petitioner ( $petitioner_data ) {
	global $wpdb;
	$comma_separated = implode(",", $petitioner_data['messages']);
	$table_name = $wpdb->prefix . 'pxe_petitioners_newer';
	
	$wpdb->insert( 
		$table_name, 
		array( 
			'p_name' => $petitioner_data['name'], 
			'mp_district' => $petitioner_data['MP'], 
			'mla_district' => $petitioner_data['MLA'], 
			'council_district' => $petitioner_data['Councillor'], 
			'message' => $comma_separated, 
			'postal' => $petitioner_data['postal_code'] 
		) 
	);
}

// write representative info to table, using replace
// TODO think if this is really that efficient
function pxe_insert_representative ( $rep_data ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'pxe_representatives_newer';
	
	$wpdb->replace( 
		$table_name, 
		array( 
			'rep_name' => $rep_data['name'], 
			'district_name' => $rep_data['district_name'], 
			'office_and_district' => $rep_data['elected_office'] . '-' . $rep_data['district_name'], 
			'elected_office' => $rep_data['elected_office'], 
			'email' => $rep_data['email']
		) 
	);	

	/* this is used to test a new rep replacing an old one
	* they would have the same office and district, but different names etc.
	* individually the office and district can be repeated, but the combination
	* is unique
	* not sure how great this setup is 
	 */

	// $wpdb->replace( 
	// 	$table_name, 
	// 	array( 
	// 		'rep_name' => 'Joe Dirt', 
	// 		'district_name' => 'Ward 8', 
	// 		'office_and_district' => 'CouncillorWard 8', 
	// 		'elected_office' => 'Councillor', 
	// 		'email' => $rep_data['email']
	// 	) 
	// );
}

/*
* @param rep_set array : an array of associateive arrays of representatives
* @param messages number : the value for the corresponding message
* @return - TODO
*/
// TODO look at making this reusable?
function pxe_send_email( $rep_set, $petitioner_data ) {
	// TODO possible implement interpolation to insert custom names
	$message_template = pxe_get_template_email( $petitioner_data['messages'], $petitioner_data['name'] );

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

// shortcode for user input form
add_shortcode('show_pxe_form', 'pxe_create_form');
// TODO add custom input HTML structure
function pxe_create_form(){
?>
<form class="rep-petition-form">
	<div class="load-container"></div>
	<div class="form-half first-half">
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
	<div class="form-half second-half">
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