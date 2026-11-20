<?php
/**
 * Generador de PDF propio para la proforma y la factura — sin depender de
 * ningún plugin de terceros (decisión de @Jessica tras el documento
 * "Cambios tras la prueba en la web": "no queremos depender de ese
 * plugin, vamos a hacerlo nosotros"). Usa FPDF (vendorizado en
 * vendor/fpdf/, licencia libre, sin Composer) directamente.
 *
 * FPDF trabaja con las fuentes núcleo (Helvetica) en cp1252 — por eso
 * TODO texto variable pasa por mb_convert_encoding() antes de escribirse
 * (ver txt()). Sin esto, los acentos (é, ñ, à…) saldrían corruptos.
 *
 * @package MAD_Suite/Proforma
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once MAD_PROFORMA_DIR . 'vendor/fpdf/fpdf.php';

class MAD_Invoice_PDF extends FPDF {

    private const MARGIN = 15;

    /** @var array Ajustes de empresa: name, vat, address, logo_url, legal_footer. */
    private $company;

    public function __construct( array $company ) {
        parent::__construct( 'P', 'mm', 'A4' );
        $this->company = $company;
        $this->SetMargins( self::MARGIN, self::MARGIN, self::MARGIN );
        $this->SetAutoPageBreak( true, 25 );
    }

    /** cp1252 es lo único que las fuentes núcleo de FPDF saben imprimir sin incrustar una fuente TrueType — ver cabecera del archivo. */
    private function txt( string $s ): string {
        $converted = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return false !== $converted ? $converted : $s;
    }

    /**
     * @param WC_Order $order
     * @param string   $type   'proforma'|'invoice'
     * @param string   $number Ya resuelto por el llamador (PRO-xxx o la numeración fiscal).
     * @param int      $until  Solo proforma: timestamp límite de pago (0 = no se muestra).
     * @return string PDF como string binario (Output('S')).
     */
    public function render( WC_Order $order, string $type, string $number, int $until = 0 ): string {
        $this->AddPage();
        $this->header_block( $type, $number, $order );
        $this->parties_block( $order );
        $this->items_table( $order );
        $this->totals_block( $order );

        if ( 'proforma' === $type ) {
            $this->payment_block( $order, $until );
        }

        $this->footer_block();

        return $this->Output( 'S' );
    }

    private function header_block( string $type, string $number, WC_Order $order ): void {
        $logo = (string) ( $this->company['logo_url'] ?? '' );
        $logo_path = $logo ? $this->local_path_for_url( $logo ) : '';

        if ( $logo_path && file_exists( $logo_path ) ) {
            $ext = strtolower( pathinfo( $logo_path, PATHINFO_EXTENSION ) );
            if ( in_array( $ext, [ 'png', 'jpg', 'jpeg' ], true ) ) {
                $this->Image( $logo_path, self::MARGIN, self::MARGIN, 0, 20 );
            }
        }

        $this->SetFont( 'Helvetica', 'B', 16 );
        $this->SetXY( self::MARGIN, self::MARGIN + ( $logo_path ? 24 : 0 ) );
        $title = 'invoice' === $type ? __( 'Factura', 'mad-suite' ) : __( 'Factura proforma', 'mad-suite' );
        $this->Cell( 0, 8, $this->txt( $title ), 0, 1 );

        $this->SetFont( 'Helvetica', '', 10 );
        $this->Cell( 0, 6, $this->txt( ( 'invoice' === $type ? __( 'Nº factura: ', 'mad-suite' ) : __( 'Nº proforma: ', 'mad-suite' ) ) . $number ), 0, 1 );
        $this->Cell( 0, 6, $this->txt( __( 'Fecha: ', 'mad-suite' ) . date_i18n( 'd/m/Y', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ) ), 0, 1 );
        $this->Ln( 4 );
    }

    private function parties_block( WC_Order $order ): void {
        $y0 = $this->GetY();
        $col_w = ( 210 - 2 * self::MARGIN ) / 2;

        $this->SetFont( 'Helvetica', 'B', 10 );
        $this->SetXY( self::MARGIN, $y0 );
        $this->Cell( $col_w, 6, $this->txt( __( 'De', 'mad-suite' ) ), 0, 2 );
        $this->SetFont( 'Helvetica', '', 9 );
        $this->MultiCell( $col_w, 5, $this->txt( $this->company_block_text() ) );

        $y1 = $this->GetY();

        $this->SetFont( 'Helvetica', 'B', 10 );
        $this->SetXY( self::MARGIN + $col_w, $y0 );
        $this->Cell( $col_w, 6, $this->txt( __( 'Para', 'mad-suite' ) ), 0, 2 );
        $this->SetX( self::MARGIN + $col_w );
        $this->SetFont( 'Helvetica', '', 9 );
        $this->MultiCell( $col_w, 5, $this->txt( $this->customer_block_text( $order ) ) );

        $this->SetY( max( $y1, $this->GetY() ) + 4 );
    }

    private function company_block_text(): string {
        $lines = array_filter( [
            (string) ( $this->company['name'] ?? '' ),
            (string) ( $this->company['vat'] ?? '' ),
            (string) ( $this->company['address'] ?? '' ),
        ] );
        return implode( "\n", $lines );
    }

    private function customer_block_text( WC_Order $order ): string {
        $name = trim( $order->get_billing_company() ?: $order->get_formatted_billing_full_name() );
        $vat  = (string) $order->get_meta( '_billing_vat' );

        $address_parts = array_filter( [
            $order->get_billing_address_1(),
            trim( $order->get_billing_postcode() . ' ' . $order->get_billing_city() ),
            $order->get_billing_state(),
            $order->get_billing_country() ? ( WC()->countries->countries[ $order->get_billing_country() ] ?? $order->get_billing_country() ) : '',
        ] );

        $lines = array_filter( array_merge(
            [ $name ],
            $vat ? [ $vat ] : [],
            $address_parts,
            [ $order->get_billing_email() ]
        ) );

        return implode( "\n", $lines );
    }

    private function items_table( WC_Order $order ): void {
        $this->SetFont( 'Helvetica', 'B', 9 );
        $this->SetFillColor( 240, 240, 240 );

        $widths = [ 90, 18, 28, 20, 24 ]; // Pieza, Cant., Precio, IVA, Importe.
        $heads  = [ __( 'Pieza', 'mad-suite' ), __( 'Cant.', 'mad-suite' ), __( 'Precio', 'mad-suite' ), __( 'IVA', 'mad-suite' ), __( 'Importe', 'mad-suite' ) ];

        foreach ( $heads as $i => $head ) {
            $this->Cell( $widths[ $i ], 7, $this->txt( $head ), 1, 0, $i > 0 ? 'R' : 'L', true );
        }
        $this->Ln();

        $this->SetFont( 'Helvetica', '', 9 );
        foreach ( $order->get_items() as $item ) {
            $qty       = $item->get_quantity();
            $line_total= (float) $item->get_total();
            $line_tax  = (float) $item->get_total_tax();
            $unit      = $qty ? ( $line_total / $qty ) : $line_total;
            $tax_rate  = $line_total > 0 ? round( $line_tax / $line_total * 100 ) : 0;

            $this->Cell( $widths[0], 7, $this->txt( $item->get_name() ), 1, 0, 'L' );
            $this->Cell( $widths[1], 7, (string) $qty, 1, 0, 'R' );
            $this->Cell( $widths[2], 7, $this->txt( $this->money( $unit, $order ) ), 1, 0, 'R' );
            $this->Cell( $widths[3], 7, $tax_rate . '%', 1, 0, 'R' );
            $this->Cell( $widths[4], 7, $this->txt( $this->money( $line_total + $line_tax, $order ) ), 1, 0, 'R' );
            $this->Ln();
        }
        $this->Ln( 4 );
    }

    private function totals_block( WC_Order $order ): void {
        $base  = (float) $order->get_subtotal() - (float) $order->get_total_discount();
        $tax   = (float) $order->get_total_tax();
        $total = (float) $order->get_total();

        $label_w = 50;
        $value_w = 30;
        $x       = 210 - self::MARGIN - $label_w - $value_w;

        foreach ( [
            [ __( 'Base imponible', 'mad-suite' ), $base ],
            [ __( 'IVA', 'mad-suite' ), $tax ],
        ] as $row ) {
            $this->SetX( $x );
            $this->SetFont( 'Helvetica', '', 9 );
            $this->Cell( $label_w, 6, $this->txt( $row[0] ), 0, 0, 'R' );
            $this->Cell( $value_w, 6, $this->txt( $this->money( $row[1], $order ) ), 0, 1, 'R' );
        }

        $this->SetX( $x );
        $this->SetFont( 'Helvetica', 'B', 10 );
        $this->Cell( $label_w, 7, $this->txt( __( 'Total', 'mad-suite' ) ), 'T', 0, 'R' );
        $this->Cell( $value_w, 7, $this->txt( $this->money( $total, $order ) ), 'T', 1, 'R' );
        $this->Ln( 4 );
    }

    private function payment_block( WC_Order $order, int $until ): void {
        $this->SetFont( 'Helvetica', 'B', 10 );
        $this->Cell( 0, 7, $this->txt( __( 'Datos para la transferencia', 'mad-suite' ) ), 0, 1 );
        $this->SetFont( 'Helvetica', '', 9 );

        if ( function_exists( 'mad_quotes_bacs_account_details' ) ) {
            $fields = [
                'account_name'   => __( 'Titular', 'mad-suite' ),
                'bank_name'      => __( 'Banco', 'mad-suite' ),
                'account_number' => __( 'Cuenta', 'mad-suite' ),
                'iban'           => __( 'IBAN', 'mad-suite' ),
                'bic'            => __( 'BIC/SWIFT', 'mad-suite' ),
            ];
            foreach ( mad_quotes_bacs_account_details() as $account ) {
                foreach ( $fields as $key => $label ) {
                    if ( empty( $account[ $key ] ) ) continue;
                    $this->Cell( 0, 5, $this->txt( $label . ': ' . $account[ $key ] ), 0, 1 );
                }
            }
        }

        $this->Cell( 0, 5, $this->txt( sprintf(
            /* translators: %s: número de pedido */
            __( 'Concepto: Pedido #%s', 'mad-suite' ),
            $order->get_order_number()
        ) ), 0, 1 );

        if ( $until > 0 && function_exists( 'mad_quotes_madrid_time' ) ) {
            $this->Ln( 2 );
            $this->SetFont( 'Helvetica', 'B', 9 );
            $this->Cell( 0, 5, $this->txt( sprintf(
                /* translators: %s: hora límite, hora de Madrid */
                __( 'Plazo para completar el pago: %s (hora de Madrid)', 'mad-suite' ),
                mad_quotes_madrid_time( $until )
            ) ), 0, 1 );
        }
        $this->Ln( 4 );
    }

    private function footer_block(): void {
        $legal = (string) ( $this->company['legal_footer'] ?? '' );
        if ( '' === trim( $legal ) ) return;

        $this->SetY( -25 );
        $this->SetFont( 'Helvetica', '', 7 );
        $this->SetTextColor( 120, 120, 120 );
        $this->MultiCell( 0, 4, $this->txt( $legal ), 0, 'C' );
    }

    private function money( float $amount, WC_Order $order ): string {
        return number_format( $amount, 2, ',', '.' ) . ' ' . $order->get_currency();
    }

    /** Resuelve una URL de medios de WP a su ruta local en disco, si vive en este sitio — FPDF no sabe leer imágenes por URL remota. */
    private function local_path_for_url( string $url ): string {
        $upload_dir = wp_upload_dir();
        if ( 0 === strpos( $url, $upload_dir['baseurl'] ) ) {
            return str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
        }
        $site_url = site_url();
        if ( 0 === strpos( $url, $site_url ) ) {
            return ABSPATH . str_replace( $site_url . '/', '', $url );
        }
        return '';
    }
}
