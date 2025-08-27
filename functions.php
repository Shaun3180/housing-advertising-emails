// SMG - 8/25/2025 fucking annoying we have to do it this way (to send e-mails to different recipients depending on product ordered)
// Look at the function get_product_email_config to determine which products are associated with which emails
// Hook into WooCommerce booking and order events
add_action('woocommerce_booking_confirmed', 'send_custom_booking_notification', 20, 1);
add_action('woocommerce_new_booking', 'send_custom_booking_notification', 30, 1);
add_action('woocommerce_checkout_order_processed', 'debug_order_processed', 10, 1);
add_action('woocommerce_thankyou', 'handle_booking_notification_on_thankyou', 10, 1);
add_action('retry_custom_booking_notification', 'retry_custom_booking_notification_handler');

/**
 * Debug log when orders are processed and attempt to send booking notifications immediately.
 */
function debug_order_processed($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        $booking_ids = get_booking_ids_from_order($order);
        foreach ($booking_ids as $booking_id) {
            send_custom_booking_notification($booking_id);
        }
    }
}

/**
 * Handle notifications from the thank you page (order should be fully completed here).
 */
function handle_booking_notification_on_thankyou($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        $booking_ids = get_booking_ids_from_order($order);
        foreach ($booking_ids as $booking_id) {
            send_custom_booking_notification($booking_id);
        }
    }
}

/**
 * Retrieve booking IDs from an order.
 */
function get_booking_ids_from_order($order) {
    $booking_ids = [];

    foreach ($order->get_items() as $item_id => $item) {
        if ($item->get_type() === 'line_item') {
            $ids = WC_Booking_Data_Store::get_booking_ids_from_order_item_id($item_id);
            if (!empty($ids)) {
                $booking_ids = array_merge($booking_ids, $ids);
            }
        }
    }

    return $booking_ids;
}

/**
 * Send a custom booking notification email if conditions are met.
 */
function send_custom_booking_notification($booking_id) {
    $booking = get_wc_booking($booking_id);
    if (!$booking) {
        return;
    }

    $processed_key = '_custom_email_sent';
    if ($booking->get_meta($processed_key)) {
        return;
    }

    // Ensure order is ready; if not, retry later
    $booking_order = $booking->get_order() ?: wc_get_order($booking->get_order_id());
    if (!$booking_order) {
        wp_schedule_single_event(time() + 30, 'retry_custom_booking_notification', [$booking_id]);
        return;
    }

    // Determine recipient email
    $to_email = get_booking_notification_recipient($booking->get_product_id());
    if (!$to_email) {
        return; // Let WooCommerce handle normally
    }

    // Prepare and send email
    $sent = send_booking_email($booking, $booking_order, $to_email);

    if ($sent) {
        $booking->update_meta_data($processed_key, time());
        $booking->save();
    }
}

/**
 * Get the email configuration for a given product ID.
 */
function get_product_email_config($product_id) {
    $product_email_map = [
        'digital_signs' => [
            'products' => [661, 233, 22],
            'sender' => 'brenton.goodman@colostate.edu',
            'sendername' => 'Brenton Goodman',
            'recipient' => 'shaun.geisert@colostate.edu'
        ],
        'table_cards' => [
            'products' => [14],
            'sender' => 'rds@colostate.edu',
            'sendername' => 'Residential Dining Services',
            'recipient' => 'rds@colostate.edu'
        ]
    ];

    foreach ($product_email_map as $config) {
        if (in_array($product_id, $config['products'], true)) {
            return $config;
        }
    }

    return null;
}

/**
 * Determine the recipient email for a given product ID.
 */
function get_booking_notification_recipient($product_id) {
    $config = get_product_email_config($product_id);
    return $config ? $config['recipient'] : '';
}

/**
 * Determine sender email based on product ID.
 */
function get_booking_sender_email($product_id) {
    $config = get_product_email_config($product_id);
    return $config ? $config['sender'] : get_option('admin_email');
}

/**
 * Determine sender name based on product ID.
 */
function get_booking_sender_name($product_id) {
    $config = get_product_email_config($product_id);
    return $config ? $config['sendername'] : get_bloginfo('name');
}

/**
 * Use WooCommerce email templates to send the booking email.
 */
function send_booking_email($booking, $booking_order, $to_email) {
    $product = wc_get_product($booking->get_product_id());
    $product_name = $product ? $product->get_name() : 'Unknown Product';
    $product_id = $booking->get_product_id();

    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();

    if (!isset($emails['WC_Email_New_Booking'])) {
        return false;
    }

    $email_obj = $emails['WC_Email_New_Booking'];
    $original_recipient = $email_obj->recipient;
    $email_obj->recipient = $to_email;
    $email_obj->object = $booking;

    $subject = $email_obj->get_subject() ?: "New Booking: {$product_name} (#{$booking->get_id()})";

    // Replace placeholders with actual values
    $subject = str_replace(
        ['{product_title}', '{order_number}', '{order_date}'],
        [$product_name, $booking->get_order_id(), date('Y-m-d')],
        $subject
    );

    // Load custom template if available
    $template_path = get_stylesheet_directory() . '/woocommerce-bookings/emails/admin-new-booking.php';
    $email_content = file_exists($template_path) ? capture_custom_template($template_path, $booking, $booking_order, $email_obj) : $email_obj->get_content_html();

    // Get the correct sender email and name based on product ID
    $from_email = get_booking_sender_email($product_id);
    $from_name = get_booking_sender_name($product_id);

    // Format headers correctly for your wp_mail override
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From' => $from_name . ' <' . $from_email . '>'
    ];

    $sent = wp_mail($to_email, $subject, $email_content, $headers);

    $email_obj->recipient = $original_recipient;

    return $sent;
}

/**
 * Capture custom email template output.
 */
function capture_custom_template($template_path, $booking, $booking_order, $email_obj) {
    $order = $booking_order;
    $sent_to_admin = true;
    $plain_text = false;
    $email = $email_obj;
    $email_heading = $email_obj->get_heading();

    ob_start();
    include $template_path;
    return ob_get_clean();
}

/**
 * Retry handler if booking notification initially failed due to missing order.
 */
function retry_custom_booking_notification_handler($booking_id) {
    $booking = get_wc_booking($booking_id);
    if (!$booking) {
        return;
    }

    if ($booking->get_meta('_custom_email_sent')) {
        return;
    }

    if ($booking->get_order()) {
        send_custom_booking_notification($booking_id);
    }
}
