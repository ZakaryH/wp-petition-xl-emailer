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
include_once( ABSPATH . 'wp-content/plugins/petition_xl_emailer/PHP_XLSXWriter-master/xlsxwriter.class.php');


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
	global $wpdb;
	$table_name = $wpdb->prefix . 'pxe_petitioners_newer';
	/* NOTE dependency on another plugin such as wp-mailfrom-ii
	*	due to an issue with WP Mail and CRON, causing the "from" domain
	*	or SERVER_NAME to be undefined when called with true CRON
	*/
	if ( pxe_new_exist() ) {
		$files_created = array();
		$districts_data = array(
			"mp" => array(),
			"mla" => array(),
			"council" => array()
			);
		$results = $wpdb->get_results(
			"
			SELECT p_name, mp_district, mla_district, council_district, postal, message, new_entry 
			FROM wp_pxe_petitioners_newer
			", ARRAY_A
		);

		foreach ($results as $row) {
			$districts_data = pxe_add_district_structure($districts_data, $row);
			if ($row['new_entry'] == 1) {
				$districts_data = pxe_add_writing_rows( $districts_data, $row, 'writing_rows_new' );
			} else {
				$districts_data = pxe_add_writing_rows( $districts_data, $row, 'writing_rows_old' );
			}
		}
		$emails = pxe_get_rep_emails();
		foreach ($districts_data as $district_type => $district) {
			// iterate thru each district
			foreach ($district as $district_name => $all_writing_rows) {
				// only go through the process if that district has new petitioners
				if ( count( $all_writing_rows['writing_rows_new'] ) > 0 ) {
					// relies on the table names pretty heavily either way though
					switch ($district_type) {
						case 'council':
							$email_index = "Councillor-$district_name";
							break;
						case 'mla':
							$email_index = "MLA-$district_name";
							break;
						case 'mp':
							$email_index = "MP-$district_name";
							break;
						
					}
					// rep emails
					$file_name = $district_type . "-" . $district_name;
					$files_created[] = write_to_sheet( $all_writing_rows['writing_rows_new'], $all_writing_rows['writing_rows_old'], $file_name);

					$to = 'yegfootball@gmail.com';
					$subject = 'YEG Soccer Weekly Reminder';
					$body = '<p>This is a reminder of the constituents in your area that have sent you an email of support for YEG Soccer in the past week, we have attached a file/image which shows historical and new supporters of YEG Soccer in your area.</p>';
					$headers[] = 'Content-Type: text/html';
					$headers[] = 'charset=UTF-8';
					$mail_attachment = array( ABSPATH . 'wp-content/plugins/petition_xl_emailer/files/' . $file_name . '.xlsx');
					wp_mail( $to, $subject, $body, $headers, $mail_attachment );
				}
				
			}
		}
		// admin email
		$to = 'yegfootball@gmail.com';
		$subject = 'YEG Soccer New Weekly Petitioners';
		$body = '<p>Attached are all the sheets for districts with new petitioners this week.</p>';
		$headers[] = 'Content-Type: text/html';
		$headers[] = 'charset=UTF-8';
		// note this is plural
		$mail_attachments = array();
		// send all the new sheets with one email
		foreach ($files_created as $created_file) {
			$mail_attachments[] =  ABSPATH . 'wp-content/plugins/petition_xl_emailer/files/' . $created_file;
		}
		wp_mail( $to, $subject, $body, $headers, $mail_attachments );

		pxe_update_petitioners();
		pxe_clean_up_files( $files_created );
	} else {
		// no new petitioners at all, stop
		exit();
	}

}

// check if any new petitioners
function pxe_new_exist () {
	global $wpdb;
	$new_true = 1;

	$new_results = $wpdb->get_col( $wpdb->prepare(
		"
		SELECT wp_pxe_petitioners_newer.new_entry 
		FROM wp_pxe_petitioners_newer 
		WHERE wp_pxe_petitioners_newer.new_entry = %d
		",
		$new_true	
	) );

	if ( $new_results ) {
		if ( count($new_results) > 0 ) {
			return true;
		} else {
			return false;
		}
	} else {
		return false;
	}
}

/*
* Update the boolean new_entry column value to 0 (false) for all 1 (true) entries
*/
function pxe_update_petitioners () {
	global $wpdb;
	$old_bool = 0;

	$wpdb->query( $wpdb->prepare(
		"
		UPDATE wp_pxe_petitioners_newer 
		SET wp_pxe_petitioners_newer.new_entry = %d 
		WHERE wp_pxe_petitioners_newer.new_entry = 1
		",
		$old_bool
		) );
}

/*
* @param filenames - array - list of files to delete, including the extension
* return bool 
*/
function pxe_clean_up_files ( $filenames ) {
	try {
		foreach ($filenames as $filename) {
			unlink( ABSPATH . 'wp-content/plugins/petition_xl_emailer/files/' . $filename);
		}
	} catch (Exception $e) {
		// maybe have a support function, to email admin as notification of failure?
		// put it anywhere significant and pass along the exception information
		return false;
	}
	return true;
}

