<?php

require_once __DIR__ . '/../bootstrap.php';

function aidfleet_verify_email_real(string $email): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'reason' => 'Invalid email format'];
    }

    $domain = substr(strrchr($email, '@'), 1);
    if (!$domain) {
        return ['valid' => false, 'reason' => 'Invalid email format'];
    }

    // Disposable domain blocklist
    $disposable = [
        'mailinator.com','guerrillamail.com','guerrillamail.net','tempmail.com',
        'throwaway.email','fakeinbox.com','sharklasers.com','guerrillamailblock.com',
        'grr.la','dispostable.com','yopmail.com','trashmail.com','mailnesia.com',
        'maildrop.cc','discard.email','tempail.com','temp-mail.org','getnada.com',
        'mohmal.com','burnermail.io','10minutemail.com','minutemail.com',
        'emailondeck.com','tempr.email','33mail.com','mytemp.email',
        'spam4.me','trashmail.me','harakirimail.com','mailsac.com',
    ];
    if (in_array(strtolower($domain), $disposable, true)) {
        return ['valid' => false, 'reason' => 'Disposable/temporary email addresses are not allowed'];
    }

    // DNS MX / A record check
    $mxhosts = [];
    $mxweight = [];
    $hasMx = @getmxrr($domain, $mxhosts, $mxweight);

    if (!$hasMx || empty($mxhosts)) {
        // Fallback: check for A record - some domains serve mail via A record
        if (!@checkdnsrr($domain, 'A')) {
            return ['valid' => false, 'reason' => 'Please enter a valid email address'];
        }
        $mxhosts = [$domain];
    }

   
    if (!empty($mxweight)) {
        array_multisort($mxweight, SORT_ASC, $mxhosts);
    }

 

    return ['valid' => true, 'reason' => 'Email domain verified'];
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'verify_email.php') {
    aidfleet_require_method('POST');

    $in    = aidfleet_get_json_body();
    $email = isset($in['email']) ? trim((string)$in['email']) : '';

    if ($email === '') {
        aidfleet_send_json(['success' => false, 'valid' => false, 'reason' => 'Email is required'], 400);
    }

    $result = aidfleet_verify_email_real($email);
    
    // Also check if already registered in AidFleet database
    $registered = false;
    try {
                $db = aidfleet_db();
        $registered = aidfleet_email_exists($email);
    } catch (Exception $e) { /* ignore DB errors here, the primary goal is verification */ }

    aidfleet_send_json([
        'success' => true,
        'valid'   => $result['valid'],
        'reason'  => $result['reason'],
        'registered' => $registered
    ]);
}
