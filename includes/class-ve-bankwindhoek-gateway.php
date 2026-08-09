<?php
if (!defined('ABSPATH')) exit;

/**
 * Bank Windhoek (Adumo) Payment Gateway for Venture Events
 */
class VE_BankWindhoek_Gateway {

    private $test_mode = false;
    private $merchant_id = '';
    private $application_id = '';
    private $jwt_secret = '';

    public function __construct() {
        // Register with main plugin
        add_action('ve_register_gateways', [$this, 'register']);

        // Handle payment initiation
        add_action('ve_gateway_initiate_payment', [$this, 'initiate_payment'], 10, 5);

        // Handle return from Adumo
        add_action('template_redirect', [$this, 'handle_return']);

        // Admin settings
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page'], 20);
    }

    /**
     * Register this gateway
     */
    public function register($manager) {
        $manager->register_gateway('adumo', 'Bank Windhoek (Adumo)', [
            'description' => 'Secure card payments via Adumo (Bank Windhoek)',
            'icon'        => '',
            'priority'    => 10,
            'active'      => true,
        ]);
    }

    /**
     * Load current credentials (respecting Test Mode)
     */
    private function load_credentials() {
        // Checkbox options are stored as "1" / "0" / empty — be explicit
        $this->test_mode = (string) get_option('ve_bankwindhoek_test_mode', '0') === '1';

        // JWT secret is always from settings (never hard-coded).
        $this->jwt_secret = $this->clean_secret((string) get_option('ve_bankwindhoek_jwt_secret', ''));

        if ($this->test_mode) {
            // Built-in Adumo staging Merchant / Application IDs (from their docs).
            // Note: get_option() does NOT fall back to the default if the option
            // exists but is an empty string — so treat empty as "use default".
            $default_test_app_id = '23ADADC0-DA2D-4DAC-A128-4845A5D71293'; // 3DS
            $test_app_id         = $this->clean_guid((string) get_option('ve_bankwindhoek_test_application_id', ''));

            $this->merchant_id    = '9BA5008C-08EE-4286-A349-54AF91A621B0';
            $this->application_id = $test_app_id !== '' ? $test_app_id : $default_test_app_id;
        } else {
            $this->merchant_id    = $this->clean_guid((string) get_option('ve_bankwindhoek_merchant_id', ''));
            $this->application_id = $this->clean_guid((string) get_option('ve_bankwindhoek_application_id', ''));
        }
    }

    /** Strip invisible chars; uppercase GUID for Adumo. */
    private function clean_guid($value) {
        $value = $this->strip_invisible($value);
        $value = strtoupper($value);
        return $value;
    }

    private function clean_secret($value) {
        return $this->strip_invisible($value);
    }

