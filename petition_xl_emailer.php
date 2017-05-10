<?php
/**
 *
 * @link              http://zakaryhughes.com
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Petition XLS Emailer
 * Plugin URI:        https://github.com/ZakaryH/wp-petition-xl-emailer
 * Description:       Allow users to find local Canadian municipal representatives, email them, store users' data, later write that data to a spreadsheet and email representatives again with attached sheet
 * Version:           1.0.0
 * Author:            Zak Hughes
 * Author URI:        http://zakaryhughes.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-pxe
 */

defined( 'ABSPATH' ) or exit;

// TODO admin section
// TODO cleanup & optimization
global $pxe_db_version;
$pxe_db_version = '1.0';
include_once( plugin_dir_path( __FILE__ ) . '/PHP_XLSXWriter-master/xlsxwriter.class.php');

/* 
* enqueue styles
*/
add_action( 'wp_enqueue_scripts', 'pxe_enqueue_styles' );
function pxe_enqueue_styles() {
		wp_enqueue_style( 'style', plugins_url( '/style.css', __FILE__ ) );
}

/* plugin activation hooks */
register_activation_hook( __FILE__, 'pxe_activation' );

/* 
* initialize necessary plugin tables, and register CRON event
*/
function pxe_activation() {
	global $wpdb;
	global $pxe_db_version;

	$table_name = $wpdb->prefix . 'pxe_petitioners';
	$table_name_two = $wpdb->prefix . 'pxe_representatives';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		p_id tinyint(11) NOT NULL AUTO_INCREMENT,
		first_name varchar(255) NOT NULL,
		last_name varchar(255) NOT NULL,
		email varchar(255) NOT NULL,
		mp_district varchar(255) NOT NULL,
		mla_district varchar(255) NOT NULL,
		council_district varchar(255) NOT NULL,
		new_entry tinyint(1) DEFAULT 1 NOT NULL,
		message varchar(255) NOT NULL,
		postal varchar(255) NOT NULL,
		association varchar(255) NOT NULL,
		formatted_address varchar(255) NOT NULL,
		PRIMARY KEY  (p_id)
	) $charset_collate;";

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

	// add the cron event
	if (! wp_next_scheduled( 'pxe_weekly_event'  )) {
		wp_schedule_event( time(), 'weekly', 'pxe_weekly_event' );
	}
}

// add new time intervals
function pxe_cron_schedule($schedules){
    if(!isset($schedules["5min"])){
        $schedules["5min"] = array(
            'interval' => 5*60,
            'display' => __('Once every 5 minutes'));
    }
    if(!isset($schedules["weekly"])){
        $schedules["weekly"] = array(
            'interval' => 604800,
            'display' => __('Once every week'));
    }
    return $schedules;
}
add_filter('cron_schedules','pxe_cron_schedule');

// register plugin deactivation function
register_deactivation_hook( __FILE__, 'pxe_deactivation' );

// clear plugin CRON event on deactivation
function pxe_deactivation() {
	wp_clear_scheduled_hook( 'pxe_weekly_event' );
}

// WP ajax hooks attached to form submission
add_action( 'wp_ajax_nopriv_pxe_main_process_async', 'pxe_main_process' );
add_action( 'wp_ajax_pxe_main_process_async', 'pxe_main_process' );

