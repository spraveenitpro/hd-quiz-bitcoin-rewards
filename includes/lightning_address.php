<?php
/**
 * Lightning Address Add-Ons:
 * Get the Lightning Address from the user and store it in the session.
 * Use the Lightning Address to send the reward.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue the stylesheet
function hdq_enqueue_lightning_style() {
    wp_enqueue_style(
        'hdq_admin_style',
        plugin_dir_url(__FILE__) . 'css/hdq_a_light_style.css',
        array(),
        HDQ_A_LIGHT_PLUGIN_VERSION
    );
}
add_action('wp_enqueue_scripts', 'hdq_enqueue_lightning_style');

// Enqueue the JavaScript file
function hdq_enqueue_lightning_script() {
    global $post; // Ensure you have access to the global post object
    $quiz_id = $post->ID; // This assumes that you are on a single quiz post. Adjust if necessary.
    
    // Get the Satoshi value for the current quiz
    $sats_field = "sats_per_answer_for_" . $quiz_id;
    $sats_value = get_option($sats_field, 0); // Default to 0 if not set

    // Get the BTCPay Server URL and API Key
    $btcpay_url = get_option('hdq_btcpay_url', '');
    $btcpay_api_key = get_option('hdq_btcpay_api_key', '');

    $script_path = plugin_dir_url(__FILE__) . 'js/hdq_a_light_script.js';
    wp_enqueue_script('hdq-lightning-script', $script_path, array('jquery'), '1.0.0', true);

    // Localize the script with your data including the sats value and the BTCPay Server URL and API Key
    wp_localize_script('hdq-lightning-script', 'hdq_data', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'satsPerAnswer' => $sats_value,
        'btcpayUrl' => $btcpay_url,
        'btcpayApiKey' => $btcpay_api_key,
    ));
}
add_action('wp_enqueue_scripts', 'hdq_enqueue_lightning_script');

// Fetch the total satoshis sent for the current quiz
function get_total_sent_for_quiz($quiz_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitcoin_quiz_results';

    // Fetch the quiz name using the quiz ID
    $quiz_term = get_term_by('id', $quiz_id, 'quiz');
    if (!$quiz_term) {
        error_log("Quiz term not found for ID: $quiz_id");
        return 0; // Return 0 if the quiz is not found
    }
    $quiz_name = $quiz_term->name;

    // Fetch total satoshis sent for the specific quiz
    $total_sent = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(satoshis_sent) FROM $table_name WHERE quiz_name = %s",
        $quiz_name
    ));
    return $total_sent;
}


function should_enable_rewards($quiz_id, $lightning_address) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitcoin_quiz_results';

    // Check if rewards are enabled for this quiz
    $rewards_enabled = get_option("enable_bitcoin_reward_for_" . $quiz_id) === 'yes';
    
    // Fetch quiz name using the term associated with the quiz ID
    $quiz_term = get_term_by('id', $quiz_id, 'quiz');
    if (!$quiz_term) {
        error_log("Quiz term not found for quiz ID $quiz_id");
        return false; // Return false if the quiz is not found
    }
    $quiz_name = $quiz_term->name;

    // Check if the quiz is over budget
    $max_budget = get_option("max_satoshi_budget_for_" . $quiz_id);
    $total_sent = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(satoshis_sent) FROM $table_name WHERE quiz_name = %s",
        $quiz_name
    ));
    $over_budget = ($total_sent >= $max_budget);
    error_log("Max budget for quiz ID $quiz_id: $max_budget");
    error_log("Total sent for quiz ID $quiz_id: $total_sent");
    error_log("Quiz ID $quiz_id over budget: " . ($over_budget ? 'Yes' : 'No'));

    return $rewards_enabled && !$over_budget;
}



/**
 * Check if rewards are enabled. If so, display a user input form to collect the Lightning Address at the start of the quiz.
 */
function la_input_lightning_address_on_quiz_start($quiz_id) {
    // Here we check if we should enable rewards for this quiz
    // For the second parameter, we can pass an empty string or a default value as the user has not entered an address yet
    if (should_enable_rewards($quiz_id, '')) {
        echo '<div class="hdq_row">';
        echo '<label for="lightning_address" class="hdq_input">Enter your Lightning Address: </label>';
        echo '<input type="text" id="lightning_address" name="lightning_address" class="hdq_lightning_input" placeholder="bolt@lightning.com">';
        echo '<input type="submit" class="hdq_button" id="hdq_save_settings" value="SAVE" style="margin-left:10px;" onclick="validateLightningAddress(event);">';
        echo '</div>';
    } else {
        // If rewards should not be enabled, display a message or hide the form
        echo '<div class="hdq_row">Rewards are not currently available for this quiz.  You can still take the quiz if you want though ; )</div>';
    }
}

add_action('hdq_before', 'la_input_lightning_address_on_quiz_start', 10, 1);

// Function to count the attempts a user's lightning address has made for a specific quiz
function count_attempts_by_lightning_address($lightning_address, $quiz_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitcoin_quiz_results';

    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE lightning_address = %s AND quiz_id = %d",
        $lightning_address, 
        $quiz_id
    ));

    error_log("Retrieved count for $lightning_address, Quiz ID $quiz_id: $count");

    $max_retries = get_option("max_retries_for_" . $quiz_id, 0);
    $max_retries_exceeded = intval($count) >= $max_retries;

    return ['count' => intval($count), 'max_retries_exceeded' => $max_retries_exceeded];
}