    private function strip_invisible($value) {
        $value = trim((string) $value);
        // Zero-width / BOM / control chars that sneak in from portal copy-paste.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        if (function_exists('mb_convert_encoding')) {
            $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        }
        return trim($value);
    }

    /**
     * Debug lines for Debug Log Manager / WP_DEBUG_LOG.
     * Filter in the plugin UI for: VE BW Adumo
     * Never logs the JWT secret value.
     */
    private function debug_log($message, $context = []) {
        $line = 'VE BW Adumo: ' . $message;
        if (!empty($context) && is_array($context)) {
            // Redact anything that might be a secret.
            foreach (['Token', 'token', 'jwt', 'secret', 'jwt_secret', 'access_token', 'Authorization'] as $k) {
                if (isset($context[$k])) {
                    $v = (string) $context[$k];
                    $context[$k] = '[redacted len=' . strlen($v) . ']';
                }
            }
            $json = wp_json_encode($context, JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' | ' . $json;
            }
        }
        error_log($line);
    }

    /**
     * Show the payment form / button
     */
    public function initiate_payment($gateway_id, $payment_reference, $event_id, $total_amount, $gateway) {
        if ($gateway_id !== 'adumo') {
            return;
        }

        $this->load_credentials();

        $this->debug_log('initiate_payment start', [
            'ref'       => $payment_reference,
            'event_id'  => (int) $event_id,
            'amount'    => $total_amount,
            'test_mode' => $this->test_mode ? 1 : 0,
            'merchant'  => $this->merchant_id,
            'app'       => $this->application_id,
            'secret_len'=> strlen($this->jwt_secret),
        ]);

        if (empty($this->merchant_id) || empty($this->application_id) || empty($this->jwt_secret)) {
            $mode = $this->test_mode ? 'Test Mode' : 'Live Mode';
            $this->debug_log('missing credentials', [
                'mode'         => $mode,
                'has_merchant' => $this->merchant_id !== '',
                'has_app'      => $this->application_id !== '',
                'has_secret'   => $this->jwt_secret !== '',
            ]);
            echo '<div class="ve-bankwindhoek-error">';
            echo '<strong>Bank Windhoek gateway is not configured.</strong><br>';
            echo 'Current mode: <strong>' . esc_html($mode) . '</strong><br>';
            echo 'Please go to <strong>Events → Bank Windhoek Settings</strong> and enter your credentials.';
            if (!$this->test_mode) {
                echo '<br><em>Tip: enable Test Mode to use Adumo staging Merchant/Application IDs (JWT Secret still required).</em>';
            }
            echo '</div>';
            return;
        }

        // Keep original ref in return URLs (our DB uses VE-YYYYMMDD-NNNN with hyphens).
        $success_url = home_url('/?ve_bankwindhoek_return=success&ref=' . urlencode($payment_reference));
        $fail_url    = home_url('/?ve_bankwindhoek_return=fail&ref=' . urlencode($payment_reference));

        // Adumo MerchantReference / JWT mref: alphanumeric, spaces, underscores, hashes only
        // (no hyphens). Invalid refs can create a session then 500 on virtual/initialise/{token}.
        $adumo_mref = $this->adumo_safe_merchant_reference($payment_reference);

        // Adumo requires Amount and JWT "amount" to match exactly (string "x.xx").
        $amount_str = number_format((float) $total_amount, 2, '.', '');

        $jwt = $this->generate_jwt($adumo_mref, $amount_str);

        if (!$jwt) {
            $this->debug_log('JWT generation failed');
            echo '<div class="ve-bankwindhoek-error">Error generating payment token. Please contact support.</div>';
            return;
        }

        $action_url = $this->test_mode
            ? 'https://staging-apiv3.adumoonline.com/product/payment/v1/initialisevirtual'
            : 'https://apiv3.adumoonline.com/product/payment/v1/initialisevirtual';

        $fields = [
            'MerchantID'             => $this->merchant_id,
            'ApplicationID'          => $this->application_id,
            'Amount'                 => $amount_str,
            'Token'                  => $jwt,
            'RedirectSuccessfulURL'  => $success_url,
            'RedirectFailedURL'      => $fail_url,
            'MerchantReference'      => $adumo_mref,
        ];

        $this->debug_log('posting initialisevirtual', [
            'action_url' => $action_url,
            'amount'     => $amount_str,
            'mref'       => $adumo_mref,
            'success_url'=> $success_url,
            'fail_url'   => $fail_url,
            'jwt_len'    => strlen($jwt),
        ]);

        // Server-side init: Adumo often returns 200 + gateway URL with ?error=… then their
        // SPA spins on /transaction-status with initialise HTTP 500. Surface the real error.
        // (Do not wp_redirect here — Venture Events captures gateway HTML in an output buffer.)
        $init = $this->adumo_server_side_init($action_url, $fields);
        if (!empty($init['error'])) {
            $this->debug_log('init rejected', [
                'error'       => $init['error'],
                'description' => $init['error_description'] ?? '',
                'final_url'   => $init['final_url'] ?? '',
            ]);
            $this->render_adumo_error($init['error'], $init['error_description'] ?? '', $init['final_url'] ?? '');
            return;
        }
        if (!empty($init['final_url']) && empty($init['transport_error'])) {
            $this->debug_log('init OK — redirecting browser to Adumo', [
                'final_url' => $init['final_url'],
            ]);
            $this->render_redirect_to_adumo($init['final_url']);
            return;
        }

        // Fallback: classic browser form POST if server-side hop failed (network/firewall).
        if (!empty($init['transport_error'])) {
            $this->debug_log('server-side transport error — falling back to form POST', [
                'transport_error' => $init['transport_error'],
                'final_url'       => $init['final_url'] ?? '',
            ]);
        }

        $this->debug_log('rendering client form POST fallback');
        $this->render_payment_form($action_url, $fields);
    }

    /**
     * POST initialisevirtual, follow redirects, then prove the session by calling
     * channel-virtual login + virtual/initialise/{token} (the call that 500s in the browser).
     *
     * @return array{final_url?:string,error?:string,error_description?:string,transport_error?:string}
     */
    private function adumo_server_side_init($action_url, array $fields) {
        if (!function_exists('curl_init')) {
            $this->debug_log('curl not available on this PHP');
            return ['transport_error' => 'curl not available'];
        }

        $this->debug_log('step1 initialisevirtual POST', ['url' => $action_url]);

        $post = $this->adumo_curl_follow($action_url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: VentureEvents-BankWindhoek/1.0',
            ],
        ]);

        if (!empty($post['transport_error'])) {
            $this->debug_log('step1 transport error', $post);
            return $post;
        }

        $final = (string) ($post['final_url'] ?? '');
        $body  = (string) ($post['body'] ?? '');

        $this->debug_log('step1 response', [
            'http_code' => $post['http_code'] ?? null,
            'final_url' => $final,
            'hops'      => $post['urls'] ?? [],
            'body_len'  => strlen($body),
            'body_head' => substr(wp_strip_all_tags($body), 0, 200),
        ]);

        // Check every hop + final URL for Adumo error query params.
        foreach (($post['urls'] ?? []) as $hop_url) {
            $parsed = $this->parse_adumo_gateway_url($hop_url);
            if (!empty($parsed['error'])) {
                $this->debug_log('step1 hop has error query', [
                    'hop'  => $hop_url,
                    'err'  => $parsed['error'],
                    'desc' => $parsed['error_description'] ?? '',
                ]);
                return $parsed + ['final_url' => $final !== '' ? $final : $hop_url];
            }
        }

        // HTML/JS sometimes embeds error=… even when effective URL looks clean.
        if ($body !== '' && preg_match('/error(?:Description)?=([A-Za-z0-9_+%.-]+)/i', $body)) {
            if (preg_match('/[?&]error=([^&"\']+)/i', $body, $em)) {
                $desc = '';
                if (preg_match('/[?&]errorDescription=([^&"\']+)/i', $body, $dm)) {
                    $desc = rawurldecode(str_replace('+', ' ', $dm[1]));
                }
                $err = rawurldecode(str_replace('+', ' ', $em[1]));
                $this->debug_log('step1 error found in HTML body', ['error' => $err, 'desc' => $desc]);
                return [
                    'error'             => $err,
                    'error_description' => $desc,
                    'final_url'         => $final,
                ];
            }
        }

        if ($final === '' || (stripos($final, 'gateway.adumoonline.com') === false && stripos($final, 'token=') === false)) {
            $this->debug_log('step1 unexpected final URL', ['final_url' => $final]);
            return [
                'transport_error' => 'Unexpected Adumo response URL: ' . $final,
                'final_url'       => $final,
            ];
        }

        // Extract session token and fully validate via the same API the SPA calls.
        if (!preg_match('/[?&]token=([0-9a-fA-F-]{36})/', $final, $tm)) {
            $this->debug_log('step1 no session token in URL', ['final_url' => $final]);
            return [
                'error'             => 'No payment session token returned by Adumo',
                'error_description' => 'The gateway URL did not include a session token. Check Merchant ID, Application ID, and JWT Secret.',
                'final_url'         => $final,
            ];
        }
        $session_token = $tm[1];
        $this->debug_log('step1 session token', ['token' => $session_token]);

        $parts  = wp_parse_url($final);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? 'gateway.adumoonline.com';
        $origin = $scheme . '://' . $host;

        // 1) Anonymous SPA login → bearer access_token
        $login_url = $origin . '/channel-virtual/login';
        $this->debug_log('step2 gateway login', ['url' => $login_url]);
        $login = $this->adumo_curl_follow($login_url, [
            CURLOPT_HTTPGET    => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'Origin: ' . $origin,
                'Referer: ' . $final,
                'User-Agent: VentureEvents-BankWindhoek/1.0',
            ],
        ]);
        if (!empty($login['transport_error'])) {
            $this->debug_log('step2 login transport error', $login);
            return [
                'error'             => 'Could not authenticate to Adumo payment gateway',
                'error_description' => $login['transport_error'],
                'final_url'         => $final,
            ];
        }
        $login_body = (string) ($login['body'] ?? '');
        $login_json = json_decode($login_body, true);
        $bearer     = is_array($login_json) ? (string) ($login_json['access_token'] ?? '') : '';
        $this->debug_log('step2 login response', [
            'http_code'   => $login['http_code'] ?? null,
            'has_bearer'  => $bearer !== '',
            'bearer_len'  => strlen($bearer),
            'body_head'   => substr($login_body, 0, 180),
        ]);
        if ($bearer === '') {
            return [
                'error'             => 'Adumo login returned no access token',
                'error_description' => substr($login_body, 0, 300),
                'final_url'         => $final,
            ];
        }

        // 2) POST virtual/initialise/{token} — this is the call that 500s in the browser console.
        $init_url = $origin . '/channel-virtual/api/v1/virtual/initialise/' . rawurlencode($session_token);
        $this->debug_log('step3 virtual/initialise', ['url' => $init_url]);
        $init     = $this->adumo_curl_once($init_url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $bearer,
                'Origin: ' . $origin,
                'Referer: ' . $final,
                'User-Agent: VentureEvents-BankWindhoek/1.0',
            ],
        ]);

        $init_code = (int) ($init['http_code'] ?? 0);
        $init_body = (string) ($init['body'] ?? '');

        $this->debug_log('step3 initialise response', [
            'http_code' => $init_code,
            'body_len'  => strlen($init_body),
            'body'      => substr($init_body, 0, 800),
            'transport' => $init['transport_error'] ?? '',
        ]);

        if (!empty($init['transport_error'])) {
            return [
                'error'             => 'Adumo session check failed',
                'error_description' => $init['transport_error'],
                'final_url'         => $final,
            ];
        }

        if ($init_code < 200 || $init_code >= 300) {
            $msg = $this->summarize_adumo_init_failure($init_code, $init_body, $final);
            $this->debug_log('step3 initialise FAILED', $msg);
            return $msg + ['final_url' => $final];
        }

        // 200 + JSON session payload → safe to send the browser.
        $this->debug_log('step3 initialise OK — session ready', [
            'session_token' => $session_token,
            'mode'          => $this->test_mode ? 'test' : 'live',
        ]);
        return ['final_url' => $final];
    }

    /**
     * @return array{error:string,error_description:string}
     */
    private function summarize_adumo_init_failure($http_code, $body, $final_url) {
        $parsed_url = $this->parse_adumo_gateway_url($final_url);
        $url_error  = $parsed_url['error'] ?? '';
        $url_desc   = $parsed_url['error_description'] ?? '';

        $json = json_decode($body, true);
        $api_msg = '';
        if (is_array($json)) {
            foreach (['message', 'error', 'error_description', 'detail', 'title', 'path'] as $k) {
                if (!empty($json[$k]) && is_scalar($json[$k])) {
                    $api_msg .= ($api_msg !== '' ? ' — ' : '') . $k . ': ' . $json[$k];
                }
            }
            if ($api_msg === '' && $body !== '') {
                $api_msg = substr($body, 0, 400);
            }
        } elseif ($body !== '') {
            $api_msg = substr(wp_strip_all_tags($body), 0, 400);
        }

        $error = $url_error !== ''
            ? $url_error
            : ('Adumo payment page failed to load session (HTTP ' . (int) $http_code . ')');

        $desc_parts = array_filter([
            $url_desc,
            $api_msg,
            'This is the same failure as the browser spinner on /transaction-status.',
            'Re-check Live Merchant ID, Application ID, and JWT Secret from Lesaka (Manage Applications + JWT Reveal).',
            'Or enable Test Mode with the staging JWT secret to verify the integration.',
        ]);

        return [
            'error'             => $error,
            'error_description' => implode(' ', $desc_parts),
        ];
    }

    /**
     * Follow redirects manually so every hop URL can be inspected for ?error=.
     *
     * @param array $curl_opts Extra curl options (must not set CURLOPT_URL).
     * @return array{final_url?:string,body?:string,urls?:string[],transport_error?:string,http_code?:int}
     */
    private function adumo_curl_follow($url, array $curl_opts = []) {
        $urls     = [];
        $current  = $url;
        $body     = '';
        $code     = 0;
        $max_hops = 8;
        $is_first = true;

        for ($i = 0; $i < $max_hops; $i++) {
            $urls[] = $current;
            $opts   = $curl_opts;
            // Only first request is POST (for initialisevirtual); later hops are GET.
            if (!$is_first) {
                unset($opts[CURLOPT_POST], $opts[CURLOPT_POSTFIELDS]);
                $opts[CURLOPT_HTTPGET] = true;
                // Drop Content-Type on GET hops.
                if (!empty($opts[CURLOPT_HTTPHEADER]) && is_array($opts[CURLOPT_HTTPHEADER])) {
                    $opts[CURLOPT_HTTPHEADER] = array_values(array_filter(
                        $opts[CURLOPT_HTTPHEADER],
                        static function ($h) {
                            return stripos($h, 'Content-Type:') !== 0;
                        }
                    ));
                }
            }
            $is_first = false;

            $res = $this->adumo_curl_once($current, $opts);
            if (!empty($res['transport_error'])) {
                return $res + ['urls' => $urls, 'final_url' => $current];
            }
            $code = (int) ($res['http_code'] ?? 0);
            $body = (string) ($res['body'] ?? '');
            $loc  = (string) ($res['redirect_url'] ?? '');

            if ($loc !== '' && $code >= 300 && $code < 400) {
                // Relative Location.
                if (strpos($loc, 'http') !== 0) {
                    $p = wp_parse_url($current);
                    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
                    $loc = (strpos($loc, '/') === 0) ? ($origin . $loc) : (rtrim($current, '/') . '/' . $loc);
                }
                $current = $loc;
                continue;
            }

            // 200 (or other non-redirect) — done.
            return [
                'final_url' => $current,
                'body'      => $body,
                'urls'      => $urls,
                'http_code' => $code,
            ];
        }

        return [
            'transport_error' => 'Too many redirects',
            'final_url'       => $current,
            'urls'            => $urls,
        ];
    }

    /**
     * Single HTTP request (no auto-follow).
     *
     * @return array{body?:string,http_code?:int,redirect_url?:string,transport_error?:string}
     */
    private function adumo_curl_once($url, array $curl_opts = []) {
        $headers = [];
        $ch = curl_init($url);
        $base = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, $header_line) use (&$headers) {
                $len = strlen($header_line);
                $parts = explode(':', $header_line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ];
        curl_setopt_array($ch, $base + $curl_opts);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['transport_error' => "curl {$errno}: {$err}"];
        }

        return [
            'body'         => is_string($body) ? $body : '',
            'http_code'    => $code,
            'redirect_url' => $headers['location'] ?? '',
        ];
    }

    private function parse_adumo_gateway_url($url) {
        $parts = wp_parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $error = isset($query['error']) ? rawurldecode((string) $query['error']) : '';
        $desc  = isset($query['errorDescription'])
            ? rawurldecode((string) $query['errorDescription'])
            : (isset($query['error_description']) ? rawurldecode((string) $query['error_description']) : '');

        // Adumo uses + for spaces in query values.
        $error = str_replace('+', ' ', $error);
        $desc  = str_replace('+', ' ', $desc);

        if ($error === '' && $desc === '') {
            return [];
        }
        return [
            'error'             => $error !== '' ? $error : 'Adumo payment initialisation failed',
            'error_description' => $desc,
        ];
    }

    private function render_adumo_error($error, $description = '', $final_url = '') {
        $mode = $this->test_mode ? 'Test Mode' : 'Live Mode';
        $mid  = $this->merchant_id;
        $aid  = $this->application_id;
        // Mask middle of GUIDs for on-screen display.
        $mask = static function ($guid) {
            $guid = (string) $guid;
            if (strlen($guid) < 12) {
                return $guid;
            }
            return substr($guid, 0, 8) . '…' . substr($guid, -4);
        };

        echo '<div class="ve-bankwindhoek-error">';
        echo '<strong>Bank Windhoek / Adumo rejected this payment session.</strong><br><br>';
        echo '<strong>Adumo error:</strong> ' . esc_html($error) . '<br>';
        if ($description !== '' && $description !== $error) {
            echo '<strong>Detail:</strong> ' . esc_html($description) . '<br>';
        }
        echo '<br>';
        echo '<strong>Mode:</strong> ' . esc_html($mode) . '<br>';
        echo '<strong>Merchant ID in use:</strong> <code>' . esc_html($mask($mid)) . '</code><br>';
        echo '<strong>Application ID in use:</strong> <code>' . esc_html($mask($aid)) . '</code><br>';
        echo '<br>';
        echo 'Check <strong>Events → Bank Windhoek Settings</strong> against Lesaka portal:<br>';
        echo 'Portal Admin → Manage Applications (MERCHANT_UID + Application UID)<br>';
        echo 'E-commerce → JWT and API Authentication → Reveal (JWT Secret for that application).<br>';
        if ($this->test_mode) {
            echo '<br><em>Test Mode: JWT Secret must be the <strong>staging</strong> secret from Adumo Virtual docs, not your live portal secret.</em>';
        } else {
            echo '<br><em>Live Mode: do not use staging Merchant/Application IDs or the public staging JWT secret.</em>';
        }
        if ($final_url !== '') {
            error_log('VE Bank Windhoek: Adumo error URL=' . $final_url);
        }
        echo '</div>';
        $this->render_pay_styles();
    }

    private function render_pay_styles() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
        <style id="ve-bankwindhoek-pay-css">
            .ve-bankwindhoek-pay {
                --ve-primary: #f4a239;
                --ve-accent: #d1d741;
                --ve-text: #221f21;
                --ve-secondary: #7a7a7a;
                --ve-radius: 0px;
                --ve-font: "Effra", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                display: block;
                width: 100%;
                max-width: 480px;
                margin: 0 auto;
                padding: 8px 8px 4px;
                text-align: center;
                color: var(--ve-text);
                font-family: var(--ve-font);
                box-sizing: border-box;
            }
            .ve-bankwindhoek-pay form {
                display: block;
                text-align: center;
            }
            .ve-bankwindhoek-pay h3 {
                margin: 8px 0 12px;
                color: var(--ve-text);
                font-family: var(--ve-font);
                font-weight: 400;
                letter-spacing: 0.02em;
            }
            .ve-bankwindhoek-pay .ve-bw-logo {
                display: block;
                width: 100%;
                max-width: 300px;
                height: auto;
                margin: 0 auto 16px;
            }
            .ve-bankwindhoek-pay p {
                margin: 0 0 20px;
                color: var(--ve-secondary);
            }
            .ve-bankwindhoek-pay .ve-bw-btn {
                display: inline-block;
                width: 100%;
                max-width: 360px;
                padding: 0.95em 1.5em;
                margin-top: 10px;
                background: var(--ve-accent);
                color: #fff;
                border: 1px solid var(--ve-accent);
                border-radius: var(--ve-radius);
                box-shadow: none;
                cursor: pointer;
                font-family: var(--ve-font);
                font-weight: 400;
                font-size: 1.05em;
                text-transform: none;
                letter-spacing: 0.02em;
                line-height: 1.3;
                transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
            }
            .ve-bankwindhoek-pay .ve-bw-btn:hover {
                background: #fff;
                color: var(--ve-accent);
                border-color: var(--ve-accent);
            }
            .ve-bankwindhoek-error {
                max-width: 520px;
                margin: 0 auto;
                padding: 20px;
                text-align: left;
                color: var(--ve-text, #221f21);
                background: rgba(219, 18, 119, 0.06);
                border: 1px solid #db1277;
                border-radius: 0;
                font-family: "Effra", system-ui, sans-serif;
                box-shadow: none;
                line-height: 1.45;
            }
            .ve-bankwindhoek-error code {
                font-size: 0.92em;
                word-break: break-all;
            }
        </style>
        <?php
    }

    private function render_redirect_to_adumo($url) {
        $this->render_pay_styles();
        $safe = esc_url($url);
        ?>
        <div class="ve-bankwindhoek-pay">
            <p>Redirecting to secure payment…</p>
            <p><a class="ve-bw-btn" href="<?php echo $safe; ?>">Continue to payment page</a></p>
        </div>
        <script>
        (function () {
            var u = <?php echo wp_json_encode($url); ?>;
            if (u) { window.location.replace(u); }
        })();
        </script>
        <noscript>
            <meta http-equiv="refresh" content="0;url=<?php echo esc_attr($url); ?>">
        </noscript>
        <?php
    }

    private function render_payment_form($action_url, array $fields) {
        $this->render_pay_styles();
        ?>
        <div class="ve-bankwindhoek-pay">
            <img
                class="ve-bw-logo"
                src="<?php echo esc_url(VE_BANKWINDHOEK_URL . 'assets/Bank-Windhoek_logo.png'); ?>"
                alt="Bank Windhoek"
            >
            <h3>Pay with Card via Bank Windhoek Payment Gateway</h3>
            <p>You will be redirected to Bank Windhoek's secure payment gateway to complete your payment.</p>

            <form id="ve-bankwindhoek-form" method="post" action="<?php echo esc_url($action_url); ?>">
                <?php foreach ($fields as $name => $value) : ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>

                <button type="submit" class="ve-bw-btn">
                    Proceed to Secure Payment Page
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Adumo Virtual MerchantReference charset (docs):
     * alphanumeric, spaces, underscores, hashes only — max 38 chars.
     * Our internal refs use hyphens (VE-YYYYMMDD-NNNN); map for Adumo only.
     */
    private function adumo_safe_merchant_reference($payment_reference) {
        $ref = (string) $payment_reference;
        $ref = str_replace('-', '_', $ref);
        $ref = preg_replace('/[^A-Za-z0-9 _#]/', '', $ref);
        $ref = trim(preg_replace('/\s+/', ' ', $ref));
        if (strlen($ref) > 38) {
            $ref = substr($ref, 0, 38);
        }
        return $ref !== '' ? $ref : ('VE' . time());
    }

    /**
     * Build Adumo Virtual request JWT.
     *
     * @param string $merchant_reference Adumo-safe mref (already sanitised).
     * @param string $amount_str         Amount as "x.xx" — must match form Amount exactly.
     */
    private function generate_jwt($merchant_reference, $amount_str) {
        $this->load_credentials();

        if (empty($this->jwt_secret)) return false;

        // Adumo docs: amount is a decimal string (e.g. "12.00"), not a JSON number.
        // cuid/auid must match form MerchantID/ApplicationID; secret must be live JWT secret.
        $now = time();
        $payload = [
            'iss'    => 'Venture Events',
            'cuid'   => $this->merchant_id,
            'auid'   => $this->application_id,
            'amount' => (string) $amount_str,
            'mref'   => $merchant_reference,
            // Match Adumo sample: opaque base64 jti (not a UUID with hyphens).
            'jti'    => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            // Slight clock skew buffer (Adumo sample uses iat = time() - 60).
            'iat'    => $now - 60,
            'exp'    => $now + (60 * 30),
        ];

        return $this->jwt_encode($payload, $this->jwt_secret);
    }

    private function jwt_encode($payload, $secret) {
        // Compact JSON (no spaces) — same shape Adumo samples use.
        $header  = wp_json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = wp_json_encode($payload);
        if ($header === false || $payload === false) {
            return false;
        }

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Handle return from Adumo
     */
    public function handle_return() {
        // Adumo may return via GET or POST; query args can be on either.
        $status = isset($_REQUEST['ve_bankwindhoek_return'])
            ? sanitize_text_field(wp_unslash($_REQUEST['ve_bankwindhoek_return']))
            : '';

        if ($status === '') {
            return;
        }

        $ref = '';
        if (!empty($_REQUEST['ref'])) {
            $ref = sanitize_text_field(wp_unslash($_REQUEST['ref']));
        } elseif (!empty($_POST['MerchantReference'])) {
            $ref = sanitize_text_field(wp_unslash($_POST['MerchantReference']));
        }

        if ($ref === '') {
            $this->debug_log('return without payment reference', ['status' => $status]);
            return;
        }

        $this->debug_log('return from Adumo', [
            'status' => $status,
            'ref'    => $ref,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        ]);

        if ($status === 'success') {
            // Adumo may POST a long JWT in _RESPONSE_TOKEN (often > varchar(100)).
            // Prefer a short safe id; Venture Events also normalizes on save.
            $raw_token = isset($_POST['_RESPONSE_TOKEN']) ? (string) $_POST['_RESPONSE_TOKEN'] : '';
            if ($raw_token === '' && isset($_GET['_RESPONSE_TOKEN'])) {
                $raw_token = (string) $_GET['_RESPONSE_TOKEN'];
            }

            if ($raw_token !== '') {
                $transaction_id = (strlen($raw_token) <= 100)
                    ? sanitize_text_field($raw_token)
                    : ('adumo:' . substr(hash('sha256', $raw_token), 0, 40));
            } else {
                $transaction_id = 'adumo-' . wp_generate_uuid4();
            }

            $success_data = [
                'payment_reference' => $ref,
                'gateway'           => 'adumo',
                'transaction_id'    => $transaction_id,
                'status'            => 'success',
            ];

            $this->debug_log('payment success', [
                'ref' => $ref,
                'tx'  => $transaction_id,
            ]);

            do_action('ve_gateway_payment_success', $success_data);
            wp_redirect(home_url('/?payment=success&ref=' . urlencode($ref)));
            exit;
        }

        wp_redirect(home_url('/?payment=failed&ref=' . urlencode($ref)));
        exit;
    }

    public function register_settings() {
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_test_mode', [
            'type'              => 'string',
            'default'           => '0',
            'sanitize_callback' => static function ($value) {
                return empty($value) ? '0' : '1';
            },
        ]);
        $clean_guid = function ($value) {
            $value = is_string($value) ? $value : '';
            $value = trim($value);
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
            if (function_exists('mb_convert_encoding')) {
                $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
            }
            return strtoupper(trim($value));
        };
        $clean_secret = static function ($value) {
            $value = is_string($value) ? $value : '';
            $value = trim($value);
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
            if (function_exists('mb_convert_encoding')) {
                $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
            }
            return trim($value);
        };

        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_merchant_id', [
            'type'              => 'string',
            'sanitize_callback' => $clean_guid,
        ]);
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_application_id', [
            'type'              => 'string',
            'sanitize_callback' => $clean_guid,
        ]);
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_jwt_secret', [
            'type'              => 'string',
            // Do not use sanitize_text_field — it can alter secrets.
            'sanitize_callback' => $clean_secret,
        ]);
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_test_application_id', [
            'type'              => 'string',
            'sanitize_callback' => $clean_guid,
        ]);
    }

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=ve_event',
            'Bank Windhoek Settings',
            'Bank Windhoek Settings',
            'manage_options',
            've-bankwindhoek-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        $test_mode = (string) get_option('ve_bankwindhoek_test_mode', '0') === '1';
        ?>
        <div class="wrap">
            <h1>Bank Windhoek Gateway Settings (Adumo)</h1>

            <div id="ve-bankwindhoek-test-creds" class="notice notice-warning inline" style="padding:12px;<?php echo $test_mode ? '' : 'display:none;'; ?>">
                <h2 style="margin-top:0;">Built-in Test Credentials</h2>
                <p>When <strong>Test Mode</strong> is enabled, these Adumo staging values are used automatically for Merchant ID. Application ID uses the field below, or the default if blank. You still need to enter the JWT Secret (from Adumo staging docs or your merchant portal).</p>
                <p><strong>Important:</strong> Bank Windhoek generally requires <strong>3D Secure (3DS)</strong>.</p>
                <ul>
                    <li><strong>Merchant ID:</strong> <code>9BA5008C-08EE-4286-A349-54AF91A621B0</code></li>
                    <li><strong>Default Test Application ID (3DS):</strong> <code>23ADADC0-DA2D-4DAC-A128-4845A5D71293</code></li>
                </ul>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('ve_bankwindhoek_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Test Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ve_bankwindhoek_test_mode" id="ve_bankwindhoek_test_mode" value="1" <?php checked($test_mode); ?>>
                                Enable Test Mode (uses Adumo staging credentials + staging endpoint)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test Application ID (optional)</th>
                        <td>
                            <input type="text" name="ve_bankwindhoek_test_application_id" value="<?php echo esc_attr(get_option('ve_bankwindhoek_test_application_id', '')); ?>" class="regular-text" placeholder="23ADADC0-DA2D-4DAC-A128-4845A5D71293" />
                            <p class="description">Leave blank to use the default Adumo 3DS test Application ID.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Live Merchant ID</th>
                        <td><input type="text" name="ve_bankwindhoek_merchant_id" value="<?php echo esc_attr(get_option('ve_bankwindhoek_merchant_id')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Live Application ID</th>
                        <td><input type="text" name="ve_bankwindhoek_application_id" value="<?php echo esc_attr(get_option('ve_bankwindhoek_application_id')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">JWT Secret</th>
                        <td>
                            <input type="password" name="ve_bankwindhoek_jwt_secret" value="<?php echo esc_attr(get_option('ve_bankwindhoek_jwt_secret')); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">Required for both Test and Live modes. Use the staging secret from Adumo docs in Test Mode, and your live secret from the merchant portal in Live Mode. Never commit this value to source control.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <script>
            (function () {
                var checkbox = document.getElementById('ve_bankwindhoek_test_mode');
                var panel = document.getElementById('ve-bankwindhoek-test-creds');
                if (!checkbox || !panel) return;
                function sync() {
                    panel.style.display = checkbox.checked ? '' : 'none';
                }
                checkbox.addEventListener('change', sync);
                sync();
            })();
            </script>
        </div>
        <?php
    }
}