// query to get rep info
function pxe_get_rep_emails() {
	global $wpdb;
	$rep_emails = array();

	$results = $wpdb->get_results(
		"
		SELECT rep_name, email, district_name, elected_office, office_and_district 
		FROM wp_pxe_representatives_newer
		", ARRAY_A
	);
	$num_rows = $wpdb->num_rows;
	if ($num_rows === 0) {
		// no reps found
		return false;
	} else {
		foreach ($results as $rep_row) {
			$rep_emails[ $rep_row['office_and_district'] ][] = $rep_row['email'];
		}
	}
	return $rep_emails;
}


// push multiple arrays into their district types (mla, mp, council)
// checks if the name has already exists as a key of that district type specifically (same name can be used for different types)
// each name is pushed to the type, each district name is a new array
// which then contains 2 more arrays of both old and new writing rows (petitioner data is also an array)
// returns a multi dimensional (nested) associative array
function pxe_add_district_structure( $district_types, $petitioner_data ) {
	foreach ( $district_types as $dist_type => $district ) {
		// add the structure for a district if that district isn't already in the district_types array
		if ( !array_key_exists( $petitioner_data[$dist_type . '_district'], $district_types[$dist_type] ) ) {
			$district_types[$dist_type] = array_merge( $district_types[$dist_type], array( $petitioner_data[$dist_type . '_district'] => array(
			"writing_rows_new" => array(),
			"writing_rows_old" => array()
			) ) );
		}
	}
	return $district_types;
}

// add the old and new writings rows to the containing object
function pxe_add_writing_rows ( $district_types, $petitioner, $rows_age ) {
	foreach ($district_types as $dist_type => $district) {
		// TODO this is only going to be false if two people live in the same district and have the same name
		// is that even a problem? Not really
		if (!in_array_r($petitioner['p_name'], $district_types[$dist_type][$petitioner[$dist_type . '_district']])) {
			// push each "new" petitioner to the "writing_rows_new" for each of their district_types
			$district_types[$dist_type][$petitioner[$dist_type . '_district']][$rows_age][] = array(
				"name" => $petitioner['p_name'],
				"mla_district" => $petitioner['mla_district'],
				"mp_district" => $petitioner['mp_district'],
				"council_district" => $petitioner['council_district'],
				"message" => $petitioner['message']
			);
		}
	}
	return $district_types;
}
/*
* creates a sheet, writes the new entries first in bold, then the old ones - both must exist to get here, if no new it stops earlier
* returns - string - name of the created file including the file extension (.xlxs)
*/
function write_to_sheet( $writing_rows_new, $writing_rows_old, $filename ) {
	$new_style = array( 'font'=>'Arial','font-size'=>10,'font-style'=>'bold', 'fill'=>'#fff', 'halign'=>'center', 'border'=>'left,right,top,bottom');
	$old_style = array( 'font'=>'Arial','font-size'=>10, 'fill'=>'#fff', 'halign'=>'center', 'border'=>'left,right,top,bottom');
	$header = array(
		'Name' => 'string',
		'MLA District' => 'string',
		'MP District' => 'string',
		'Council District' => 'string',
		'Messages' => 'string'
	);
	$writer = new XLSXWriter();

	$writer->writeSheetHeader('Sheet1', $header);

	$writer->writeSheetRow('Sheet1', array(
		'New Petitioners'
	));
	foreach ($writing_rows_new as $write_row) {
		$writer->writeSheetRow('Sheet1', $write_row, $new_style);
	}
	$writer->writeSheetRow('Sheet1', array(
		'Archived Petitioners'
	));
	foreach ($writing_rows_old as $write_row) {
		$writer->writeSheetRow('Sheet1', $write_row, $old_style);
	}
	try {
		$writer->writeToFile( ABSPATH . 'wp-content/plugins/petition_xl_emailer/files/' . $filename . '.xlsx');
	} catch (Exception $e) {
		$to = 'yegfootball@gmail.com';
		$subject = 'YEG Soccer Petition';
		$body = "Error: " . $e->getMessage() ;
		$headers[] = 'Content-Type: text/html';
		$headers[] = 'charset=UTF-8';
		wp_mail( $to, $subject, $body, $headers );
		// echo $e;	
	}

	return $filename . '.xlsx';
}


// for multidimensional arrays using recursion
function in_array_r($needle, $haystack, $strict = false) {
    foreach ($haystack as $item) {
        if (($strict ? $item === $needle : $item == $needle) || (is_array($item) && in_array_r($needle, $item, $strict))) {
            return true;
        }
    }

    return false;
}

// TODO rename or split up functionality
// right now this does way too much, hard to see what's going on
// main script called by the AJAX submission of plugin form
function pxe_main_process() {
	// TODO sanitize
	// strip tags, check postal format & email format?
	$_POST['name'] = strip_tags($_POST['name']);
	$_POST['postalCode'] = strip_tags($_POST['postalCode']);

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

	pxe_insert_petitioner( $petitioner_data );
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
// make it usable for 1st email, second email, admin email and error email?
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
	$message = "<p>This email was sent to you by YEG Soccer on behalf of: $username that has identified they live in your constituency.</p>";
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