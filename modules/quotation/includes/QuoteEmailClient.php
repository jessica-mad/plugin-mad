<?php
/**
 * QuoteEmailClient — Email WooCommerce al cliente con precios reales y enlace
 * para revisar y pagar la cotización.
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;
if ( ! class_exists('\WC_Email') ) return;

class QuoteEmailClient extends \WC_Email {

    private $module_ref;

    public function __construct( $module = null ) {
        $this->module_ref     = $module;
        $this->id             = 'mad_quote_sent';
        $this->customer_email = true;
        $this->title          = __('Cotización enviada al cliente', 'mad-suite');
        $this->description    = __('Email que recibe el cliente cuando el administrador envía la cotización con precios.', 'mad-suite');
        $this->template_html  = '';
        $this->template_plain = '';
        $this->placeholders   = [];

        // Disparador
        add_action( 'mad_quote_sent_notification', [ $this, 'trigger' ], 10, 1 );

        parent::__construct();
    }

    public function trigger( int $order_id ) {
        $this->setup_locale();

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->restore_locale();
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        $this->find['order-number']  = '{order_number}';
        $this->replace['order-number'] = $order->get_order_number();
        $this->find['order-date']    = '{order_date}';
        $this->replace['order-date'] = wc_format_datetime( $order->get_date_created() );

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            $this->restore_locale();
            return;
        }

        $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        $this->restore_locale();
    }

    public function get_default_subject(): string {
        return __('[{site_title}] Tu cotización está lista — Pedido #{order_number}', 'mad-suite');
    }

    public function get_default_heading(): string {
        return __('Tu cotización está lista', 'mad-suite');
    }

    /**
     * Genera la URL de revisión/pago para el cliente.
     */
    public function get_payment_review_url( \WC_Order $order ): string {
        $page_id = 0;
        if ( $this->module_ref ) {
            $s       = $this->module_ref->get_settings();
            $page_id = (int) ( $s['payment_page_id'] ?? 0 );
        }

        if ( $page_id ) {
            return add_query_arg( [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ], get_permalink( $page_id ) );
        }

        // Fallback: URL de pago nativa de WC
        return $order->get_checkout_payment_url();
    }

    public function get_content_html(): string {
        ob_start();
        include dirname( __DIR__ ) . '/views/email-client-quote.php';
        return ob_get_clean();
    }

    public function get_content_plain(): string {
        $order = $this->object;
        if ( ! $order ) return '';

        $out  = __('Tu cotización está lista', 'mad-suite') . "\n\n";
        $out .= sprintf( __('Pedido: #%s', 'mad-suite'), $order->get_order_number() ) . "\n\n";
        $out .= __('Productos:', 'mad-suite') . "\n";
        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $price    = $product ? wc_price( (float) $product->get_price() ) : '—';
            $out .= '- ' . $item->get_name() . ' x' . $item->get_quantity() . ' — ' . strip_tags($price) . "\n";
        }
        $out .= "\n" . __('Revisar y pagar:', 'mad-suite') . ' ' . $this->get_payment_review_url( $order ) . "\n";
        return $out;
    }

    public function get_content(): string {
        $this->sending = true;
        return $this->get_content_html();
    }

    public function init_form_fields() {
        parent::init_form_fields();
    }
}
