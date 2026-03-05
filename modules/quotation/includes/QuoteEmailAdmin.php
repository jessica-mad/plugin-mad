<?php
/**
 * QuoteEmailAdmin — Email WooCommerce informativo al administrador cuando llega
 * una nueva solicitud de cotización. NO incluye precios.
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

class QuoteEmailAdmin extends \WC_Email {

    public function __construct() {
        $this->id             = 'mad_quote_request';
        $this->title          = __('Solicitud de cotización (Admin)', 'mad-suite');
        $this->description    = __('Email que recibe el administrador cuando un cliente envía una solicitud de cotización.', 'mad-suite');
        $this->template_html  = ''; // usaremos get_content_html directamente
        $this->template_plain = '';
        $this->placeholders   = [];

        // Disparadores
        add_action( 'mad_quote_request_notification', [ $this, 'trigger' ], 10, 1 );

        parent::__construct();

        // Destinatario: email admin (configurable en ajustes del módulo)
        $this->recipient = $this->get_option( 'recipient', get_option('admin_email') );
    }

    public function trigger( int $order_id ) {
        $this->setup_locale();

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->restore_locale();
            return;
        }

        $this->object        = $order;
        $this->recipient     = apply_filters( 'mad_quote_admin_email_recipient', get_option('admin_email'), $order );
        $this->find['order-date']   = '{order_date}';
        $this->replace['order-date'] = wc_format_datetime( $order->get_date_created() );
        $this->find['order-number']  = '{order_number}';
        $this->replace['order-number'] = $order->get_order_number();

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            $this->restore_locale();
            return;
        }

        $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        $this->restore_locale();
    }

    public function get_default_subject(): string {
        return __('[{site_title}] Nueva solicitud de cotización — Pedido #{order_number}', 'mad-suite');
    }

    public function get_default_heading(): string {
        return __('Nueva solicitud de cotización', 'mad-suite');
    }

    public function get_content_html(): string {
        ob_start();
        include dirname( __DIR__ ) . '/views/email-admin-quote.php';
        return ob_get_clean();
    }

    public function get_content_plain(): string {
        $order = $this->object;
        if ( ! $order ) return '';

        $out  = __('Nueva solicitud de cotización', 'mad-suite') . "\n\n";
        $out .= sprintf( __('Pedido: #%s', 'mad-suite'), $order->get_order_number() ) . "\n";
        $out .= sprintf( __('Cliente: %s', 'mad-suite'), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . "\n";
        $out .= sprintf( __('Email: %s', 'mad-suite'), $order->get_billing_email() ) . "\n\n";
        $out .= __('Productos solicitados:', 'mad-suite') . "\n";
        foreach ( $order->get_items() as $item ) {
            $out .= '- ' . $item->get_name() . ' x' . $item->get_quantity() . "\n";
        }
        return $out;
    }

    public function get_content(): string {
        $this->sending = true;
        $content = $this->get_content_html();
        return $content;
    }

    public function init_form_fields() {
        parent::init_form_fields(); // sets default WC fields (enabled, subject, heading, email_type)

        // Añadir campo de destinatario al principio
        $this->form_fields = array_merge(
            [
                'recipient' => [
                    'title'       => __('Destinatario', 'mad-suite'),
                    'type'        => 'text',
                    'description' => sprintf( __('Destinatario del email. Separar múltiples emails con coma. Default: %s', 'mad-suite'), '<code>' . esc_attr( get_option('admin_email') ) . '</code>' ),
                    'placeholder' => '',
                    'default'     => '',
                    'desc_tip'    => true,
                ],
            ],
            $this->form_fields
        );
    }
}
