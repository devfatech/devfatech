<?php
/**
 * Plugin Name: TSNC Calculator Email Handler
 * Description: Handles form submissions from the TSNC Instant Estimate Calculator and emails leads.
 * Version: 4.1
 * Author: FA Technologies
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register AJAX handlers (logged-in + not-logged-in) ────────────────────────
add_action( 'wp_ajax_tsnc_lead',             'tsnc_handle_lead' );
add_action( 'wp_ajax_nopriv_tsnc_lead',      'tsnc_handle_lead' );
add_action( 'wp_ajax_tsnc_send_code',        'tsnc_handle_send_code' );
add_action( 'wp_ajax_nopriv_tsnc_send_code', 'tsnc_handle_send_code' );
add_action( 'wp_ajax_tsnc_verify_code',      'tsnc_handle_verify_code' );
add_action( 'wp_ajax_nopriv_tsnc_verify_code','tsnc_handle_verify_code' );

// ──────────────────────────────────────────────────────────────────────────────
// EMAIL VERIFICATION — send a 4-digit code, verify it
// ──────────────────────────────────────────────────────────────────────────────

function tsnc_handle_send_code() {
    if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( sanitize_text_field($_POST['nonce']), 'tsnc_lead_nonce' ) ) {
        wp_send_json_error( ['msg' => 'Security check failed.'], 403 );
    }

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $name  = isset($_POST['name'])  ? sanitize_text_field($_POST['name'])  : '';

    if ( ! is_email($email) ) {
        wp_send_json_error( ['msg' => 'Invalid email.'], 400 );
    }

    // Generate a 4-digit code and store it in a transient (5-minute TTL)
    $code    = str_pad( wp_rand(0, 9999), 4, '0', STR_PAD_LEFT );
    $key     = 'tsnc_code_' . md5( strtolower($email) );
    set_transient( $key, $code, 5 * MINUTE_IN_SECONDS );

    $greeting = $name ? "Hi {$name}," : "Hi there,";

    $body = "
    <!DOCTYPE html>
    <html><head><meta charset=\"UTF-8\"></head><body>
    <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f5faf3;padding:24px;\">
      <div style=\"max-width:480px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #DAE8D6;\">
        <div style=\"background:#2C6B22;padding:16px 22px;\">
          <p style=\"color:#ffffff;font-size:16px;font-weight:700;margin:0;\">Toronto Steam N Clean</p>
          <p style=\"color:#CDF2BD;font-size:13px;margin:4px 0 0;\">Your email verification code</p>
        </div>
        <div style=\"padding:28px 24px;text-align:center;\">
          <p style=\"font-size:15px;color:#22301F;margin:0 0 20px;\">{$greeting}</p>
          <p style=\"font-size:14px;color:#3C5847;margin:0 0 24px;\">Use this code to verify your email and complete your booking request:</p>
          <div style=\"font-size:42px;font-weight:700;letter-spacing:.3em;color:#2C6B22;background:#EBF6E8;border-radius:12px;padding:20px 24px;display:inline-block;font-family:'Courier New',monospace;\">{$code}</div>
          <p style=\"font-size:13px;color:#7B937F;margin:20px 0 0;\">This code expires in 5 minutes. If you didn't request this, ignore it.</p>
        </div>
        <div style=\"background:#f5faf3;padding:14px 24px;border-top:1px solid #DAE8D6;text-align:center;\">
          <p style=\"font-size:11px;color:#93AF9D;margin:0;\">Toronto Steam N Clean &nbsp;·&nbsp; 416 755 2528 &nbsp;·&nbsp; torontosteamnclean.ca</p>
        </div>
      </div>
    </div>
    </body></html>";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: Toronto Steam N Clean <info@torontosteamnclean.ca>',
    ];

    $sent = wp_mail( $email, 'Your Toronto Steam N Clean verification code: ' . $code, $body, $headers );

    if ( $sent ) {
        wp_send_json_success( ['msg' => 'Code sent.'] );
    } else {
        wp_send_json_error( ['msg' => 'Could not send email.'], 500 );
    }
}

function tsnc_handle_verify_code() {
    if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( sanitize_text_field($_POST['nonce']), 'tsnc_lead_nonce' ) ) {
        wp_send_json_error( ['msg' => 'Security check failed.'], 403 );
    }

    $email = isset($_POST['email']) ? sanitize_email($_POST['email'])     : '';
    $code  = isset($_POST['code'])  ? sanitize_text_field($_POST['code']) : '';

    if ( ! is_email($email) || ! preg_match('/^[0-9]{4}$/', $code) ) {
        wp_send_json_error( ['msg' => 'Invalid input.'], 400 );
    }

    $key    = 'tsnc_code_' . md5( strtolower($email) );
    $stored = get_transient( $key );

    if ( $stored === false ) {
        wp_send_json_error( ['msg' => 'Code expired — please request a new one.'] );
    }

    if ( $stored !== $code ) {
        wp_send_json_error( ['msg' => 'Incorrect code.'] );
    }

    delete_transient( $key );
    // Remember the verification so the booking handler can enforce it server-side (30 min)
    set_transient( 'tsnc_ok_' . md5( strtolower($email) ), 1, 30 * MINUTE_IN_SECONDS );
    wp_send_json_success( ['msg' => 'Verified.'] );
}

// ──────────────────────────────────────────────────────────────────────────────
// MAIN LEAD HANDLER
// v3.6: reads new window.__estimate shape: {area, returning, leather,
//       inHome:{has, lines[], total, surcharge:{building,fee}|null},
//       rugs:{has, lines[], total}}
// ──────────────────────────────────────────────────────────────────────────────

function tsnc_handle_lead() {
    if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( sanitize_text_field($_POST['nonce']), 'tsnc_lead_nonce' ) ) {
        wp_send_json_error( ['msg' => 'Security check failed.'], 403 );
    }

    $to           = 'info@torontosteamnclean.ca';
    $name         = isset($_POST['name'])     ? sanitize_text_field($_POST['name'])                          : '';
    $phone        = isset($_POST['phone'])    ? sanitize_text_field($_POST['phone'])                         : '';
    $email        = isset($_POST['email'])    ? sanitize_email($_POST['email'])                              : '';
    $email_only   = ! empty($_POST['email_only']);   // true for email-quote and exit-intent (no name/phone)

    // Validate required fields for booking (not email-only)
    if ( ! $email_only ) {
        if ( empty($name) ) {
            wp_send_json_error( ['msg' => 'Name is required.'], 400 );
        }
        if ( ! is_email($email) ) {
            wp_send_json_error( ['msg' => 'Invalid email.'], 400 );
        }
        if ( ! get_transient( 'tsnc_ok_' . md5( strtolower($email) ) ) ) {
            wp_send_json_error( ['msg' => 'Email not verified.'], 403 );
        }
        delete_transient( 'tsnc_ok_' . md5( strtolower($email) ) );
    }

    // Parse the full estimate JSON (new v2.1 shape)
    $estimate_raw = isset($_POST['estimate']) ? wp_unslash( $_POST['estimate'] ) : '{}';
    $estimate     = json_decode( $estimate_raw, true );
    if ( ! is_array($estimate) ) $estimate = [];

    $area      = isset($estimate['area'])      ? sanitize_text_field($estimate['area'])  : '—';
    $returning = ! empty($estimate['returning']);
    $leather   = ! empty($estimate['leather']);
    $in_home   = isset($estimate['inHome'])    ? $estimate['inHome']   : [];
    $rugs      = isset($estimate['rugs'])      ? $estimate['rugs']     : [];

    $in_home_has   = ! empty($in_home['has']);
    $rugs_has      = ! empty($rugs['has']);
    $in_home_total = isset($in_home['total']) ? sanitize_text_field($in_home['total']) : '$0';
    $rugs_total    = isset($rugs['total'])    ? sanitize_text_field($rugs['total'])    : '$0';
    $in_home_lines = isset($in_home['lines']) && is_array($in_home['lines']) ? $in_home['lines'] : [];
    $rugs_lines    = isset($rugs['lines'])    && is_array($rugs['lines'])    ? $rugs['lines']    : [];
    $surcharge     = isset($in_home['surcharge']) && is_array($in_home['surcharge']) ? $in_home['surcharge'] : null;

    // ── BOOKING (Reserve My Spot) ─────────────────────────────────────────────
    if ( ! $email_only ) {
        $returning_label = $returning ? 'Yes — returning customer' : 'No — new customer';

        // Build the team email
        $subject  = '🟢 New Booking Request — ' . $name . ' (' . $area . ')';
        $body     = tsnc_booking_email_v36( $name, $phone, $email, $area, $returning_label, $leather, $surcharge,
                                             $in_home_has, $in_home_lines, $in_home_total,
                                             $rugs_has, $rugs_lines, $rugs_total );
        $headers  = tsnc_email_headers( $email, $name );

        // Send to team and capture the Message-ID for threading
        $sent = tsnc_send_and_store_message_id( $to, $subject, $body, $headers );

        // Customer confirmation copy
        if ( $email && is_email($email) ) {
            $copy_subject = 'Your booking request is in — Toronto Steam N Clean';
            $copy_body    = tsnc_customer_booking_email_v36( $name, $area, $in_home_has, $in_home_lines, $in_home_total,
                                                              $rugs_has, $rugs_lines, $rugs_total );
            $copy_headers = [
                'Content-Type: text/html; charset=UTF-8',
                'Reply-To: Toronto Steam N Clean <info@torontosteamnclean.ca>',
            ];
            wp_mail( $email, $copy_subject, $copy_body, $copy_headers );
        }

        if ( $sent ) {
            // Return a request_id so JS can send step-2 details threaded to this email
            $request_id = uniqid('tsnc_', true);
            // Store the email+request_id mapping for 48 hours so step-2 can thread onto it
            set_transient( 'tsnc_req_' . $request_id, [
                'msg_id'  => get_transient('tsnc_last_msg_id'),
                'subject' => $subject,
                'email'   => $email,
                'name'    => $name,
            ], 48 * HOUR_IN_SECONDS );
            wp_send_json_success( ['msg' => 'Lead received.', 'request_id' => $request_id] );
        } else {
            tsnc_log_send_failure( 'booking', $name, $email );
            wp_send_json_error( ['msg' => 'Mail failed — check server mail config.'], 500 );
        }

    // ── EMAIL-QUOTE / EXIT-INTENT (email only, no name/phone) ─────────────────
    } else {
        $subject = $rugs_has && ! $in_home_has
            ? '📧 Quote Email — Rugs — ' . $area
            : '📧 Quote Email — ' . $area . ' — ' . $in_home_total;

        $body    = tsnc_quote_or_exit_email_v36( $email, $area,
                                                  $in_home_has, $in_home_lines, $in_home_total,
                                                  $rugs_has, $rugs_lines, $rugs_total );
        $headers = tsnc_email_headers( $email );
        $sent    = wp_mail( $to, $subject, $body, $headers );

        // Customer copy with full quote + $50 offer
        if ( $email && is_email($email) ) {
            $copy_subject = 'Your Toronto Steam N Clean estimate — ' . $area;
            $copy_body    = tsnc_customer_quote_email_v36( $email, $area,
                                                            $in_home_has, $in_home_lines, $in_home_total,
                                                            $rugs_has, $rugs_lines, $rugs_total );
            $copy_headers = [
                'Content-Type: text/html; charset=UTF-8',
                'Reply-To: Toronto Steam N Clean <info@torontosteamnclean.ca>',
            ];
            wp_mail( $email, $copy_subject, $copy_body, $copy_headers );
        }

        if ( $sent ) {
            wp_send_json_success( ['msg' => 'Lead received.'] );
        } else {
            tsnc_log_send_failure( 'quote/exit', '', $email );
            wp_send_json_error( ['msg' => 'Mail failed.'], 500 );
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 2 — booking details (address, date, time, notes)
// Called separately from the JS step-2 form submit
// ──────────────────────────────────────────────────────────────────────────────
// NOTE: Step 2 is handled by the same tsnc_lead action with a 'step' field.
// We detect it here inside tsnc_handle_lead via a separate check:
add_action( 'wp_ajax_tsnc_booking_details',        'tsnc_handle_booking_details' );
add_action( 'wp_ajax_nopriv_tsnc_booking_details', 'tsnc_handle_booking_details' );

function tsnc_handle_booking_details() {
    if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( sanitize_text_field($_POST['nonce']), 'tsnc_lead_nonce' ) ) {
        wp_send_json_error( ['msg' => 'Security check failed.'], 403 );
    }

    $to         = 'info@torontosteamnclean.ca';
    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    $address    = isset($_POST['address'])     ? sanitize_text_field($_POST['address'])     : '';
    $unit       = isset($_POST['unit'])        ? sanitize_text_field($_POST['unit'])        : '';
    $pref_date  = isset($_POST['preferredDate']) ? sanitize_text_field($_POST['preferredDate']) : '';
    $pref_time  = isset($_POST['preferredTime']) ? sanitize_text_field($_POST['preferredTime']) : '';
    $notes      = isset($_POST['accessNotes'])   ? sanitize_text_field($_POST['accessNotes'])   : '';
    $how_heard  = isset($_POST['howHeard'])      ? sanitize_text_field($_POST['howHeard'])      : '';

    // Parse estimate for the full quote in the step-2 email
    $estimate_raw = isset($_POST['estimate']) ? wp_unslash( $_POST['estimate'] ) : '{}';
    $estimate     = json_decode( $estimate_raw, true );
    if ( ! is_array($estimate) ) $estimate = [];

    $in_home = isset($estimate['inHome']) ? $estimate['inHome'] : [];
    $rugs    = isset($estimate['rugs'])   ? $estimate['rugs']   : [];

    $in_home_has   = ! empty($in_home['has']);
    $rugs_has      = ! empty($rugs['has']);
    $in_home_total = isset($in_home['total']) ? sanitize_text_field($in_home['total']) : '$0';
    $rugs_total    = isset($rugs['total'])    ? sanitize_text_field($rugs['total'])    : '$0';
    $in_home_lines = isset($in_home['lines']) && is_array($in_home['lines']) ? $in_home['lines'] : [];
    $rugs_lines    = isset($rugs['lines'])    && is_array($rugs['lines'])    ? $rugs['lines']    : [];
    $surcharge     = isset($in_home['surcharge']) && is_array($in_home['surcharge']) ? $in_home['surcharge'] : null;

    // Threading: look up the original email's Message-ID
    $orig = $request_id ? get_transient('tsnc_req_' . $request_id) : null;
    $threading_headers = [];
    if ( $orig && ! empty($orig['msg_id']) ) {
        $threading_headers[] = 'In-Reply-To: ' . $orig['msg_id'];
        $threading_headers[] = 'References: '  . $orig['msg_id'];
    }

    $name_from_orig = $orig ? $orig['name'] : '';
    $subject = '📋 Booking Details — ' . ( $name_from_orig ?: 'Customer' ) . ' — ' . ( $address ?: '(no address given)' );

    $body    = tsnc_details_email_v36( $name_from_orig, $address, $unit, $pref_date, $pref_time, $notes, $how_heard,
                                        $surcharge, $in_home_has, $in_home_lines, $in_home_total,
                                        $rugs_has, $rugs_lines, $rugs_total );
    $headers = array_merge( ['Content-Type: text/html; charset=UTF-8'], $threading_headers );

    $sent = wp_mail( $to, $subject, $body, $headers );

    error_log( sprintf( 'TSNC step-2 [req:%s] → sent:%s', $request_id, $sent ? 'yes' : 'NO' ) );

    if ( $sent ) {
        wp_send_json_success( ['msg' => 'Details received.'] );
    } else {
        wp_send_json_error( ['msg' => 'Mail failed.'], 500 );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// ESTIMATE BREAKDOWN RENDERER — v3.6
// Renders two independent blocks (in-home + rugs). Skips 'nudge' type lines.
// NEVER recomputes math — renders lines[] exactly as JS built them.
// ──────────────────────────────────────────────────────────────────────────────

function tsnc_render_lines( $lines, $block_total ) {
    $rows = '';
    if ( ! is_array($lines) || empty($lines) ) return '';

    foreach ( $lines as $line ) {
        $label = isset($line['label'])  ? sanitize_text_field($line['label'])  : '';
        $value = isset($line['amount']) ? sanitize_text_field($line['amount']) : '';
        $ltype = isset($line['type'])   ? $line['type'] : 'line';

        if ( empty($label) ) continue;
        if ( $ltype === 'nudge' ) continue;  // skip upsell nudges in email

        $label_esc = esc_html($label);
        $value_esc = esc_html($value);

        switch ( $ltype ) {
            case 'header':
                $rows .= "<tr><td colspan='2' style='padding:10px 10px 4px;font-size:12px;font-weight:700;color:#5B7766;text-transform:uppercase;letter-spacing:.08em;border-top:1px solid #DAE8D6;'>{$label_esc}</td></tr>";
                break;
            case 'total':
                $rows .= "<tr><td style='padding:10px 10px 4px;font-size:15px;font-weight:700;color:#2C6B22;border-top:2px solid #2C6B22;'>{$label_esc}</td><td style='padding:10px 10px 4px;font-size:16px;font-weight:700;color:#2C6B22;text-align:right;border-top:2px solid #2C6B22;white-space:nowrap;'>{$value_esc}</td></tr>";
                break;
            case 'subtotal':
                $rows .= "<tr style='border-top:1px solid #DAE8D6;'><td style='padding:8px 10px 4px;font-size:14px;font-weight:700;color:#22301F;'>{$label_esc}</td><td style='padding:8px 10px 4px;font-size:14px;font-weight:700;color:#22301F;text-align:right;white-space:nowrap;'>{$value_esc}</td></tr>";
                break;
            case 'savings':
                $rows .= "<tr><td style='padding:6px 10px;font-size:13px;font-weight:700;color:#2E7A20;background:#EAF7E4;'>{$label_esc}</td><td style='padding:6px 10px;font-size:13px;font-weight:700;color:#2E7A20;background:#EAF7E4;text-align:right;white-space:nowrap;'>{$value_esc}</td></tr>";
                break;
            case 'detail':
            case 'line':
            default:
                $is_sub = ( substr($label, 0, 2) === '· ' || substr($label, 0, 3) === ' · ' );
                $pad = $is_sub ? 'padding:5px 10px 5px 20px' : 'padding:7px 10px';
                $clr = $is_sub ? '#6b7a6f' : '#22301F';
                $fsz = $is_sub ? '12px' : '14px';
                $rows .= "<tr style='border-bottom:1px solid #e3ece0;'><td style='{$pad};font-size:{$fsz};color:{$clr};'>{$label_esc}</td><td style='{$pad};font-size:{$fsz};color:{$clr};text-align:right;white-space:nowrap;font-weight:600;'>{$value_esc}</td></tr>";
        }
    }

    return "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;max-width:520px;font-family:Arial,Helvetica,sans-serif;margin:10px 0;'>{$rows}</table>";
}

// ──────────────────────────────────────────────────────────────────────────────
// EMAIL BODY BUILDERS — v3.6
// ──────────────────────────────────────────────────────────────────────────────

function tsnc_email_wrapper_v36( $heading, $heading_color, $info_rows_html, $estimate_html, $footer_note, $auto_note = true ) {
    $auto = $auto_note
        ? '<p style="text-align:center;font-size:11px;color:#93AF9D;margin-top:14px;">Toronto Steam N Clean — automated lead notification</p>'
        : '';
    return "
    <!DOCTYPE html>
    <html><head><meta charset=\"UTF-8\"></head><body>
    <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f5faf3;padding:24px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #DAE8D6;\">
        <div style=\"background:{$heading_color};padding:16px 22px;\">
          <span style=\"color:#ffffff;font-size:16px;font-weight:700;\">{$heading}</span>
        </div>
        <div style=\"padding:20px 22px;\">
          <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"font-size:14px;color:#22301F;margin-bottom:10px;\">
            {$info_rows_html}
          </table>
          {$estimate_html}
          <p style=\"font-size:13px;color:#3C5847;margin-top:18px;\">{$footer_note}</p>
        </div>
      </div>
      {$auto}
    </div>
    </body></html>";
}

function tsnc_info_row( $label, $value ) {
    $label_esc = esc_html($label);
    $value_esc = esc_html($value);
    return "<tr>
        <td style='padding:3px 0;width:130px;color:#5B7766;vertical-align:top;'>{$label_esc}</td>
        <td style='padding:3px 0;font-weight:600;'>{$value_esc}</td>
    </tr>";
}

// Team email — booking request (step 1)
function tsnc_booking_email_v36( $name, $phone, $email, $area, $returning_label, $leather,
                                   $surcharge, $in_home_has, $in_home_lines, $in_home_total,
                                   $rugs_has, $rugs_lines, $rugs_total ) {
    $info  = tsnc_info_row( 'Name',       $name );
    $info .= tsnc_info_row( 'Phone',      $phone );
    $info .= tsnc_info_row( 'Email',      $email );
    $info .= tsnc_info_row( 'Area',       $area );
    $info .= tsnc_info_row( 'Returning',  $returning_label );
    if ( $leather ) $info .= tsnc_info_row( 'Leather', 'Yes — bring leather kit' );
    if ( $surcharge && ! empty($surcharge['building']) ) {
        $info .= tsnc_info_row( 'Building access', esc_html($surcharge['building']) . ' — ' . esc_html($surcharge['fee']) . ' surcharge (already in estimate)' );
    }
    $info .= tsnc_info_row( 'Time', wp_date('Y-m-d H:i:s T') );

    $estimate_html = tsnc_two_block_estimate( $in_home_has, $in_home_lines, $in_home_total,
                                               $rugs_has, $rugs_lines, $rugs_total );

    return tsnc_email_wrapper_v36(
        '🟢 New Booking Request',
        '#2C6B22',
        $info,
        $estimate_html,
        'Reply directly to this email or call/text the customer to confirm their time.'
    );
}

// Team email — quote / exit-intent (email only, no name/phone)
function tsnc_quote_or_exit_email_v36( $email, $area, $in_home_has, $in_home_lines, $in_home_total,
                                        $rugs_has, $rugs_lines, $rugs_total ) {
    $info  = tsnc_info_row( 'Email', $email );
    $info .= tsnc_info_row( 'Area',  $area );
    $info .= tsnc_info_row( 'Time',  wp_date('Y-m-d H:i:s T') );

    $estimate_html = tsnc_two_block_estimate( $in_home_has, $in_home_lines, $in_home_total,
                                               $rugs_has, $rugs_lines, $rugs_total );

    return tsnc_email_wrapper_v36(
        '📧 Quote Email Request',
        '#2E7A20',
        $info,
        $estimate_html,
        'Add to marketing list and follow up with $50 new-customer offer.'
    );
}

// Team email — step 2 details (threaded onto step 1)
function tsnc_details_email_v36( $name, $address, $unit, $pref_date, $pref_time, $notes, $how_heard,
                                   $surcharge, $in_home_has, $in_home_lines, $in_home_total,
                                   $rugs_has, $rugs_lines, $rugs_total ) {
    $info = '';
    if ( $name )      $info .= tsnc_info_row( 'Customer',  $name );
    $full_addr = $address . ( $unit ? ', Unit/Buzzer: ' . $unit : '' );
    if ( $full_addr ) $info .= tsnc_info_row( 'Address',   $full_addr );
    if ( $surcharge && ! empty($surcharge['building']) ) {
        $info .= tsnc_info_row( 'Building', esc_html($surcharge['building']) . ' — ' . esc_html($surcharge['fee']) . ' access surcharge' );
    }
    if ( $pref_date ) $info .= tsnc_info_row( 'Pref. date', $pref_date );
    if ( $pref_time ) $info .= tsnc_info_row( 'Pref. time', $pref_time );
    if ( $notes )     $info .= tsnc_info_row( 'Access notes', $notes );
    if ( $how_heard ) $info .= tsnc_info_row( 'How heard',  $how_heard );
    $info .= tsnc_info_row( 'Time', wp_date('Y-m-d H:i:s T') );

    // Full quote repeated for the office record
    $estimate_html = tsnc_two_block_estimate( $in_home_has, $in_home_lines, $in_home_total,
                                               $rugs_has, $rugs_lines, $rugs_total );

    return tsnc_email_wrapper_v36(
        '📋 Booking Details Added',
        '#1E5C35',
        $info,
        $estimate_html,
        'Update the booking record and confirm date/time with the customer.'
    );
}

// Customer booking confirmation (step 1)
function tsnc_customer_booking_email_v36( $name, $area, $in_home_has, $in_home_lines, $in_home_total,
                                           $rugs_has, $rugs_lines, $rugs_total ) {
    $greeting = $name ? "Hi {$name}," : "Hi there,";
    $area_esc = $area ? esc_html($area) : '';

    $estimate_html = tsnc_two_block_estimate( $in_home_has, $in_home_lines, $in_home_total,
                                               $rugs_has, $rugs_lines, $rugs_total );

    return "
    <!DOCTYPE html>
    <html><head><meta charset=\"UTF-8\"></head><body>
    <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f5faf3;padding:24px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #DAE8D6;\">
        <div style=\"background:#2C6B22;padding:20px 24px;\">
          <p style=\"color:#ffffff;font-size:18px;font-weight:700;margin:0;\">Toronto Steam N Clean</p>
          <p style=\"color:#CDF2BD;font-size:13px;margin:4px 0 0;\">Your booking request is confirmed</p>
        </div>
        <div style=\"padding:24px 24px 8px;\">
          <p style=\"font-size:15px;color:#22301F;margin:0 0 10px;\">{$greeting}</p>
          <p style=\"font-size:14px;color:#3C5847;margin:0 0 6px;\">Thanks — your booking request is in. We'll email you within <strong>1–2 business hours</strong> (Mon–Fri 9–6, Sat 9–3) to confirm your time.</p>
          " . ( $area_esc ? "<p style=\"font-size:13px;color:#5B7766;margin:0 0 16px;\">Estimate shown is for <strong>{$area_esc}</strong>. Travel, parking and setup are included. Your technician confirms the final count on the day — any change is always discussed with you first.</p>" : '' ) . "
          <h3 style=\"font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#5B7766;margin:0 0 6px;border-top:1px solid #DAE8D6;padding-top:14px;\">Your Estimate</h3>
          {$estimate_html}
          <div style=\"margin:20px 0;text-align:center;\">
            <a href=\"tel:4167552528\" style=\"display:inline-block;background:#3A962A;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:13px 32px;border-radius:999px;\">Call or Text to Confirm</a>
          </div>
          <p style=\"font-size:13px;color:#5B7766;margin:0 0 6px;\">📞 <strong>416 755 2528</strong> &nbsp;·&nbsp; ✉️ <a href=\"mailto:info@torontosteamnclean.ca\" style=\"color:#2E7A20;\">info@torontosteamnclean.ca</a></p>
          <p style=\"font-size:12px;color:#93AF9D;margin:16px 0 0;\">No payment now. Free to cancel or reschedule up to 24 hours before your appointment.</p>
        </div>
        <div style=\"background:#f5faf3;padding:14px 24px;border-top:1px solid #DAE8D6;\">
          <p style=\"font-size:11px;color:#93AF9D;margin:0;text-align:center;\">Toronto Steam N Clean &nbsp;·&nbsp; torontosteamnclean.ca</p>
        </div>
      </div>
    </div>
    </body></html>";
}

// Customer quote email (email-quote / exit-intent leads)
function tsnc_customer_quote_email_v36( $email, $area, $in_home_has, $in_home_lines, $in_home_total,
                                         $rugs_has, $rugs_lines, $rugs_total ) {
    $area_esc = $area ? esc_html($area) : '';
    $estimate_html = tsnc_two_block_estimate( $in_home_has, $in_home_lines, $in_home_total,
                                               $rugs_has, $rugs_lines, $rugs_total );

    $intro_line = $area_esc
        ? "Here's your estimate for <strong>{$area_esc}</strong>. These are real prices — no vague ranges, no surprise charges."
        : "Here's your instant estimate. These are real prices — no vague ranges, no surprise charges.";
    $loc_note = $area_esc
        ? "Travel, parking and setup are all included. Valid for service in {$area_esc}."
        : "Travel, parking and setup are all included. This estimate assumes Toronto area pricing.";

    return "
    <!DOCTYPE html>
    <html><head><meta charset=\"UTF-8\"></head><body>
    <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f5faf3;padding:24px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #DAE8D6;\">
        <div style=\"background:#2C6B22;padding:20px 24px;\">
          <p style=\"color:#ffffff;font-size:18px;font-weight:700;margin:0;\">Toronto Steam N Clean</p>
          <p style=\"color:#CDF2BD;font-size:13px;margin:4px 0 0;\">Your instant estimate is ready</p>
        </div>
        <div style=\"padding:24px 24px 8px;\">
          <p style=\"font-size:14px;color:#3C5847;margin:0 0 6px;\">{$intro_line}</p>
          <p style=\"font-size:13px;color:#5B7766;margin:0 0 20px;\">{$loc_note}</p>
          <h3 style=\"font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#5B7766;margin:0 0 6px;border-top:1px solid #DAE8D6;padding-top:14px;\">Your Estimate</h3>
          {$estimate_html}
          <div style=\"margin:24px 0;text-align:center;\">
            <a href=\"tel:4167552528\" style=\"display:inline-block;background:#3A962A;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:13px 32px;border-radius:999px;\">Call or Text Us to Book</a>
          </div>
          <p style=\"font-size:13px;color:#5B7766;margin:0 0 6px;\">📞 <strong>416 755 2528</strong> &nbsp;·&nbsp; ✉️ <a href=\"mailto:info@torontosteamnclean.ca\" style=\"color:#2E7A20;\">info@torontosteamnclean.ca</a></p>
          <p style=\"font-size:12px;color:#93AF9D;margin:16px 0 0;\">New customer <strong>\$50 discount</strong> applied above. Our technician may adjust on-site if soiling level or access conditions differ — always with your agreement first.</p>
        </div>
        <div style=\"background:#f5faf3;padding:14px 24px;border-top:1px solid #DAE8D6;\">
          <p style=\"font-size:11px;color:#93AF9D;margin:0;text-align:center;\">Toronto Steam N Clean &nbsp;·&nbsp; torontosteamnclean.ca</p>
        </div>
      </div>
    </div>
    </body></html>";
}

// ──────────────────────────────────────────────────────────────────────────────
// TWO-BLOCK ESTIMATE RENDERER
// Renders in-home block + rugs block as two independent sections.
// Never combines them into a single grand total.
// ──────────────────────────────────────────────────────────────────────────────

function tsnc_two_block_estimate( $in_home_has, $in_home_lines, $in_home_total,
                                   $rugs_has, $rugs_lines, $rugs_total ) {
    $html = '';

    if ( $in_home_has && ! empty($in_home_lines) ) {
        $html .= "<h3 style='font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#5B7766;margin:18px 0 4px;border-top:1px solid #DAE8D6;padding-top:14px;'>In-home cleaning</h3>";
        $html .= tsnc_render_lines( $in_home_lines, $in_home_total );
    }

    if ( $rugs_has && ! empty($rugs_lines) ) {
        $html .= "<h3 style='font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#5B7766;margin:18px 0 4px;border-top:1px solid #DAE8D6;padding-top:14px;'>Area rugs <span style='font-weight:400;color:#93AF9D;font-size:11px;'>(separate visit &amp; bill)</span></h3>";
        $html .= tsnc_render_lines( $rugs_lines, $rugs_total );
        $html .= "<p style='font-size:11px;color:#93AF9D;margin:4px 0 0;'>Rug prices are estimates based on dimensions provided. We measure each rug at our plant and confirm before cleaning if the total changes.</p>";
    }

    if ( ! $in_home_has && ! $rugs_has ) {
        $html = '<p style="font-size:13px;color:#93AF9D;margin:8px 0;">No estimate items — customer may not have filled the calculator.</p>';
    }

    return $html;
}

// ──────────────────────────────────────────────────────────────────────────────
// HELPERS
// ──────────────────────────────────────────────────────────────────────────────

function tsnc_email_headers( $reply_to_email = '', $reply_to_name = '' ) {
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    if ( $reply_to_email && is_email($reply_to_email) ) {
        $label = $reply_to_name ? "{$reply_to_name} <{$reply_to_email}>" : $reply_to_email;
        $headers[] = "Reply-To: {$label}";
    }
    return $headers;
}

/**
 * Send mail and capture the Message-ID header for threading.
 * Stores the ID in a transient (60 seconds) so the caller can read it.
 */
