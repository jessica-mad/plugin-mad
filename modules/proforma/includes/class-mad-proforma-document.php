<?php
/**
 * Tipo de documento "Factura proforma" para PDF Invoices & Packing Slips
 * for WooCommerce (plugin gratuito, slug woocommerce-pdf-invoices-packing-slips).
 *
 * Este archivo SOLO se carga (ver Module.php) cuando ya se comprobó que
 * la clase base WPO_WCPDF_Invoice existe — necesario porque en PHP una
 * declaración "class X extends Y" falla de forma fatal en el momento de
 * cargar el archivo si Y todavía no existe.
 *
 * ADVERTENCIA: el método exacto para reutilizar la plantilla de Factura
 * (get_template_file(), más abajo) depende de cómo esa versión concreta
 * del plugin resuelve sus plantillas — no se pudo verificar contra el
 * sitio en vivo. Si la proforma generada sale con un layout roto o vacío,
 * es el primer lugar a revisar.
 *
 * @package MAD_Suite/Proforma
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Proforma_Document extends WPO_WCPDF_Invoice {

    public $type = 'proforma';

    public function __construct( $order = null ) {
        $this->title = __( 'Factura proforma', 'mad-suite' );
        parent::__construct( $order );
    }

    public function get_title() {
        return $this->title;
    }

    public function get_type() {
        return $this->type;
    }

    /**
     * Una proforma solo tiene sentido mientras el pedido está a la espera
     * de pago (transferencia bancaria pendiente de comprobante). Una vez
     * pagado/completado, el documento válido es la factura real del plugin.
     */
    public function is_allowed() {
        if ( ! ( $this->order instanceof WC_Order ) ) return false;
        return $this->order->has_status( [ 'pending', 'on-hold' ] );
    }

    /** Se genera al vuelo en cada request — no depende de un número guardado antes. */
    public function exists() {
        return true;
    }

    /**
     * Numeración propia, con prefijo, independiente de la secuencia fiscal
     * de la factura real — una proforma no es un documento fiscal y no debe
     * "gastar" ni interferir con la numeración correlativa de facturas.
     */
    public function get_number() {
        return $this->order ? 'PRO-' . $this->order->get_order_number() : '';
    }

    public function get_number_plain() {
        return $this->get_number();
    }

    public function get_formatted_number() {
        return $this->get_number();
    }

    /**
     * Reutiliza la plantilla PHP de Factura (mismos datos de empresa/logo/
     * maquetación ya configurados en el plugin) en vez de mantener una
     * plantilla aparte — intercambia temporalmente el tipo a "invoice" solo
     * para la búsqueda del archivo de plantilla.
     */
    public function get_template_file() {
        $original_type = $this->type;
        $this->type    = 'invoice';
        try {
            $file = parent::get_template_file();
        } catch ( \Throwable $e ) {
            $file = false;
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'No se pudo resolver la plantilla PDF para la proforma: ' . $e->getMessage(),
                    [ 'source' => 'mad-proforma' ]
                );
            }
        }
        $this->type = $original_type;
        return $file;
    }
}