// main script called by the AJAX submission of plugin form
function pxe_main_process() {
	// email errors to the main email account for exceptions
	$petitioner_data = array(
		'postal_code' => $_POST['postalCode'],
		'email' => $_POST['email'],
		'first_name' => $_POST['firstName'],
		'last_name' => $_POST['lastName'],
		'messages' => $_POST['messages'],
		'association' => $_POST['association']
		);

	// sanitize data
	$petitioner_data = pxe_validate_input( $petitioner_data );
	// postal code to lat/long coords
	$location = pxe_get_geo_coords( $petitioner_data['postal_code'] );
	if ( is_null( $location ) ) {
		pxe_show_client_error("No Response", "Unable to connect to Google maps service.");
		pxe_send_error( 'Unable to connect to Google maps service.', $petitioner_data );
		exit();
	} elseif ( !$location ) {
		pxe_show_client_error("Bad Response", "Unrecognized postal code. Please confirm it is correct. Some newer postal codes may not yet be registered. ");
		pxe_send_error( 'Unrecognized postal code', $petitioner_data );
		exit();
	}

	$petitioner_data['formatted_address'] = $location->formatted_address;

	// petitioner has edmonton postal code
	if ( !$petitioner_data['other'] ) {
		$rep_set = pxe_get_reps( $location->lat, $location->lng );
		if ( is_null( $rep_set ) ) {
			pxe_show_client_error("No Response", "Unable to connect to Represent service.");
			pxe_send_error( 'Unable to connect to Represent service.', $petitioner_data );
			exit();
		} elseif ( !$rep_set ) {
			pxe_show_client_error("Empty Response", "No representatives found for that postal code.");
			pxe_send_error( 'No representatives found for that postal code.', $petitioner_data );
			exit();
		}
		// add districts from rep data to petitioner data
		$petitioner_data = pxe_add_districts( $rep_set, $petitioner_data );
		// pass reps to client side for success message
		echo json_encode( array_values( $rep_set ) );
		// add data to tables
		pxe_insert_petitioner( $petitioner_data );
		foreach ($rep_set as $rep_data) {
			pxe_insert_representative( $rep_data );
		}
	} else {
		// outside of edmonton
		$rep_set = array(
			'other' => true
		);
		$petitioner_data['MLA'] = 'other';
		$petitioner_data['MP'] = 'other';
		$petitioner_data['Councillor'] = 'other';
		echo json_encode( $rep_set );
		pxe_insert_petitioner( $petitioner_data );
	}
	die();	
}

// add the cron process
add_action( 'pxe_weekly_event', 'pxe_cron_process' );

// the script executed by CRON (wp-cron)
// please forgive me, last minute fixed were made, 'other' conditions are workarounds
function pxe_cron_process() {
	global $wpdb;

	// proceed only if at least 1 new petitioner exists
	if ( pxe_new_exist() ) {
		$files_created = array();
		$districts_data = array(
			"mp" => array(),
			"mla" => array(),
			"council" => array(),
			"other" => array()
			);

		$results = $wpdb->get_results(
			"
			SELECT first_name, last_name, email, mp_district, mla_district, council_district, postal, message, association, new_entry, formatted_address 
			FROM {$wpdb->prefix}pxe_petitioners
			", ARRAY_A
		);
		// create each disctrict's structure and populate it
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
					// get the index for the correct $emails email address
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
					// TODO clean up/modularize these email calls
					// rep emails
					$file_name = $district_type . "-" . $district_name;
					$files_created[] = pxe_write_to_sheet( $all_writing_rows['writing_rows_new'], $all_writing_rows['writing_rows_old'], $file_name );

					if ( ($district_type !== 'other') && ($district_name !== 'other') ) {
						// $to = $emails[$email_index][0];
						$to = 'yegfootball@gmail.com';
						$subject = 'YEG Soccer Weekly Reminder';
						$body = '<p>This is a notice of the constituents in your area that are in support of YEG Soccer for the past week, we have attached a file/image which shows historical and new supporters of YEG Soccer in your area.' . $emails[$email_index][0] . '</p>';
						$body .= '<b>Sheet "Messages" column legend</b>';
						$body .= '<p>0: General support of the YEG Soccer cause</p>';
						$body .= '<p>1: I believe the local soccer clubs need to work together to collaborate in improve the state of soccer in Edmonton</p>';
						$body .= '<p>2: Include Soccer facilities in the City of Edmonton Recreation Facility Template</p>';
						$body .= '<p>3: Commission of a 10 year plan for soccer facilities in the City of Edmonton</p>';
						$body .= '<p>4: Support for FC Edmonton</p>';
						$body .= '<p>5:  I support Edmonton becoming a host city in Canadas World Cup 2026 bid</p>';
						$headers[] = 'Content-Type: text/html';
						$headers[] = 'charset=UTF-8';
						$mail_attachment = array( plugin_dir_path( __FILE__ ) . '/files/' . $file_name . '.xlsx');
						wp_mail( $to, $subject, $body, $headers, $mail_attachment );
					}
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
			// workaround to not send these 3 files that are being created
			if ( ($created_file !== 'mp-other.xlsx') && ($created_file !== 'mla-other.xlsx') && ($created_file !== 'council-other.xlsx') ) {
				$mail_attachments[] =  plugin_dir_path( __FILE__ ) . '/files/' . $created_file;
			}
		}
		wp_mail( $to, $subject, $body, $headers, $mail_attachments );
		pxe_update_petitioners();
		pxe_clean_up_files( $files_created );
	} else {
		// no new petitioners
		exit();
	}
}