function tsnc_send_and_store_message_id( $to, $subject, $body, $headers ) {
    // Generate a unique Message-ID before sending
    $msg_id = '<tsnc-' . uniqid('', true) . '@torontosteamnclean.ca>';
    if ( is_array($headers) ) {
        $headers[] = 'Message-ID: ' . $msg_id;
    }
    set_transient( 'tsnc_last_msg_id', $msg_id, 60 );  // read immediately after
    return wp_mail( $to, $subject, $body, $headers );
}

function tsnc_log_send_failure( $type, $name, $email ) {
    error_log( sprintf(
        'TSNC wp_mail FAILED [%s] name=%s email=%s',
        $type, $name, $email
    ) );
}

// ──────────────────────────────────────────────────────────────────────────────
// WORDPRESS HOOKS
// ──────────────────────────────────────────────────────────────────────────────

// Log wp_mail failures
add_action( 'wp_mail_failed', 'tsnc_log_mail_failure' );
function tsnc_log_mail_failure( $wp_error ) {
    error_log( 'TSNC wp_mail_failed: ' . $wp_error->get_error_message() );
}

// Force HTML content type
add_filter( 'wp_mail_content_type', 'tsnc_mail_content_type' );
function tsnc_mail_content_type() {
    return 'text/html';
}

// Sender name
add_filter( 'wp_mail_from_name', 'tsnc_mail_from_name' );
function tsnc_mail_from_name() {
    return 'Toronto Steam N Clean';
}

// Sender address
add_filter( 'wp_mail_from', 'tsnc_mail_from' );
function tsnc_mail_from() {
    return 'info@torontosteamnclean.ca';
}

// Inject TSNC_AJAX config into every page footer
add_action( 'wp_footer', 'tsnc_inject_nonce' );
function tsnc_inject_nonce() {
    ?>
    <script>
    window.TSNC_AJAX = {
        url:   "<?php echo esc_url( admin_url('admin-ajax.php') ); ?>",
        nonce: "<?php echo wp_create_nonce('tsnc_lead_nonce'); ?>"
    };
    </script>
    <?php
}
