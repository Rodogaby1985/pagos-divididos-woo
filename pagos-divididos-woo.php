<?php
/**
 * Plugin Name: Pagos Divididos Woo
 * Description: Checkout dividido en 3 pasos para pago de productos y envío por separado.
 * Version: 0.1.0
 * Author: Frankie Lencería
 * Requires Plugins: woocommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('PDW_Split_Checkout_Plugin')) {
    final class PDW_Split_Checkout_Plugin {
        private const OPTION_KEY = 'pdw_settings';
        private const SESSION_PRODUCT_ORDER = 'pdw_product_order_id';

        public static function init(): void {
            add_action('plugins_loaded', [__CLASS__, 'bootstrap']);
        }

        public static function bootstrap(): void {
            if (! class_exists('WooCommerce')) {
                return;
            }

            add_shortcode('pdw_checkout_dividido', [__CLASS__, 'render_shortcode']);
            add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
            add_action('admin_init', [__CLASS__, 'register_settings']);

            add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'filter_gateways_by_order_role']);

            add_action('woocommerce_payment_complete', [__CLASS__, 'handle_payment_complete']);
            add_action('woocommerce_order_status_failed', [__CLASS__, 'handle_payment_failed']);
            add_action('woocommerce_order_status_cancelled', [__CLASS__, 'handle_payment_failed']);

            add_action('init', [__CLASS__, 'handle_post_actions']);
        }

        private static function get_settings(): array {
            $defaults = [
                'enabled' => 'no',
                'shipping_gateway_id' => '',
                'step1_notice' => 'Estás pagando solo PRODUCTOS. El envío se paga en el siguiente paso.',
                'step2_notice' => 'Estás pagando solo ENVÍO. Este cobro se procesa en una cuenta distinta.',
                'contact_text' => 'Coordinar envío por WhatsApp al +54 0000-0000.',
            ];

            $settings = get_option(self::OPTION_KEY, []);

            if (! is_array($settings)) {
                $settings = [];
            }

            return wp_parse_args($settings, $defaults);
        }

        private static function is_enabled(): bool {
            $settings = self::get_settings();
            return isset($settings['enabled']) && 'yes' === $settings['enabled'];
        }

        public static function register_admin_menu(): void {
            add_submenu_page(
                'woocommerce',
                __('Checkout dividido', 'pdw'),
                __('Checkout dividido', 'pdw'),
                'manage_woocommerce',
                'pdw-checkout-dividido',
                [__CLASS__, 'render_settings_page']
            );
        }

        public static function register_settings(): void {
            register_setting('pdw_settings_group', self::OPTION_KEY, [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => self::get_settings(),
            ]);
        }

        public static function sanitize_settings(array $input): array {
            $input['enabled'] = isset($input['enabled']) && 'yes' === $input['enabled'] ? 'yes' : 'no';
            $input['shipping_gateway_id'] = isset($input['shipping_gateway_id']) ? sanitize_text_field($input['shipping_gateway_id']) : '';
            $input['step1_notice'] = isset($input['step1_notice']) ? sanitize_text_field($input['step1_notice']) : '';
            $input['step2_notice'] = isset($input['step2_notice']) ? sanitize_text_field($input['step2_notice']) : '';
            $input['contact_text'] = isset($input['contact_text']) ? sanitize_textarea_field($input['contact_text']) : '';

            return $input;
        }

        public static function render_settings_page(): void {
            if (! current_user_can('manage_woocommerce')) {
                return;
            }

            $settings = self::get_settings();
            $gateways = WC()->payment_gateways()->payment_gateways();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Checkout dividido en 3 pasos', 'pdw'); ?></h1>
                <form method="post" action="options.php">
                    <?php settings_fields('pdw_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="pdw_enabled"><?php esc_html_e('Activar checkout dividido', 'pdw'); ?></label></th>
                            <td>
                                <label>
                                    <input id="pdw_enabled" type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="yes" <?php checked('yes', $settings['enabled']); ?> />
                                    <?php esc_html_e('Habilitado', 'pdw'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pdw_shipping_gateway_id"><?php esc_html_e('Pasarela exclusiva de envío', 'pdw'); ?></label></th>
                            <td>
                                <select id="pdw_shipping_gateway_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[shipping_gateway_id]">
                                    <option value=""><?php esc_html_e('Seleccionar pasarela', 'pdw'); ?></option>
                                    <?php foreach ($gateways as $gateway): ?>
                                        <option value="<?php echo esc_attr($gateway->id); ?>" <?php selected($settings['shipping_gateway_id'], $gateway->id); ?>>
                                            <?php echo esc_html($gateway->get_title() . ' (' . $gateway->id . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Esta pasarela se mostrará solo para cobrar envío en el Paso 2.', 'pdw'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pdw_step1_notice"><?php esc_html_e('Texto Paso 1', 'pdw'); ?></label></th>
                            <td><input id="pdw_step1_notice" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[step1_notice]" value="<?php echo esc_attr($settings['step1_notice']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pdw_step2_notice"><?php esc_html_e('Texto Paso 2', 'pdw'); ?></label></th>
                            <td><input id="pdw_step2_notice" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[step2_notice]" value="<?php echo esc_attr($settings['step2_notice']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pdw_contact_text"><?php esc_html_e('Texto de contacto por fallo de envío', 'pdw'); ?></label></th>
                            <td><textarea id="pdw_contact_text" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_text]"><?php echo esc_textarea($settings['contact_text']); ?></textarea></td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
                <p><strong><?php esc_html_e('Shortcode:', 'pdw'); ?></strong> <code>[pdw_checkout_dividido]</code></p>
            </div>
            <?php
        }

        public static function render_shortcode(): string {
            if (! self::is_enabled()) {
                return '<p>' . esc_html__('El checkout dividido está desactivado.', 'pdw') . '</p>';
            }

            if (! function_exists('WC') || ! WC()->cart) {
                return '<p>' . esc_html__('WooCommerce no está inicializado.', 'pdw') . '</p>';
            }

            $settings = self::get_settings();
            $product_order = self::get_product_order_from_session();

            ob_start();

            if (! $product_order) {
                self::render_step_1($settings);
                return (string) ob_get_clean();
            }

            $products_status = (string) $product_order->get_meta('_pdw_products_payment_status');

            if ('paid' !== $products_status) {
                echo '<h3>Paso 1: Pago de productos</h3>';
                echo '<p>' . esc_html($settings['step1_notice']) . '</p>';
                echo '<p>' . esc_html__('Tu pedido de productos está pendiente de pago.', 'pdw') . '</p>';
                echo '<form method="post">';
                wp_nonce_field('pdw_retry_products_payment', '_pdw_nonce');
                echo '<input type="hidden" name="pdw_action" value="retry_products_payment" />';
                echo '<button type="submit" class="button alt">' . esc_html__('Pagar productos', 'pdw') . '</button>';
                echo '</form>';
                return (string) ob_get_clean();
            }

            $shipping_order_id = (int) $product_order->get_meta('_pdw_shipping_order_id');
            $shipping_order = $shipping_order_id ? wc_get_order($shipping_order_id) : null;
            $shipping_status = (string) $product_order->get_meta('_pdw_shipping_payment_status');

            if (! $shipping_order || ! in_array($shipping_status, ['paid', 'failed'], true)) {
                self::render_step_2($settings, $product_order, $shipping_order, $shipping_status);
                return (string) ob_get_clean();
            }

            self::render_step_3($settings, $product_order, $shipping_order, $shipping_status);

            return (string) ob_get_clean();
        }

        private static function render_step_1(array $settings): void {
            $cart_subtotal = WC()->cart->get_subtotal();
            echo '<h3>Paso 1: Pago de productos</h3>';
            echo '<p>' . esc_html($settings['step1_notice']) . '</p>';
            echo '<p><strong>' . esc_html__('Subtotal productos:', 'pdw') . '</strong> ' . wp_kses_post(wc_price($cart_subtotal)) . '</p>';

            if (WC()->cart->is_empty()) {
                echo '<p>' . esc_html__('Tu carrito está vacío.', 'pdw') . '</p>';
                return;
            }

            echo '<form method="post">';
            wp_nonce_field('pdw_create_products_order', '_pdw_nonce');
            echo '<input type="hidden" name="pdw_action" value="create_products_order" />';
            echo '<p><label>' . esc_html__('Nombre', 'pdw') . '<br><input required type="text" name="billing_first_name" /></label></p>';
            echo '<p><label>' . esc_html__('Apellido', 'pdw') . '<br><input required type="text" name="billing_last_name" /></label></p>';
            echo '<p><label>' . esc_html__('Email', 'pdw') . '<br><input required type="email" name="billing_email" /></label></p>';
            echo '<p><label>' . esc_html__('Teléfono', 'pdw') . '<br><input required type="text" name="billing_phone" /></label></p>';
            echo '<p><label>' . esc_html__('Dirección', 'pdw') . '<br><input required type="text" name="billing_address_1" /></label></p>';
            echo '<p><label>' . esc_html__('Ciudad', 'pdw') . '<br><input required type="text" name="billing_city" /></label></p>';
            echo '<p><label>' . esc_html__('Provincia/Estado', 'pdw') . '<br><input type="text" name="billing_state" /></label></p>';
            echo '<p><label>' . esc_html__('Código postal', 'pdw') . '<br><input required type="text" name="billing_postcode" /></label></p>';
            echo '<p><label for="pdw_billing_country">' . esc_html__('País', 'pdw') . '</label><br><select required id="pdw_billing_country" name="billing_country">';
            foreach (WC()->countries->get_countries() as $country_code => $country_name) {
                echo '<option value="' . esc_attr($country_code) . '" ' . selected('AR', $country_code, false) . '>' . esc_html($country_name) . '</option>';
            }
            echo '</select></p>';
            echo '<button type="submit" class="button alt">' . esc_html__('Crear y pagar productos', 'pdw') . '</button>';
            echo '</form>';
        }

        private static function render_step_2(array $settings, WC_Order $product_order, ?WC_Order $shipping_order, string $shipping_status): void {
            echo '<h3>Paso 2: Pago de envío</h3>';
            echo '<p>' . esc_html($settings['step2_notice']) . '</p>';

            if ($shipping_order && ! $shipping_order->is_paid()) {
                echo '<p>' . esc_html__('El pago de envío está pendiente.', 'pdw') . '</p>';
                echo '<a class="button" href="' . esc_url($shipping_order->get_checkout_payment_url()) . '">' . esc_html__('Pagar envío', 'pdw') . '</a>';
                return;
            }

            if ('failed' === $shipping_status) {
                echo '<p><strong>' . esc_html__('El pago de envío falló.', 'pdw') . '</strong></p>';
            }

            $rates = self::get_shipping_rates_from_product_order($product_order);

            if (empty($rates)) {
                echo '<p>' . esc_html__('No hay métodos de envío disponibles para la dirección cargada.', 'pdw') . '</p>';
                return;
            }

            echo '<form method="post">';
            wp_nonce_field('pdw_create_shipping_order', '_pdw_nonce');
            echo '<input type="hidden" name="pdw_action" value="create_shipping_order" />';

            $rate_index = 0;
            foreach ($rates as $rate_id => $rate_data) {
                $input_id = 'pdw_shipping_rate_' . $rate_index;
                echo '<p><input required id="' . esc_attr($input_id) . '" type="radio" name="shipping_rate_id" value="' . esc_attr($rate_id) . '" /> ';
                echo '<label for="' . esc_attr($input_id) . '">' . esc_html($rate_data['label']) . ' - ' . wp_kses_post(wc_price((float) $rate_data['cost'])) . '</label></p>';
                $rate_index++;
            }

            echo '<button type="submit" class="button alt">' . esc_html__('Crear y pagar envío', 'pdw') . '</button>';
            echo '</form>';
        }

        private static function render_step_3(array $settings, WC_Order $product_order, WC_Order $shipping_order, string $shipping_status): void {
            $finalized = (string) $product_order->get_meta('_pdw_finalized');
            $products_paid = 'paid' === (string) $product_order->get_meta('_pdw_products_payment_status');
            $shipping_paid = 'paid' === $shipping_status;

            echo '<h3>Paso 3: Resumen y confirmación</h3>';
            echo '<p><strong>' . esc_html__('Pago PRODUCTOS', 'pdw') . ':</strong> ' . wp_kses_post(wc_price((float) $product_order->get_total())) . ' - ' . esc_html($products_paid ? 'Pagado' : 'Pendiente') . '</p>';
            echo '<p><strong>' . esc_html__('Pago ENVÍO', 'pdw') . ':</strong> ' . wp_kses_post(wc_price((float) $shipping_order->get_total())) . ' - ' . esc_html($shipping_paid ? 'Pagado' : 'Fallido') . '</p>';

            if ('yes' === $finalized) {
                echo '<p>' . esc_html__('Pedido confirmado. Gracias por tu compra.', 'pdw') . '</p>';
                return;
            }

            echo '<form method="post">';
            wp_nonce_field('pdw_confirm_order', '_pdw_nonce');
            echo '<input type="hidden" name="pdw_action" value="confirm_order" />';
            echo '<button type="submit" class="button alt">' . esc_html__('Confirmar pedido final', 'pdw') . '</button>';
            echo '</form>';

            if (! $shipping_paid) {
                echo '<p><strong>' . esc_html__('Coordinar envío', 'pdw') . ':</strong> ' . esc_html($settings['contact_text']) . '</p>';
            }
        }

        public static function handle_post_actions(): void {
            if (! self::is_enabled() || ! isset($_POST['pdw_action'])) {
                return;
            }

            if (! function_exists('WC') || ! WC()->session) {
                return;
            }

            $action = sanitize_key(wp_unslash($_POST['pdw_action']));
            $nonce = isset($_POST['_pdw_nonce']) ? sanitize_text_field(wp_unslash($_POST['_pdw_nonce'])) : '';
            $nonce_actions = [
                'create_products_order' => 'pdw_create_products_order',
                'retry_products_payment' => 'pdw_retry_products_payment',
                'create_shipping_order' => 'pdw_create_shipping_order',
                'confirm_order' => 'pdw_confirm_order',
            ];

            if (! isset($nonce_actions[$action]) || ! wp_verify_nonce($nonce, $nonce_actions[$action])) {
                wc_add_notice(__('Tu sesión expiró. Por favor intentá nuevamente.', 'pdw'), 'error');
                return;
            }

            if ('create_products_order' === $action) {
                self::handle_create_products_order();
            }

            if ('retry_products_payment' === $action) {
                self::handle_retry_products_payment();
            }

            if ('create_shipping_order' === $action) {
                self::handle_create_shipping_order();
            }

            if ('confirm_order' === $action) {
                self::handle_confirm_order();
            }
        }

        private static function handle_create_products_order(): void {
            if (WC()->cart->is_empty()) {
                return;
            }

            $existing = self::get_product_order_from_session();
            if ($existing instanceof WC_Order) {
                if ('paid' === (string) $existing->get_meta('_pdw_products_payment_status')) {
                    return;
                }
                self::process_products_payment($existing);
                return;
            }

            $country = wc_strtoupper(sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')));

            $address = [
                'first_name' => sanitize_text_field(wp_unslash($_POST['billing_first_name'] ?? '')),
                'last_name'  => sanitize_text_field(wp_unslash($_POST['billing_last_name'] ?? '')),
                'email'      => sanitize_email(wp_unslash($_POST['billing_email'] ?? '')),
                'phone'      => sanitize_text_field(wp_unslash($_POST['billing_phone'] ?? '')),
                'address_1'  => sanitize_text_field(wp_unslash($_POST['billing_address_1'] ?? '')),
                'city'       => sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')),
                'state'      => sanitize_text_field(wp_unslash($_POST['billing_state'] ?? '')),
                'postcode'   => sanitize_text_field(wp_unslash($_POST['billing_postcode'] ?? '')),
                'country'    => $country,
            ];

            $required_fields = ['first_name', 'last_name', 'email', 'phone', 'address_1', 'city', 'postcode', 'country'];
            $field_labels = [
                'first_name' => __('Nombre', 'pdw'),
                'last_name' => __('Apellido', 'pdw'),
                'email' => __('Email', 'pdw'),
                'phone' => __('Teléfono', 'pdw'),
                'address_1' => __('Dirección', 'pdw'),
                'city' => __('Ciudad', 'pdw'),
                'postcode' => __('Código postal', 'pdw'),
                'country' => __('País', 'pdw'),
            ];
            $missing_fields = [];
            foreach ($required_fields as $field_key) {
                if ('' === $address[$field_key]) {
                    $missing_fields[] = $field_labels[$field_key];
                }
            }

            if (! empty($missing_fields)) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: missing billing fields */
                        __('Completá los siguientes campos: %s.', 'pdw'),
                        implode(', ', $missing_fields)
                    ),
                    'error'
                );
                return;
            }

            if (! WC()->countries->country_exists($address['country'])) {
                wc_add_notice(__('El país ingresado no es válido.', 'pdw'), 'error');
                return;
            }

            $order = wc_create_order();
            if (! $order instanceof WC_Order) {
                wc_add_notice(__('No se pudo crear la orden de productos. Intentá nuevamente.', 'pdw'), 'error');
                return;
            }

            foreach (WC()->cart->get_cart() as $item) {
                $product = $item['data'];
                $order->add_product($product, (int) $item['quantity']);
            }

            $order->set_address($address, 'billing');
            $order->set_address($address, 'shipping');

            $order->calculate_totals();
            $order->update_meta_data('_pdw_role', 'products');
            $order->update_meta_data('_pdw_products_payment_status', 'pending');
            $order->update_meta_data('_pdw_shipping_payment_status', 'pending');
            $order->save();

            WC()->session->set(self::SESSION_PRODUCT_ORDER, $order->get_id());
            self::process_products_payment($order);
        }

        private static function handle_retry_products_payment(): void {
            $order = self::get_product_order_from_session();
            if (! $order instanceof WC_Order) {
                wc_add_notice(__('No se encontró el pedido. Por favor, intentá nuevamente desde el inicio.', 'pdw'), 'error');
                return;
            }

            if ('paid' === (string) $order->get_meta('_pdw_products_payment_status')) {
                return;
            }

            self::process_products_payment($order);
        }

        private static function get_product_gateway(): ?WC_Payment_Gateway {
            $settings = self::get_settings();
            $shipping_gateway_id = (string) $settings['shipping_gateway_id'];

            $all_gateways = WC()->payment_gateways()->payment_gateways();
            $available = [];

            foreach ($all_gateways as $id => $gateway) {
                if ('yes' !== $gateway->enabled) {
                    continue;
                }
                if ('' !== $shipping_gateway_id && $id === $shipping_gateway_id) {
                    continue;
                }
                $available[$id] = $gateway;
            }

            if (empty($available)) {
                return null;
            }

            // Use the first enabled gateway in WooCommerce's configured order.
            // Merchants control gateway priority from WooCommerce > Settings > Payments.
            return reset($available);
        }

        private static function process_products_payment(WC_Order $order): void {
            $gateway = self::get_product_gateway();
            $logger = wc_get_logger();
            $user_error_message = __('No fue posible iniciar el pago de productos. Intenta nuevamente o contacta soporte.', 'pdw');

            if (null === $gateway) {
                $logger->error(
                    sprintf('PDW Paso 1: No hay gateway de productos disponible para order #%d.', $order->get_id()),
                    ['source' => 'pdw']
                );
                wc_add_notice(__('No hay una pasarela elegible para el pago de productos. Contactá soporte para continuar.', 'pdw'), 'error');
                return;
            }

            $order->set_payment_method($gateway->id);
            $order->set_payment_method_title($gateway->get_title());
            $order->save();

            $logger->info(
                sprintf('PDW Paso 1: Iniciando pago de productos para order #%d con gateway "%s".', $order->get_id(), $gateway->id),
                ['source' => 'pdw']
            );

            try {
                $result = $gateway->process_payment($order->get_id());
            } catch (Throwable $e) {
                $logger->error(
                    sprintf('PDW Paso 1: Excepción en process_payment para order #%d con gateway "%s": %s', $order->get_id(), $gateway->id, $e->getMessage()),
                    ['source' => 'pdw']
                );
                wc_add_notice($user_error_message, 'error');
                return;
            }

            $result_summary = self::summarize_payment_result($result);
            $logger->info(
                sprintf(
                    'PDW Paso 1: Resultado de process_payment para order #%d con gateway "%s": %s',
                    $order->get_id(),
                    $gateway->id,
                    $result_summary
                ),
                ['source' => 'pdw']
            );

            if (! is_array($result)) {
                $logger->error(
                    sprintf(
                        'PDW Paso 1: Rechazado process_payment para order #%d con gateway "%s". Motivo: respuesta no es array. Resumen=%s',
                        $order->get_id(),
                        $gateway->id,
                        $result_summary
                    ),
                    ['source' => 'pdw']
                );
                wc_add_notice($user_error_message, 'error');
                return;
            }

            $payment_result = sanitize_text_field((string) ($result['result'] ?? ''));
            $redirect = trim((string) ($result['redirect'] ?? ''));

            if ('success' !== $payment_result || '' === $redirect) {
                $logger->error(
                    sprintf(
                        'PDW Paso 1: Rechazado process_payment para order #%d con gateway "%s". Motivo: success/redirect inválidos. result=%s redirect=%s',
                        $order->get_id(),
                        $gateway->id,
                        $payment_result,
                        '' === $redirect ? '(empty)' : esc_url_raw($redirect)
                    ),
                    ['source' => 'pdw']
                );
                wc_add_notice($user_error_message, 'error');
                return;
            }

            if (self::is_order_received_redirect($redirect) && ! $order->is_paid()) {
                $logger->error(
                    sprintf(
                        'PDW Paso 1: Rechazado redirect a order-received para order #%d con gateway "%s". Motivo: orden sin pago confirmado. redirect=%s',
                        $order->get_id(),
                        $gateway->id,
                        esc_url_raw($redirect)
                    ),
                    ['source' => 'pdw']
                );
                wc_add_notice($user_error_message, 'error');
                return;
            }

            $logger->info(
                sprintf('PDW Paso 1: Redirigiendo order #%d a gateway "%s": %s', $order->get_id(), $gateway->id, $redirect),
                ['source' => 'pdw']
            );

            wp_safe_redirect($redirect);
            exit;
        }

        private static function summarize_payment_result($result): string {
            if (! is_array($result)) {
                return 'type=' . gettype($result);
            }

            $summary = [
                'keys' => array_values(array_map('sanitize_key', array_keys($result))),
                'result' => sanitize_text_field((string) ($result['result'] ?? '')),
            ];

            $redirect = trim((string) ($result['redirect'] ?? ''));
            if ('' !== $redirect) {
                $redirect_host = wp_parse_url($redirect, PHP_URL_HOST);
                if (is_string($redirect_host) && '' !== $redirect_host) {
                    $summary['redirect_host'] = sanitize_text_field($redirect_host);
                }

                $redirect_path = wp_parse_url($redirect, PHP_URL_PATH);
                if (is_string($redirect_path) && '' !== $redirect_path) {
                    $summary['redirect_path'] = sanitize_text_field($redirect_path);
                }
            }

            $json = wp_json_encode($summary);
            return false === $json ? 'json_encode_error' : $json;
        }

        private static function is_order_received_redirect(string $redirect): bool {
            $redirect_path = wp_parse_url($redirect, PHP_URL_PATH);
            if (! is_string($redirect_path) || '' === $redirect_path) {
                return false;
            }

            return 1 === preg_match('#/order-received/#', $redirect_path);
        }

        private static function handle_create_shipping_order(): void {
            $product_order = self::get_product_order_from_session();

            if (! $product_order instanceof WC_Order) {
                return;
            }

            if ('paid' !== (string) $product_order->get_meta('_pdw_products_payment_status')) {
                return;
            }

            $existing_shipping_order_id = (int) $product_order->get_meta('_pdw_shipping_order_id');
            if ($existing_shipping_order_id > 0) {
                $existing_shipping_order = wc_get_order($existing_shipping_order_id);
                if ($existing_shipping_order instanceof WC_Order) {
                    if ($existing_shipping_order->is_paid()) {
                        $product_order->update_meta_data('_pdw_shipping_payment_status', 'paid');
                        $product_order->save();
                        return;
                    }

                    if ($existing_shipping_order->needs_payment() || $existing_shipping_order->has_status(['pending', 'on-hold'])) {
                        wp_safe_redirect($existing_shipping_order->get_checkout_payment_url());
                        exit;
                    }
                }
            }

            $shipping_rate_id = sanitize_text_field(wp_unslash($_POST['shipping_rate_id'] ?? ''));
            $rates = self::get_shipping_rates_from_product_order($product_order);

            if (! isset($rates[$shipping_rate_id])) {
                wc_add_notice(__('El método de envío seleccionado ya no está disponible. Elegí otro método.', 'pdw'), 'error');
                return;
            }

            $selected = $rates[$shipping_rate_id];
            $shipping_order = wc_create_order();
            if (! $shipping_order instanceof WC_Order) {
                wc_add_notice(__('No se pudo crear la orden de envío. Intentá nuevamente.', 'pdw'), 'error');
                return;
            }

            $shipping_order->set_address($product_order->get_address('billing'), 'billing');
            $shipping_order->set_address($product_order->get_address('shipping'), 'shipping');

            $fee = new WC_Order_Item_Fee();
            $fee->set_name(sprintf(__('Envío: %s', 'pdw'), $selected['label']));
            $fee->set_total((float) $selected['cost']);
            $shipping_order->add_item($fee);
            $shipping_order->calculate_totals();

            $shipping_order->update_meta_data('_pdw_role', 'shipping');
            $shipping_order->update_meta_data('_pdw_parent_order_id', $product_order->get_id());
            $shipping_order->save();

            $product_order->update_meta_data('_pdw_shipping_order_id', $shipping_order->get_id());
            $product_order->update_meta_data('_pdw_shipping_method_id', $shipping_rate_id);
            $product_order->update_meta_data('_pdw_shipping_method_label', $selected['label']);
            $product_order->update_meta_data('_pdw_shipping_amount', (float) $selected['cost']);
            $product_order->update_meta_data('_pdw_shipping_payment_status', 'pending');
            $product_order->save();

            wp_safe_redirect($shipping_order->get_checkout_payment_url());
            exit;
        }

        private static function handle_confirm_order(): void {
            $settings = self::get_settings();
            $product_order = self::get_product_order_from_session();

            if (! $product_order instanceof WC_Order) {
                return;
            }

            if ('yes' === (string) $product_order->get_meta('_pdw_finalized')) {
                return;
            }

            $products_status = (string) $product_order->get_meta('_pdw_products_payment_status');
            $shipping_status = (string) $product_order->get_meta('_pdw_shipping_payment_status');

            if ('paid' !== $products_status) {
                return;
            }

            if ('paid' === $shipping_status) {
                $target_status = $product_order->needs_processing() ? 'processing' : 'completed';
                $product_order->update_status($target_status, __('Pago de productos y envío confirmado en checkout dividido.', 'pdw'));
            } else {
                $product_order->update_status('on-hold', self::build_shipping_coordination_note($settings['contact_text']));
            }

            $product_order->update_meta_data('_pdw_finalized', 'yes');
            $product_order->save();

            WC()->cart->empty_cart();
        }

        public static function filter_gateways_by_order_role(array $gateways): array {
            if (! is_checkout_pay_page()) {
                return $gateways;
            }

            $order_id = absint(get_query_var('order-pay'));
            if (! $order_id) {
                return $gateways;
            }

            $settings = self::get_settings();
            $shipping_gateway_id = (string) $settings['shipping_gateway_id'];
            $order = wc_get_order($order_id);

            if (! $order instanceof WC_Order || '' === $shipping_gateway_id) {
                return $gateways;
            }

            $role = (string) $order->get_meta('_pdw_role');

            if ('products' === $role && isset($gateways[$shipping_gateway_id])) {
                unset($gateways[$shipping_gateway_id]);
            }

            if ('shipping' === $role) {
                foreach ($gateways as $id => $gateway) {
                    if ($id !== $shipping_gateway_id) {
                        unset($gateways[$id]);
                    }
                }
            }

            return $gateways;
        }

        public static function handle_payment_complete(int $order_id): void {
            $order = wc_get_order($order_id);
            if (! $order instanceof WC_Order) {
                return;
            }

            $role = (string) $order->get_meta('_pdw_role');

            if ('products' === $role) {
                $order->update_meta_data('_pdw_products_payment_status', 'paid');
                $order->save();
                return;
            }

            if ('shipping' === $role) {
                $parent_order_id = (int) $order->get_meta('_pdw_parent_order_id');
                $parent_order = $parent_order_id ? wc_get_order($parent_order_id) : null;
                if (! $parent_order instanceof WC_Order) {
                    return;
                }

                $parent_order->update_meta_data('_pdw_shipping_payment_status', 'paid');
                $parent_order->add_order_note(__('Pago de envío acreditado en cuenta separada.', 'pdw'));
                $parent_order->save();
            }
        }

        public static function handle_payment_failed(int $order_id): void {
            $settings = self::get_settings();
            $order = wc_get_order($order_id);
            if (! $order instanceof WC_Order) {
                return;
            }

            $role = (string) $order->get_meta('_pdw_role');

            if ('products' === $role) {
                $order->update_meta_data('_pdw_products_payment_status', 'failed');
                $order->save();
                return;
            }

            if ('shipping' === $role) {
                $parent_order_id = (int) $order->get_meta('_pdw_parent_order_id');
                $parent_order = $parent_order_id ? wc_get_order($parent_order_id) : null;
                if (! $parent_order instanceof WC_Order) {
                    return;
                }

                $parent_order->update_meta_data('_pdw_shipping_payment_status', 'failed');
                $parent_order->update_status('on-hold', self::build_shipping_coordination_note($settings['contact_text']));
                $parent_order->add_order_note(__('Pago de envío fallido. Coordinar envío con cliente.', 'pdw'));
                $parent_order->save();
            }
        }

        private static function build_shipping_coordination_note(string $contact_text): string {
            return __('Coordinar envío', 'pdw') . ': ' . $contact_text;
        }

        private static function get_product_order_from_session(): ?WC_Order {
            if (! function_exists('WC') || ! WC()->session) {
                return null;
            }

            $order_id = (int) WC()->session->get(self::SESSION_PRODUCT_ORDER);
            if (! $order_id) {
                return null;
            }

            $order = wc_get_order($order_id);
            return $order instanceof WC_Order ? $order : null;
        }

        private static function get_shipping_rates_from_product_order(WC_Order $product_order): array {
            if (! function_exists('WC') || ! WC()->shipping()) {
                return [];
            }

            $packages = [[
                'contents' => [],
                'contents_cost' => 0,
                'applied_coupons' => [],
                'user' => ['ID' => (int) $product_order->get_customer_id()],
                'destination' => [
                    'country' => $product_order->get_shipping_country(),
                    'state' => $product_order->get_shipping_state(),
                    'postcode' => $product_order->get_shipping_postcode(),
                    'city' => $product_order->get_shipping_city(),
                    'address' => $product_order->get_shipping_address_1(),
                    'address_2' => $product_order->get_shipping_address_2(),
                ],
            ]];

            foreach ($product_order->get_items() as $item) {
                $product = $item->get_product();
                if (! $product) {
                    continue;
                }

                $packages[0]['contents'][] = [
                    'data' => $product,
                    'quantity' => $item->get_quantity(),
                    'line_total' => $item->get_total(),
                ];

                $packages[0]['contents_cost'] += (float) $item->get_total();
            }

            WC()->shipping()->calculate_shipping($packages);
            $rates = [];

            if (! isset($packages[0]['rates']) || empty($packages[0]['rates'])) {
                return [];
            }

            foreach ($packages[0]['rates'] as $rate_id => $rate) {
                $rates[$rate_id] = [
                    'label' => $rate->get_label(),
                    'cost' => (float) $rate->get_cost(),
                ];
            }

            return $rates;
        }
    }

    PDW_Split_Checkout_Plugin::init();
}