function store_lightning_address_in_session() {
    if (isset($_POST['address']) && isset($_POST['quiz_id'])) {
        $lightning_address = sanitize_text_field($_POST['address']);
        $quiz_id = intval($_POST['quiz_id']); // Fetch quiz_id from the POST data
        error_log("POST Data: " . print_r($_POST, true));

        $max_retries = get_option("max_retries_for_" . $quiz_id, 0);
        $attempt_data = count_attempts_by_lightning_address($lightning_address, $quiz_id);
        $attempts = $attempt_data['count']; // Access the count of attempts
        $max_retries_exceeded = $attempt_data['max_retries_exceeded'];

        error_log("Max retries: $max_retries");
        error_log("Attempts: $attempts");
        $_SESSION['max_retries_exceeded'] = $max_retries_exceeded;

        if (!$max_retries_exceeded) {
            $_SESSION['lightning_address'] = $lightning_address;
            echo 'Address stored successfully.';
        } else {
            echo 'Maximum attempts reached for this Lightning Address. You can still take the quiz, but you won\'t get sats ; )';
        }
    } else {
        echo 'No address or quiz ID provided.';
    }
    wp_die();
}

add_action('wp_ajax_store_lightning_address', 'store_lightning_address_in_session');        // If the user is logged in
add_action('wp_ajax_nopriv_store_lightning_address', 'store_lightning_address_in_session'); // If the user is not logged in

function hdq_pay_bolt11_invoice() {
    global $wpdb;

    error_log("POST Data: " . print_r($_POST, true)); // Debug log to check all POST data
    // Retrieve quiz_id from POST data
    $quiz_id = isset($_POST['quiz_id']) ? intval($_POST['quiz_id']) : 0;
    error_log("Quiz ID from post data: " . $quiz_id);


    $lightning_address = isset($_POST['lightning_address']) ? sanitize_text_field($_POST['lightning_address']) : '';
    $quiz_id = isset($_POST['quiz_id']) ? intval($_POST['quiz_id']) : 0;

    // Get attempt count and check if maximum retries have been exceeded
    $attempt_data = count_attempts_by_lightning_address($lightning_address, $quiz_id);
    error_log("Attempt data: " . print_r($attempt_data, true));
    if ($attempt_data['max_retries_exceeded']) {
        echo json_encode(['error' => 'Maximum attempts reached for this Lightning Address.']);
        wp_die();
    }

    $lightning_address = isset($_POST['lightning_address']) ? sanitize_text_field($_POST['lightning_address']) : '';
    $quiz_id = isset($_POST['quiz_id']) ? intval($_POST['quiz_id']) : 0;
    $btcpayServerUrl = get_option('hdq_btcpay_url', '');
    $apiKey = get_option('hdq_btcpay_api_key', '');
    $storeId = get_option('hdq_btcpay_store_id', '');
    $cryptoCode = "BTC"; // Hardcoded as BTC
    $bolt11 = isset($_POST['bolt11']) ? sanitize_text_field($_POST['bolt11']) : '';

    // Remove any trailing slashes
    $btcpayServerUrl = rtrim($btcpayServerUrl, '/');

    // Construct the correct URL
    $url = $btcpayServerUrl . "/api/v1/stores/" . $storeId . "/lightning/" . $cryptoCode . "/invoices/pay";
    $body = json_encode(['BOLT11' => $bolt11]);

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'token ' . $apiKey,
        ],
        'body' => $body,
        'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
        echo json_encode(['error' => 'Payment request failed', 'details' => $response->get_error_message()]);
    } else {
        echo wp_remote_retrieve_body($response);
    }

    wp_die();
}

// Register the new AJAX action
add_action('wp_ajax_pay_bolt11_invoice', 'hdq_pay_bolt11_invoice');        // If the user is logged in
add_action('wp_ajax_nopriv_pay_bolt11_invoice', 'hdq_pay_bolt11_invoice'); // If the user is not logged in

function hdq_save_quiz_results() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitcoin_quiz_results';

    // Get current user information
    $current_user = wp_get_current_user();

    // Collect data from the AJAX request
    $user_id = is_user_logged_in() ? $current_user->user_login : '0';
    $lightning_address = isset($_POST['lightning_address']) ? sanitize_text_field($_POST['lightning_address']) : '';
    $quiz_result = isset($_POST['quiz_result']) ? sanitize_text_field($_POST['quiz_result']) : '';
    $satoshis_earned = isset($_POST['satoshis_earned']) ? intval($_POST['satoshis_earned']) : 0;
    $quiz_id = isset($_POST['quiz_id']) ? sanitize_text_field($_POST['quiz_id']) : '';

    // Fetch quiz name using the term associated with the quiz ID
    $quiz_term = get_term_by('id', $quiz_id, 'quiz');
    $quiz_name = $quiz_term ? $quiz_term->name : 'Unknown Quiz';

    $send_success = isset($_POST['send_success']) ? intval($_POST['send_success']) : 0;
    $satoshis_sent = isset($_POST['satoshis_sent']) ? intval($_POST['satoshis_sent']) : 0;

    // Insert data into the database
    $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'lightning_address' => $lightning_address,
            'quiz_result' => $quiz_result,
            'satoshis_earned' => $satoshis_earned,
            'quiz_name' => $quiz_name,
            'send_success' => $send_success,
            'satoshis_sent' => $satoshis_sent,
            'quiz_id' => $quiz_id // Include quiz_id in the array
        ),
        array('%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d') // Update the format string accordingly
    );

    // Send a response back to the AJAX request
    echo json_encode(array('success' => true));
    wp_die();
}

add_action('wp_ajax_hdq_save_quiz_results', 'hdq_save_quiz_results');
add_action('wp_ajax_nopriv_hdq_save_quiz_results', 'hdq_save_quiz_results');
