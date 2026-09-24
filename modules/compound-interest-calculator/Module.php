<?php
/**
 * Calculadora de Interés Compuesto — widget embebible vía shortcode que
 * compara el crecimiento de una inversión a interés compuesto contra
 * simplemente ahorrar el mismo dinero sin invertir.
 *
 * @package MAD_Suite/CompoundInterestCalculator
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return new class ( $core ?? null ) implements MAD_Suite_Module {

    private const SHORTCODE = 'mad_compound_interest_calculator';

    public function __construct( $core ) {}

    public function slug()        { return 'compound-interest-calculator'; }
    public function title()       { return __( 'Calculadora de Interés Compuesto', 'mad-suite' ); }
    public function menu_label()  { return __( 'Calc. Interés Compuesto', 'mad-suite' ); }
    public function menu_slug()   { return 'mad-suite-compound-interest-calculator'; }
    public function description() { return __( 'Calculadora interactiva de interés compuesto con gráfica comparativa contra ahorro sin invertir. Se embebe con un shortcode.', 'mad-suite' ); }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function init(): void {
        add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
    }

    public function admin_init(): void {}

    // ── Assets ────────────────────────────────────────────────────────────────

    /**
     * Solo carga CSS/JS en páginas que realmente tienen el shortcode —
     * evita cargar el widget en todo el sitio sin necesidad.
     */
    public function maybe_enqueue_assets(): void {
        if ( ! is_singular() ) return;

        $post = get_post();
        if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) return;

        $this->enqueue_assets();
    }

    private function enqueue_assets(): void {
        $base_url = plugin_dir_url( __FILE__ );
        $version  = '1.0.0';

        wp_enqueue_style(
            'mad-cic-style',
            $base_url . 'assets/css/calculator.css',
            [],
            $version
        );

        wp_enqueue_script(
            'mad-cic-script',
            $base_url . 'assets/js/calculator.js',
            [],
            $version,
            true
        );
    }

    // ── Shortcode ────────────────────────────────────────────────────────────

    public function render_shortcode( $atts ): string {
        // Por si el shortcode se agrega dinámicamente (constructor de página,
        // widget, etc.) y maybe_enqueue_assets() no lo detectó en post_content.
        if ( ! wp_style_is( 'mad-cic-style', 'enqueued' ) ) {
            $this->enqueue_assets();
        }

        $atts = shortcode_atts( [
            'moneda'             => '€',
            'inicial_defecto'    => 5000,
            'aporte_defecto'     => 200,
            'rentabilidad_defecto' => 7,
            'anios_defecto'      => 20,
        ], $atts, self::SHORTCODE );

        $id = 'mad-cic-' . wp_unique_id();

        ob_start();
        ?>
        <div class="mad-cic"
             id="<?php echo esc_attr( $id ); ?>"
             data-currency="<?php echo esc_attr( $atts['moneda'] ); ?>"
             data-initial="<?php echo esc_attr( (int) $atts['inicial_defecto'] ); ?>"
             data-monthly="<?php echo esc_attr( (int) $atts['aporte_defecto'] ); ?>"
             data-rate="<?php echo esc_attr( (float) $atts['rentabilidad_defecto'] ); ?>"
             data-years="<?php echo esc_attr( (int) $atts['anios_defecto'] ); ?>">

            <div class="mad-cic__controls">

                <div class="mad-cic__field">
                    <div class="mad-cic__field-head">
                        <label for="<?php echo esc_attr( $id ); ?>-initial"><?php esc_html_e( 'Valor inicial', 'mad-suite' ); ?></label>
                        <output class="mad-cic__value" for="<?php echo esc_attr( $id ); ?>-initial" data-out="initial"></output>
                    </div>
                    <input type="range" id="<?php echo esc_attr( $id ); ?>-initial" data-role="initial"
                           min="0" max="100000" step="500">
                </div>

                <div class="mad-cic__field">
                    <div class="mad-cic__field-head">
                        <label for="<?php echo esc_attr( $id ); ?>-monthly"><?php esc_html_e( 'Aporte mensual', 'mad-suite' ); ?></label>
                        <output class="mad-cic__value" for="<?php echo esc_attr( $id ); ?>-monthly" data-out="monthly"></output>
                    </div>
                    <input type="range" id="<?php echo esc_attr( $id ); ?>-monthly" data-role="monthly"
                           min="0" max="3000" step="25">
                </div>

                <div class="mad-cic__field">
                    <div class="mad-cic__field-head">
                        <label for="<?php echo esc_attr( $id ); ?>-rate"><?php esc_html_e( 'Rentabilidad anual estimada', 'mad-suite' ); ?></label>
                        <output class="mad-cic__value" for="<?php echo esc_attr( $id ); ?>-rate" data-out="rate"></output>
                    </div>
                    <input type="range" id="<?php echo esc_attr( $id ); ?>-rate" data-role="rate"
                           min="0" max="15" step="0.5">
                    <div class="mad-cic__risk" role="group" aria-label="<?php esc_attr_e( 'Perfiles de riesgo orientativos', 'mad-suite' ); ?>">
                        <button type="button" class="mad-cic__risk-btn" data-rate="4"><?php esc_html_e( 'Conservador · 4%', 'mad-suite' ); ?></button>
                        <button type="button" class="mad-cic__risk-btn" data-rate="7"><?php esc_html_e( 'Moderado · 7%', 'mad-suite' ); ?></button>
                        <button type="button" class="mad-cic__risk-btn" data-rate="10"><?php esc_html_e( 'Agresivo · 10%', 'mad-suite' ); ?></button>
                    </div>
                    <p class="mad-cic__disclaimer"><?php esc_html_e( 'Valores orientativos de ejemplo — no son una promesa ni garantía de rentabilidad futura.', 'mad-suite' ); ?></p>
                </div>

                <div class="mad-cic__field">
                    <div class="mad-cic__field-head">
                        <label for="<?php echo esc_attr( $id ); ?>-years"><?php esc_html_e( 'Plazo', 'mad-suite' ); ?></label>
                        <output class="mad-cic__value" for="<?php echo esc_attr( $id ); ?>-years" data-out="years"></output>
                    </div>
                    <input type="range" id="<?php echo esc_attr( $id ); ?>-years" data-role="years"
                           min="1" max="40" step="1">
                </div>

            </div>

            <div class="mad-cic__results">
                <div class="mad-cic__stat">
                    <span class="mad-cic__stat-label"><?php esc_html_e( 'Total aportado', 'mad-suite' ); ?></span>
                    <span class="mad-cic__stat-value" data-stat="contributed">—</span>
                </div>
                <div class="mad-cic__stat">
                    <span class="mad-cic__stat-label"><?php esc_html_e( 'Interés generado', 'mad-suite' ); ?></span>
                    <span class="mad-cic__stat-value" data-stat="interest">—</span>
                </div>
                <div class="mad-cic__stat mad-cic__stat--highlight">
                    <span class="mad-cic__stat-label"><?php esc_html_e( 'Valor final con interés compuesto', 'mad-suite' ); ?></span>
                    <span class="mad-cic__stat-value" data-stat="final-compound">—</span>
                </div>
                <div class="mad-cic__stat">
                    <span class="mad-cic__stat-label"><?php esc_html_e( 'Valor final sin invertir', 'mad-suite' ); ?></span>
                    <span class="mad-cic__stat-value" data-stat="final-simple">—</span>
                </div>
            </div>

            <div class="mad-cic__chart-wrap">
                <div class="mad-cic__legend">
                    <span class="mad-cic__legend-item"><i class="mad-cic__swatch mad-cic__swatch--compound"></i><?php esc_html_e( 'Interés compuesto', 'mad-suite' ); ?></span>
                    <span class="mad-cic__legend-item"><i class="mad-cic__swatch mad-cic__swatch--simple"></i><?php esc_html_e( 'Ahorro sin invertir', 'mad-suite' ); ?></span>
                    <button type="button" class="mad-cic__table-toggle" aria-expanded="false"><?php esc_html_e( 'Ver tabla de datos', 'mad-suite' ); ?></button>
                </div>
                <div class="mad-cic__canvas-holder">
                    <canvas class="mad-cic__canvas" role="img"
                            aria-label="<?php esc_attr_e( 'Gráfica comparativa entre interés compuesto y ahorro sin invertir a lo largo del tiempo', 'mad-suite' ); ?>"></canvas>
                    <div class="mad-cic__tooltip" hidden></div>
                </div>
                <div class="mad-cic__table-wrap" hidden>
                    <table class="mad-cic__table">
                        <caption class="screen-reader-text"><?php esc_html_e( 'Valores año a año', 'mad-suite' ); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Año', 'mad-suite' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Interés compuesto', 'mad-suite' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Ahorro sin invertir', 'mad-suite' ); ?></th>
                            </tr>
                        </thead>
                        <tbody data-table-body></tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>
            <p><?php esc_html_e( 'Este módulo no tiene ajustes — es un widget que se embebe con un shortcode en cualquier página o entrada.', 'mad-suite' ); ?></p>

            <h2><?php esc_html_e( 'Cómo usarlo', 'mad-suite' ); ?></h2>
            <p><?php esc_html_e( 'Pegá este shortcode en el editor de cualquier página o entrada:', 'mad-suite' ); ?></p>
            <p><code>[<?php echo esc_html( self::SHORTCODE ); ?>]</code></p>

            <h3><?php esc_html_e( 'Parámetros opcionales', 'mad-suite' ); ?></h3>
            <p><?php esc_html_e( 'Todos son opcionales — si no se indican, se usan los valores por defecto que se ven abajo.', 'mad-suite' ); ?></p>
            <table class="widefat striped" style="max-width:720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Parámetro', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Qué hace', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Por defecto', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>moneda</code></td><td><?php esc_html_e( 'Símbolo de moneda mostrado', 'mad-suite' ); ?></td><td>€</td></tr>
                    <tr><td><code>inicial_defecto</code></td><td><?php esc_html_e( 'Valor inicial del slider "Valor inicial"', 'mad-suite' ); ?></td><td>5000</td></tr>
                    <tr><td><code>aporte_defecto</code></td><td><?php esc_html_e( 'Valor inicial del slider "Aporte mensual"', 'mad-suite' ); ?></td><td>200</td></tr>
                    <tr><td><code>rentabilidad_defecto</code></td><td><?php esc_html_e( 'Valor inicial del slider "Rentabilidad anual" (%)', 'mad-suite' ); ?></td><td>7</td></tr>
                    <tr><td><code>anios_defecto</code></td><td><?php esc_html_e( 'Valor inicial del slider "Plazo" (años)', 'mad-suite' ); ?></td><td>20</td></tr>
                </tbody>
            </table>
            <p class="description">
                <?php echo esc_html( '[' . self::SHORTCODE . ' inicial_defecto="10000" aporte_defecto="300" rentabilidad_defecto="8" anios_defecto="30"]' ); ?>
            </p>
        </div>
        <?php
    }
};