/**
* check if any new petitioners 
* @return boolean
*/
function pxe_new_exist () {
	global $wpdb;
	$new_true = 1;

	$new_results = $wpdb->get_col( $wpdb->prepare(
		"
		SELECT {$wpdb->prefix}pxe_petitioners.new_entry 
		FROM {$wpdb->prefix}pxe_petitioners 
		WHERE {$wpdb->prefix}pxe_petitioners.new_entry = %d
		",
		$new_true	
	) );

	if ( $new_results ) {
		if ( count($new_results) > 0 ) {
			return true;
		}
	}
	return false;
}

/*
* Update the boolean new_entry column value to 0 (false) for all 1 (true) entries
*/
function pxe_update_petitioners () {
	global $wpdb;
	$old_bool = 0;

	$wpdb->query( $wpdb->prepare(
		"
		UPDATE {$wpdb->prefix}pxe_petitioners 
		SET {$wpdb->prefix}pxe_petitioners.new_entry = %d 
		WHERE {$wpdb->prefix}pxe_petitioners.new_entry = 1
		",
		$old_bool
	) );
}

/**
* @param filenames - array - list of files to delete, including the extension
* @return bool 
*/
function pxe_clean_up_files ( $filenames ) {
	try {
		foreach ($filenames as $filename) {
			unlink( plugin_dir_path( __FILE__ ) . '/files/' . $filename);
		}
	} catch (Exception $e) {
		// maybe have a support function, to email admin as notification of failure?
		// put it anywhere significant and pass along the exception information
		return false;
	}
	return true;
}

