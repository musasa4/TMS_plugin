<?php
/*
 * Plugin Name: TMS Plugin
 * Description: Handle all send and receive SMS on the site 
 * Version: 1.0
 * Author: Musa Zulu
 * Author URI: http://www.tic-it.co.za
 * License: TMS02
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Use WordPress's built-in wpdb class for database connection
global $wpdb;

// Function to send SMS via Kannel with a retry mechanism
function send_sms_via_kannel($to, $message, $retry_count = 3) {
    $smsc_ip = "154.0.172.246";
    $smsc_port = "13013";
    $system_id = "playsms";
    $passwd = "playsms";

    // URL-encode the message, keeping URLs intact
    $url_pattern = '/(https?:\/\/[^\s]+)/';
    $encoded_message = preg_replace_callback($url_pattern, function ($matches) {
        return rawurlencode($matches[0]); // Encode only the URL part
    }, $message);

    // Prepare URL
    $url = "http://$smsc_ip:$smsc_port/cgi-bin/sendsms";
    $params = [
        'username' => sanitize_text_field($system_id),
        'password' => sanitize_text_field($passwd),
        'to' => sanitize_text_field($to),
        'text' => $encoded_message,
        'smsc' => 'your_smsc_id' // Add this if necessary
    ];
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986); // Ensure proper encoding
    $url .= '?' . $query;

    $attempts = 0;
    $response = null;

    while ($attempts < $retry_count) {
        $response = wp_remote_get($url, ['timeout' => 3]); // Set timeout to 15 seconds
        if (!is_wp_error($response)) {
            break; // Exit the loop if the response is successful
        }
        $attempts++;
    }

    if (is_wp_error($response)) {
        error_log('SMS Error: ' . $response->get_error_message()); // Log the error
        return 'Error: ' . $response->get_error_message();
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($response_code != 200) {
        return 'Error: HTTP Status Code ' . $response_code . ' - ' . $body;
    }

    return $body;
}

// Function to process new shipments
function process_new_shipments() {
    global $wpdb;

    // Fetch new shipment records from the log table
    $sql = "SELECT shipment_id FROM {$wpdb->prefix}shipment_log WHERE processed = 0";
    $results = $wpdb->get_results($sql);

    if (!$results) {
        error_log("Error fetching shipment records: " . $wpdb->last_error);
        return;
    }

    foreach ($results as $row) {
        $shipment_id = (int) $row->shipment_id;

        // Fetch the phone number, client name, and reference number from wp_postmeta
        $phone_number = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
            '%wpcargo_shipper_phone%', $shipment_id
        ));

        $client_name = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
            '%wpcargo_shipper_name%', $shipment_id
        ));

        $ref_number = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
            '%wpcargo_carrier_ref_number%', $shipment_id
        ));

        if ($phone_number && $client_name && $ref_number) {
            // Construct the message
            $message = sprintf("Dear %s, your parcel is on its way! Thanks for choosing Rahasana Transport Services. Your Tracking ID: %s.", sanitize_text_field($client_name), sanitize_text_field($ref_number));

            // Send SMS
            $response = send_sms_via_kannel($phone_number, $message);
			
			// Mark the log record as processed
                $update_record2 = "UPDATE wpfu_shipment_log SET processed = 1 WHERE shipment_id = $shipment_id";
                $wpdb->query($update_record2);

            if (strpos($response, 'Error') === false) {
                // Mark the log record as processed
                $update_sql = $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}shipment_log SET processed = 1 WHERE shipment_id = %d",
                    $shipment_id
                );

                if ($wpdb->query($update_sql) !== false) {
                    error_log("Successfully updated shipment log for shipment_id $shipment_id.");
                } else {
                    error_log("Error updating shipment log for shipment_id $shipment_id: " . $wpdb->last_error);
                }
            } else {
                error_log("Failed to send SMS to $phone_number: $response");
            }
        } else {
            error_log("Missing meta data for shipment_id $shipment_id.");
        }
    }
}


// Function to process the status updates on shipments wpfu_shipment_update
function process_status_update_shipments() {
    global $wpdb;

    // Fetch new shipment records from the log table
    $sql = "SELECT shipment_id FROM {$wpdb->prefix}shipment_update WHERE processed = 0";
    $results = $wpdb->get_results($sql);

    if (!$results) {
        error_log("Error fetching shipment records: " . $wpdb->last_error);
        return;
    }

    foreach ($results as $row) {
        $shipment_id = (int) $row->shipment_id;

        // Fetch the phone number, client name, and reference number from wp_postmeta
        $phone_number = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
            '%wpcargo_shipper_phone%', $shipment_id
        ));

        $client_name = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
            '%wpcargo_shipper_name%', $shipment_id
        ));

        $ref_number = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
            '%wpcargo_carrier_ref_number%', $shipment_id
        ));

        if ($phone_number && $client_name && $ref_number) {
            // Construct the message
            $message = sprintf("Dear %s, your parcel has arrived! Please collect it at our office or arrange delivery. Thanks for choosing Rahasana Transport Services. Tracking ID: %s.", sanitize_text_field($client_name), sanitize_text_field($ref_number));
			

            // Send SMS
            $response = send_sms_via_kannel($phone_number, $message);
			
			// Mark the log record as processed
                $update_record3 = "UPDATE wpfu_shipment_update SET processed = 1 WHERE shipment_id = $shipment_id";
                $wpdb->query($update_record3);

            if (strpos($response, 'Error') === false) {
                // Mark the log record as processed
                $update_sql = $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}shipment_update SET processed = 1 WHERE shipment_id = %d",
                    $shipment_id
                );

                if ($wpdb->query($update_sql) !== false) {
                    error_log("Successfully updated shipment log for shipment_id $shipment_id.");
                } else {
                    error_log("Error updating shipment log for shipment_id $shipment_id: " . $wpdb->last_error);
                }
            } else {
                error_log("Failed to send SMS to $phone_number: $response");
            }
        } else {
            error_log("Missing meta data for shipment_id $shipment_id.");
        }
    }
}

// Schedule the shipment processing
add_action('init', function () {
    if (defined('DOING_CRON') && DOING_CRON) {
        return; // Prevent execution during WP-Cron jobs
    }

    // Process new shipments
    process_new_shipments();
	// Process new shipments
    process_status_update_shipments();
});
