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
        $this->jwt_secret = trim((string) get_option('ve_bankwindhoek_jwt_secret', ''));

        if ($this->test_mode) {
            // Built-in Adumo staging Merchant / Application IDs (from their docs).
            // Note: get_option() does NOT fall back to the default if the option
            // exists but is an empty string — so treat empty as "use default".
            $default_test_app_id = '23ADADC0-DA2D-4DAC-A128-4845A5D71293'; // 3DS
            $test_app_id         = trim((string) get_option('ve_bankwindhoek_test_application_id', ''));

            $this->merchant_id    = '9BA5008C-08EE-4286-A349-54AF91A621B0';
            $this->application_id = $test_app_id !== '' ? $test_app_id : $default_test_app_id;
        } else {
            $this->merchant_id    = trim((string) get_option('ve_bankwindhoek_merchant_id', ''));
            $this->application_id = trim((string) get_option('ve_bankwindhoek_application_id', ''));
        }
    }

    /**
     * Show the payment form / button
     */
    public function initiate_payment($gateway_id, $payment_reference, $event_id, $total_amount, $gateway) {
        if ($gateway_id !== 'adumo') {
            return;
        }

        $this->load_credentials();

        if (empty($this->merchant_id) || empty($this->application_id) || empty($this->jwt_secret)) {
            $mode = $this->test_mode ? 'Test Mode' : 'Live Mode';
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

        $success_url = home_url('/?ve_bankwindhoek_return=success&ref=' . urlencode($payment_reference));
        $fail_url    = home_url('/?ve_bankwindhoek_return=fail&ref=' . urlencode($payment_reference));

        $jwt = $this->generate_jwt($payment_reference, $total_amount);

        if (!$jwt) {
            echo '<div class="ve-bankwindhoek-error">Error generating payment token. Please contact support.</div>';
            return;
        }

        $action_url = $this->test_mode
            ? 'https://staging-apiv3.adumoonline.com/product/payment/v1/initialisevirtual'
            : 'https://apiv3.adumoonline.com/product/payment/v1/initialisevirtual';

        // Brand styles: Travel Namibia accents. No font-family / weight / size overrides.
        ?>
        <style id="ve-bankwindhoek-pay-css">
            .ve-bankwindhoek-pay {
                --ve-primary: #f48c26;
                --ve-accent: #c0d03c;
                --ve-secondary: #54595f;
                --ve-text: #7a7a7a;
                --ve-radius: 10px;
                --ve-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
                display: block;
                width: 100%;
                max-width: 480px;
                margin: 0 auto;
                padding: 8px 8px 4px;
                text-align: center;
                color: var(--ve-secondary);
                box-sizing: border-box;
            }
            .ve-bankwindhoek-pay form {
                display: block;
                text-align: center;
            }
            .ve-bankwindhoek-pay h3 {
                margin: 8px 0 12px;
                color: var(--ve-secondary);
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
                color: var(--ve-text);
            }
            .ve-bankwindhoek-pay .ve-bw-btn {
                display: inline-block;
                width: 100%;
                max-width: 360px;
                padding: 0.95em 1.5em;
                margin-top: 10px;
                background: var(--ve-primary);
                color: #fff;
                border: none;
                border-radius: var(--ve-radius);
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
                cursor: pointer;
                font: inherit;
                text-transform: uppercase;
                line-height: 1.3;
                transition: background-color 0.15s ease;
            }
            .ve-bankwindhoek-pay .ve-bw-btn:hover {
                background: #e07a18;
            }
            .ve-bankwindhoek-error {
                max-width: 480px;
                margin: 0 auto;
                padding: 20px;
                text-align: left;
                color: #7a1f1f;
                background: #fff5f5;
                border: none;
                border-radius: 10px;
                box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
            }
        </style>
        <div class="ve-bankwindhoek-pay">
            <img
                class="ve-bw-logo"
                src="<?php echo esc_url(VE_BANKWINDHOEK_URL . 'assets/Bank-Windhoek_logo.png'); ?>"
                alt="Bank Windhoek"
            >
            <h3>Pay with Card via Bank Windhoek Payment Gateway</h3>
            <p>You will be redirected to Bank Windhoek's secure payment gateway to complete your payment.</p>

            <form id="ve-bankwindhoek-form" method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="MerchantID"      value="<?php echo esc_attr($this->merchant_id); ?>">
                <input type="hidden" name="ApplicationID"   value="<?php echo esc_attr($this->application_id); ?>">
                <input type="hidden" name="Amount"          value="<?php echo esc_attr(number_format($total_amount, 2, '.', '')); ?>">
                <input type="hidden" name="Token"           value="<?php echo esc_attr($jwt); ?>">
                <input type="hidden" name="RedirectSuccessfulURL" value="<?php echo esc_url($success_url); ?>">
                <input type="hidden" name="RedirectFailedURL"    value="<?php echo esc_url($fail_url); ?>">
                <input type="hidden" name="MerchantReference"    value="<?php echo esc_attr($payment_reference); ?>">

                <button type="submit" class="ve-bw-btn">
                    Proceed to Secure Payment Page
                </button>
            </form>
        </div>
        <?php
    }

    private function generate_jwt($merchant_reference, $amount) {
        $this->load_credentials();

        if (empty($this->jwt_secret)) return false;

        $payload = [
            'iss'   => 'Venture Events',
            'cuid'  => $this->merchant_id,
            'auid'  => $this->application_id,
            'amount'=> (float) $amount,
            'mref'  => $merchant_reference,
            'jti'   => wp_generate_uuid4(),
            'iat'   => time(),
            'exp'   => time() + (60 * 30),
        ];

        return $this->jwt_encode($payload, $this->jwt_secret);
    }

    private function jwt_encode($payload, $secret) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);

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
            error_log('VE Bank Windhoek: Return received without payment reference');
            return;
        }

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

            error_log("VE Bank Windhoek: Payment return success for ref={$ref}, tx={$transaction_id}");

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
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_merchant_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_application_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_jwt_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('ve_bankwindhoek_settings', 've_bankwindhoek_test_application_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
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