/**
* query to get rep info
* @return array of emails
*/
function pxe_get_rep_emails() {
	global $wpdb;
	$rep_emails = array();

	$results = $wpdb->get_results(
		"
		SELECT rep_name, email, district_name, elected_office, office_and_district 
		FROM {$wpdb->prefix}pxe_representatives
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

/**
* adds 2 arrays for old/new writing rows to an associative array having index of the district name, and adds that array to the array of the district type (eg. MLA)
* @param district_types - multidimensional array - initial structure of 3 associative arrays for types
* @param petitioner_data - associative array - a single petitioner's table data
* @return - multidimensional array
*/
function pxe_add_district_structure( $district_types, $petitioner_data ) {
	foreach ( $district_types as $dist_type => $district ) {
		if ( ($dist_type !== 'other') && ($petitioner_data['mp_district'] !== 'other') ) {
			// add the structure for a district if that district isn't already in the district_types array
			if ( !array_key_exists( $petitioner_data[$dist_type . '_district'], $district_types[$dist_type] ) ) {
				$district_types[$dist_type] = array_merge( $district_types[$dist_type], array( $petitioner_data[$dist_type . '_district'] => array(
				"writing_rows_new" => array(),
				"writing_rows_old" => array()
				) ) );
			}
		} else {
			if ( !array_key_exists( 'other', $district_types['other'] ) ) {
				$district_types['other'] = array_merge( $district_types['other'], array( 'other' => array(
				"writing_rows_new" => array(),
				"writing_rows_old" => array()
				) ) );
			}
		}
	}
	return $district_types;
}

/** 
* populates the old & new writing rows of a district
* @param district_types - multidimensional array
* @param petitioner - associative array
* @param rows_age - string - "old" or "new"
* @return multidimensional array - same structure higher up, but lowest level contains petitioner data
*/
// add the old and new writings rows to the containing object
function pxe_add_writing_rows ( $district_types, $petitioner, $rows_age ) {
	foreach ($district_types as $dist_type => $district) {
		if ( $dist_type !== 'other') {
		// push each "new" petitioner to the "writing_rows_new" for each of their district_types
		// TODO this is only going to be false if two people live in the same district and have the same name
		// if (!in_array_r($petitioner['p_name'], $district_types[$dist_type][$petitioner[$dist_type . '_district']])) {
			$district_types[$dist_type][$petitioner[$dist_type . '_district']][$rows_age][] = array(
				"first_name" => $petitioner['first_name'],
				"last_name" => $petitioner['last_name'],
				"email" => $petitioner['email'],
				"postal_code" => $petitioner['postal'],
				"mla_district" => $petitioner['mla_district'],
				"mp_district" => $petitioner['mp_district'],
				"council_district" => $petitioner['council_district'],
				"message" => $petitioner['message'],
				"association" => $petitioner['association'],
				"formatted_address" => $petitioner['formatted_address']
			);
		// }
		} elseif ( $petitioner['mp_district'] === 'other' ) {
			$district_types['other']['other'][$rows_age][] = array(
				"first_name" => $petitioner['first_name'],
				"last_name" => $petitioner['last_name'],
				"email" => $petitioner['email'],
				"postal_code" => $petitioner['postal'],
				"mla_district" => $petitioner['mla_district'],
				"mp_district" => $petitioner['mp_district'],
				"council_district" => $petitioner['council_district'],
				"message" => $petitioner['message'],
				"association" => $petitioner['association'],
				"formatted_address" => $petitioner['formatted_address']
			);
		}
	}
	return $district_types;
}

/**
* creates a sheet, writes the new entries first in bold, then the old ones - both must exist to get here, if no new it stops earlier
* @param - writing_rows_new - array
* @param - writing_rows_old - array
* @param - filename - string
* @return - string - name of the created file including the file extension (.xlxs)
*/
function pxe_write_to_sheet( $writing_rows_new, $writing_rows_old, $filename ) {
	$new_style = array( 'font'=>'Arial','font-size'=>10,'font-style'=>'bold', 'fill'=>'#eee', 'border'=>'left,right,top,bottom');
	$old_style = array( 'font'=>'Arial','font-size'=>10, 'fill'=>'#fff', 'border'=>'left,right,top,bottom');
	$header = array(
		'First Name' => 'string',
		'Last Name' => 'string',
		'Email' => 'string',
		'Postal Code' => 'string',
		'MLA District' => 'string',
		'MP District' => 'string',
		'Council District' => 'string',
		'Messages' => 'string',
		'Association' => 'string',
		'Address' => 'string'
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
		$writer->writeToFile( plugin_dir_path( __FILE__ ) . '/files/' . $filename . '.xlsx');
	} catch (Exception $e) {
		// TODO re-usable email function
		$to = 'yegfootball@gmail.com';
		$subject = 'YEG Soccer Petition';
		$body = "Error: " . $e->getMessage() ;
		$headers[] = 'Content-Type: text/html';
		$headers[] = 'charset=UTF-8';
		wp_mail( $to, $subject, $body, $headers );
	}

	return $filename . '.xlsx';
}

/**
* @param string - error type
* @param string - error message
*/
// TODO parameter for error code?
function pxe_show_client_error( $error_type, $error_msg ) {
	header("HTTP/1.0 434 " . $error_type);
	echo $error_msg;
}


/**
* sanitizes data, and returns it or exits with an error message if invalid data
* @param input_data - assoc array - petitioner's input data
* @return assoc array of validated and formatted data
*/
function pxe_validate_input ( $input_data ) {
	$edm_codes = ['T5A','T6A','T5B','T6B','T5C','T6C','T5E','T6E','T5G','T6G','T5H','T6H','T5J','T6J','T5K','T6K','T5L','T6L','T5M','T6M','T5N','T6N','T5P','T6P','T5R','T6R','T5S','T6S','T5T','T6T','T5V','T6V','T5W','T6W','T5X','T6X','T5Y','T5Z'];
	$input_data['first_name'] = trim( strip_tags($input_data['first_name']) );
	$input_data['last_name'] = trim( strip_tags($input_data['last_name']) );
	// TODO remove special chars?
	// check if empty name(s)
	if ( ($input_data['first_name'] === '') || ($input_data['last_name'] === '') ) {
		pxe_show_client_error('Input Error', 'Invalid Name');
		exit();
	}

	// check email format
	if ( !filter_var($input_data['email'], FILTER_VALIDATE_EMAIL) ) {
		pxe_show_client_error('Input Error', 'Invalid Email Address');
		exit();
	}

	// check if each msg is one of the accepted values
	foreach ($input_data['messages'] as $msg) {
		if ( 
			($msg !== '0') 
			&& ($msg !== '1') 
			&& ($msg !== '2') 
			&& ($msg !== '3') 
			&& ($msg !== '4') 
			&& ($msg !== '5') 
			&& ($msg !== '6') 
		) 
		{
			pxe_show_client_error('Input Error', 'Invalid Message Value');
			exit();
		}
	}

	// check if association is one of the accepted values
	if ( 
		($input_data['association'] !== 'unknown') 
		&& ($input_data['association'] !== 'parent') 
		&& ($input_data['association'] !== 'coach') 
		&& ($input_data['association'] !== 'player') 
		&& ($input_data['association'] !== 'other') 
	) 
	{
		pxe_show_client_error('Input Error', 'Invalid Association Value');
		exit();
	}

	// check postal code FORMAT ONLY - may still not yield proper results
	$input_data['postal_code'] = pxe_postal_filter( $input_data['postal_code'] );
	if ( !$input_data['postal_code'] ) {
		pxe_show_client_error('Input Error', 'Invalid Postal Code');
		exit();
	}
	// TODO handle this better
    foreach ($edm_codes as $code) {
        $reg = '#^' . $code . '(.*)$#i';
        // matches edmonton, stop
        if( preg_match($reg, $input_data['postal_code']) ) {
        	$input_data['other'] = false;
			return $input_data;
        }
    }
    // outside edmonton
    $input_data['other'] = true;
    return $input_data;
}

/**
* @param postalCode - string
* @return uppercased string with spaces removed, or false
*/
// TODO consistent return values
function pxe_postal_filter ($postalCode) {
	$postalCode = (string) $postalCode;
	$postalCode = trim($postalCode);
    $pattern = '/([ABCEGHJKLMNPRSTVXY]\d)([ABCEGHJKLMNPRSTVWXYZ]\d){2}/i';
    $space_pattern = '/\s/g';

    if ($postalCode === '') {
        return false;
    }
    // remove spaces
    $postalCode = preg_replace('/\s+/', '', $postalCode);
    if ( strlen($postalCode) > 6 ) {
    	return false;
    }

    if (preg_match($pattern, $postalCode)) {
		return strtoupper( $postalCode );    
    }
    return false;
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

/**
* @param - postal_code - string
* @return  - associative array of longitude and latitude
*/
function pxe_get_geo_coords ( $postal_code ) {
	$url = 'https://maps.googleapis.com/maps/api/geocode/json?components=postal_code:' . $postal_code;
	
	$request = wp_remote_get( esc_url_raw( $url ) , array( 'timeout' => 120) );
	// error getting response from resource
	if ( is_wp_error( $request ) ) {
		return null;
	}
	$body = wp_remote_retrieve_body( $request );
	$response = json_decode($body);
	if ( count( $response->results ) > 0 ) {
		$location = $response->results[0]->geometry->location;
		$location->formatted_address = $response->results[0]->formatted_address;
		return $location;
	}
	// unrecognized postal code
	return false;
}

/**
* @param lat float geographic latitude value
* @param long float geographic longitude value
* @return - bool or array of associative arrays
*/
function pxe_get_reps ( $lat, $long ) {
	$url = 'https://represent.opennorth.ca/representatives/?point=' . $lat . ',' . $long;
	$request = wp_remote_get( esc_url_raw( $url ) );
	// error getting response from resource
	if ( is_wp_error( $request ) ) {
		return null;
	}
	$response = json_decode( wp_remote_retrieve_body( $request ), true );
	// grab only the rep objects from response
	$response = $response['objects'];
	if ( count( $response > 0 )) {
		// filter results
		// TODO make this alterable by the plugin admin, also add a plugin admin
		return array_filter( $response, function( $v ) {
			if ( $v['elected_office'] !== "Mayor") {
				return $v;
			}
		});
	}
	// no results returned
	return false;
}

/**
* @param rep_set - array of associative arrays
* @param petitioner_data -  associative array
* @return - associative array
*/
function pxe_add_districts ( $rep_set, $petitioner_data) {
	foreach ( $rep_set as $rep_data ) {
		$petitioner_data[$rep_data['elected_office']] = $rep_data['district_name'];
	}

	return $petitioner_data;
}

/**
* Adds petitioner data to table
* @param petitioner_data - associative array
*/
function pxe_insert_petitioner ( $petitioner_data ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'pxe_petitioners';
	if ( count( $petitioner_data['messages'] ) > 0 ) {
		$comma_separated = implode(",", $petitioner_data['messages']);
	} else {
		$comma_separated = "0";
	}
	
	$wpdb->insert( 
		$table_name, 
		array( 
			'first_name' => $petitioner_data['first_name'], 
			'last_name' => $petitioner_data['last_name'], 
			'email' => $petitioner_data['email'], 
			'mp_district' => $petitioner_data['MP'], 
			'mla_district' => $petitioner_data['MLA'], 
			'council_district' => $petitioner_data['Councillor'], 
			'message' => $comma_separated, 
			'postal' => $petitioner_data['postal_code'],
			'association' => $petitioner_data['association'],
			'formatted_address' => $petitioner_data['formatted_address']
		) 
	);
}

/**
* Inserts if new, replaces if already exists & different
* @param rep_data - associative array
*/
function pxe_insert_representative ( $rep_data ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'pxe_representatives';

	$result = $wpdb->get_results(
		"
		SELECT rep_name, office_and_district 
		FROM {$wpdb->prefix}pxe_representatives 
		WHERE office_and_district = '" . $rep_data['elected_office'] . '-' . $rep_data['district_name'] . "'
		", ARRAY_A
	);
	$num_rows = $wpdb->num_rows;

	if ($num_rows === 0) {
		// doesn't exist
		$wpdb->insert( 
			$table_name, 
			array( 
				'office_and_district' => $rep_data['elected_office'] . '-' . $rep_data['district_name'],
				'rep_name' => $rep_data['name'],
				'district_name' => $rep_data['district_name'],
				'elected_office' => $rep_data['elected_office'],
				'email' => $rep_data['email']
			) 
		);
	} else {
		// exists
		foreach ($result as $rep_row) {
			// check if it's the same as what's being passed to this function
			if ($rep_row['rep_name'] !== $rep_data['name']) {
				$wpdb->update( 
					$table_name, 
					array( 
						'rep_name' => $rep_data['name'],
						'email' => $rep_data['email']
					), 
					array( 'office_and_district' => $rep_row['office_and_district'] ), 
					array( 
						'%s',
						'%s'
					)
				);
			}
		}
	}
}

function pxe_send_error( $error_body, $user_data ) {
	$to = 'yegfootball@gmail.com';
	$subject = 'Form Error';
	$body = "<b>$error_body</b>
		<p>First Name: " . $user_data['first_name'] . "</p>
		<p>Last Name: " . $user_data['last_name'] . "</p>
		<p>Postal Code: " . $user_data['postal_code'] . "</p>
		<p>Email: " . $user_data['email'] . "</p>
		<p>Messages: " ;
	foreach ($user_data['messages'] as $msg) {
		$body .= "$msg ";
	}
	$body .= "</p>";
	$headers[] = 'Content-Type: text/html';
	$headers[] = 'charset=UTF-8';
	wp_mail( $to, $subject, $body, $headers );
}


// shortcode for user input form
add_shortcode('show_pxe_form', 'pxe_create_form');
/*
* shortcode for the plugin's form, enqueues required script where this appears 
*/
function pxe_create_form(){
	// enqueue script where the shortcode form appears
	wp_enqueue_script( 'main', plugins_url( '/main.js', __FILE__ ), array('jquery'), '1.0', true );

	// form checkbox and display values
	$checkbox_data = array(
		array(
			'label' => 'Minor soccer in Edmonton',
			'content' => 'With 44% of all children playing soccer, it is the greatest and earliest opportunity to teach kids about the benefits of sport, it is important that we provide them safe, quality, accessible and affordable places to pla'
		),
		array(
			'label' => 'City of Edmonton including indoor soccer fields in their Recreational Facility',
			'content' => 'As it stands today soccer is implicitly not allowed in City of Edmonton recreational facility gyms.  We believe the new template for recreational facilities needs to have provisions for citizens to practice and play the overwhelmingly popular sport of Soccer.'
		),
		array(
			'label' => 'FC Edmonton',
			'content' => 'Edmonton has a successful Professional Soccer Club called FC Edmonton founded in 2010, by Tom and Dave Fath. They have coordinated important events for our city including a memorial for Constable Daniel Woodall (a big soccer fan).  Their logo was designed with Edmonton colors in mind, and play in an Edmonton Eskimos branded facility.  We believe they need more support to be as successful as they should be in our sports crazy city.'
		),
		array(
			'label' => 'The commission of a 10 year plan for soccer facilities in the City of Edmonton',
			'content' => 'We are already extremely behind in capacity for soccer facilities and we need to catch up in order to meet demand.  We also need to ensure that the success of facilities are not based on capacity alone, the facilities need to be accessible, affordable and of a high quality.  To achieve this ambitious but necessary result we need a comprehensive, well thought out and community engaged plan that will allow us to address issues such as boarded vs non.'
		),
		array(
			'label' => 'Collaboration with local soccer clubs to plan, build and maintain indoor soccer facilities',
			'content' => 'I support collaboration with local Soccer clubs to meet the needs of the sport in Edmonton. There are several local clubs that are looking to develop indoor facilities for their teams because of the significant lack of indoor facilities in the Edmonton area.  They need your support.  There are a number of open minded leagues, clubs, facility operators that are willing to coordinate to serve the greater community in collaboration with government entities to make things happen in our wonderful winter city.'
		),
		array(
			'label' => 'The formation of a Canadian Premier League',
			'content' => 'Rumor has it that a NHL/CFL owner backed Premier League will be launching in Canada as soon as 2018, founded, staffed by and for the benefit of Canadian players, coaches, and administrators. This is important to the development of our youth and providing full time soccer jobs to Canadians in Canada.'
		)
	);		

?>
<form class="rep-petition-form">
	<input type="hidden" value="<?php echo site_url(); ?>" id="siteUrl">
	<div class="load-container"></div>
	<div class="form-half first-half">
		<div id="petition-error-div"></div>
		<div class="form-group">
			<label for="first_name">First Name*</label>
			<input class="form-control" type="text" id="first_name" name="first_name" autocomplete="off" placeholder="Your first name" required>
		</div>
		<div class="form-group">
			<label for="last_name">Last Name*</label>
			<input class="form-control" type="text" id="last_name" name="last_name" autocomplete="off" placeholder="Your last name" required>
		</div>
		<div class="form-group">
			<label for="user_email">Email*</label>
			<input class="form-control" type="email" id="user_email" name="user_email" autocomplete="off" placeholder="address@example.com" required>
		</div>
		<div class="form-group">
			<label for="postal_code">Postal Code*</label>
			<input class="form-control" type="text" id="postal_code" name="postal_code" autocomplete="off" placeholder="Your postal code" required>
		</div>
		<div class="form-group">
			<label for="association">Association</label>
			<select name="association" id="association">
				<option value="unknown" default>-- Choose --</option>
				<option value="parent">Parent</option>
				<option value="coach">Coach</option>
				<option value="player">Player</option>
				<option value="other">Other</option>
			</select>
		</div>
		<div class="form-group">
			<input type="submit" value="Submit" class="btn btn-warning btn-block">
		</div>
		<p class="petition-disclaimer">
			The information you are submitting will only be used for purposes of advocating for soccer in Edmonton
		</p>
	</div>
	<div class="form-half second-half">
		<h4>I Support...</h4>
	<?php
		foreach ($checkbox_data as $i => $data) {
			echo "<div class='form-group'>";
			echo "<input checked='true' type='checkbox' id='template_msg_" . ($i + 1) . "' value='" . ($i + 1) . "' data-msg='" . $data['content'] . "'>";
			echo "<label class='inline-label' for='template_msg_" . ($i + 1) . "'>" . $data['label'] . "</label>";
			echo "</div>"; 
		}
	 ?>
		<div class="petition-message-display">
			<p>Dear representative, I am a supporter of soccer and of YEG Soccer, I believe that the City, Province and Federal government need to do more to support the Worlds Beautiful Game.  There are inherent benefits to soccer for our society including health, public safety, leadership, and gender equality – and the good news is that 44% of all Canadian children are already big fans!  Help us use soccer as positive influence, it is already there, it is already popular we just need your support to use its already far reach to benefit our community even further.</p>
		</div>
	</div>
</form>
<div id="rep-info-display" class="rep-petition-form">
</div>
<?php
}