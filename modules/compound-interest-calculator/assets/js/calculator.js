/**
 * Calculadora de Interés Compuesto — cálculo + gráfica (canvas, sin dependencias).
 */
(function () {
    'use strict';

    var LOCALE = 'es-ES';

    function formatMoney( value, currency ) {
        return new Intl.NumberFormat( LOCALE, { maximumFractionDigits: 0 } ).format( Math.round( value ) ) + ' ' + currency;
    }

    function formatMoneyShort( value, currency ) {
        var abs = Math.abs( value );
        if ( abs >= 1000000 ) {
            return new Intl.NumberFormat( LOCALE, { maximumFractionDigits: 1 } ).format( value / 1000000 ) + 'M ' + currency;
        }
        if ( abs >= 1000 ) {
            return new Intl.NumberFormat( LOCALE, { maximumFractionDigits: 1 } ).format( value / 1000 ) + 'k ' + currency;
        }
        return Math.round( value ) + ' ' + currency;
    }

    /** Simula mes a mes y devuelve un punto por año (0..years). */
    function computeSeries( initial, monthly, annualRatePct, years ) {
        var monthlyRate = Math.pow( 1 + annualRatePct / 100, 1 / 12 ) - 1;
        var months = years * 12;

        var compoundByMonth = new Array( months + 1 );
        var simpleByMonth = new Array( months + 1 );
        compoundByMonth[ 0 ] = initial;
        simpleByMonth[ 0 ] = initial;

        for ( var m = 1; m <= months; m++ ) {
            compoundByMonth[ m ] = compoundByMonth[ m - 1 ] * ( 1 + monthlyRate ) + monthly;
            simpleByMonth[ m ] = simpleByMonth[ m - 1 ] + monthly;
        }

        var labels = [];
        var compound = [];
        var simple = [];
        for ( var y = 0; y <= years; y++ ) {
            labels.push( y );
            compound.push( compoundByMonth[ y * 12 ] );
            simple.push( simpleByMonth[ y * 12 ] );
        }

        return { labels: labels, compound: compound, simple: simple };
    }

    function niceStep( maxValue, targetTicks ) {
        if ( maxValue <= 0 ) return 1;
        var raw = maxValue / targetTicks;
        var magnitude = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
        var residual = raw / magnitude;
        var step;
        if ( residual > 5 ) step = 10 * magnitude;
        else if ( residual > 2 ) step = 5 * magnitude;
        else if ( residual > 1 ) step = 2 * magnitude;
        else step = magnitude;
        return step;
    }

    function CicChart( canvas, colors ) {
        this.canvas = canvas;
        this.colors = colors;
        this.ctx = canvas.getContext( '2d' );
        this.series = null;
        this.currency = '€';
        this.hoverIndex = null;
        this.plot = null; // área de trazado calculada en cada render

        this.onResize = this.render.bind( this );
        window.addEventListener( 'resize', this.onResize );
    }

    CicChart.prototype.setData = function ( series, currency ) {
        this.series = series;
        this.currency = currency;
        this.render();
    };

    CicChart.prototype.destroy = function () {
        window.removeEventListener( 'resize', this.onResize );
    };

    CicChart.prototype.render = function () {
        if ( ! this.series ) return;

        var canvas = this.canvas;
        var ctx = this.ctx;
        var rect = canvas.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;

        canvas.width = Math.max( 1, Math.round( rect.width * dpr ) );
        canvas.height = Math.max( 1, Math.round( rect.height * dpr ) );
        ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );

        var W = rect.width;
        var H = rect.height;

        var padLeft = 56;
        var padRight = 12;
        var padTop = 12;
        var padBottom = 28;

        var plotW = Math.max( 10, W - padLeft - padRight );
        var plotH = Math.max( 10, H - padTop - padBottom );

        var series = this.series;
        var maxVal = 0;
        for ( var i = 0; i < series.compound.length; i++ ) {
            maxVal = Math.max( maxVal, series.compound[ i ], series.simple[ i ] );
        }
        maxVal = maxVal <= 0 ? 1 : maxVal;

        var step = niceStep( maxVal, 5 );
        var axisMax = Math.ceil( maxVal / step ) * step;

        var years = series.labels;
        var lastIndex = years.length - 1;

        function xFor( index ) {
            return padLeft + ( lastIndex === 0 ? 0 : ( index / lastIndex ) * plotW );
        }
        function yFor( value ) {
            return padTop + plotH - ( value / axisMax ) * plotH;
        }

        this.plot = { padLeft: padLeft, padTop: padTop, plotW: plotW, plotH: plotH, axisMax: axisMax, xFor: xFor, yFor: yFor };

        ctx.clearRect( 0, 0, W, H );

        // ── Gridlines + etiquetas del eje Y ─────────────────────────────
        ctx.strokeStyle = this.colors.grid;
        ctx.fillStyle = this.colors.textMuted;
        ctx.font = '11px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'right';

        var currency = this.currency;
        for ( var g = 0; g <= axisMax; g += step ) {
            var gy = yFor( g );
            ctx.beginPath();
            ctx.lineWidth = 1;
            ctx.moveTo( padLeft, gy );
            ctx.lineTo( padLeft + plotW, gy );
            ctx.stroke();
            ctx.fillText( formatMoneyShort( g, currency ), padLeft - 8, gy );
        }

        // ── Etiquetas del eje X (años, con salto si hay muchas) ─────────
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        var maxLabels = Math.max( 2, Math.floor( plotW / 55 ) );
        var labelStep = Math.max( 1, Math.ceil( lastIndex / maxLabels ) );
        for ( var idx = 0; idx <= lastIndex; idx += labelStep ) {
            ctx.fillText( years[ idx ] + ( years[ idx ] === 1 ? ' año' : ' años' ), xFor( idx ), padTop + plotH + 8 );
        }
        if ( ( lastIndex % labelStep ) !== 0 ) {
            ctx.fillText( years[ lastIndex ] + ' años', xFor( lastIndex ), padTop + plotH + 8 );
        }

        // ── Líneas de las dos series ─────────────────────────────────────
        this.drawLine( series.simple, this.colors.simple );
        this.drawLine( series.compound, this.colors.compound );

        // ── Crosshair + puntos al hacer hover ────────────────────────────
        if ( this.hoverIndex !== null && this.hoverIndex >= 0 && this.hoverIndex <= lastIndex ) {
            var hx = xFor( this.hoverIndex );

            ctx.beginPath();
            ctx.strokeStyle = this.colors.grid;
            ctx.setLineDash( [ 4, 4 ] );
            ctx.lineWidth = 1;
            ctx.moveTo( hx, padTop );
            ctx.lineTo( hx, padTop + plotH );
            ctx.stroke();
            ctx.setLineDash( [] );

            this.drawDot( hx, yFor( series.compound[ this.hoverIndex ] ), this.colors.compound );
            this.drawDot( hx, yFor( series.simple[ this.hoverIndex ] ), this.colors.simple );
        }
    };

    CicChart.prototype.drawLine = function ( values, color ) {
        var ctx = this.ctx;
        var plot = this.plot;

        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';

        for ( var i = 0; i < values.length; i++ ) {
            var x = plot.xFor( i );
            var y = plot.yFor( values[ i ] );
            if ( i === 0 ) ctx.moveTo( x, y );
            else ctx.lineTo( x, y );
        }
        ctx.stroke();
    };

    CicChart.prototype.drawDot = function ( x, y, color ) {
        var ctx = this.ctx;
        ctx.beginPath();
        ctx.fillStyle = color;
        ctx.arc( x, y, 4, 0, Math.PI * 2 );
        ctx.fill();
        ctx.beginPath();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1.5;
        ctx.arc( x, y, 4, 0, Math.PI * 2 );
        ctx.stroke();
    };

    /** Índice de año más cercano a una posición X del mouse (relativa al canvas). */
    CicChart.prototype.indexFromX = function ( mouseX ) {
        if ( ! this.plot || ! this.series ) return null;
        var plot = this.plot;
        var lastIndex = this.series.labels.length - 1;
        if ( lastIndex <= 0 ) return 0;
        var ratio = ( mouseX - plot.padLeft ) / plot.plotW;
        ratio = Math.min( 1, Math.max( 0, ratio ) );
        return Math.round( ratio * lastIndex );
    };

    function initWidget( root ) {
        var currency = root.getAttribute( 'data-currency' ) || '€';
        var defaults = {
            initial: parseFloat( root.getAttribute( 'data-initial' ) ) || 0,
            monthly: parseFloat( root.getAttribute( 'data-monthly' ) ) || 0,
            rate: parseFloat( root.getAttribute( 'data-rate' ) ) || 0,
            years: parseInt( root.getAttribute( 'data-years' ), 10 ) || 1,
        };

        var inputs = {
            initial: root.querySelector( '[data-role="initial"]' ),
            monthly: root.querySelector( '[data-role="monthly"]' ),
            rate: root.querySelector( '[data-role="rate"]' ),
            years: root.querySelector( '[data-role="years"]' ),
        };
        var outputs = {
            initial: root.querySelector( '[data-out="initial"]' ),
            monthly: root.querySelector( '[data-out="monthly"]' ),
            rate: root.querySelector( '[data-out="rate"]' ),
            years: root.querySelector( '[data-out="years"]' ),
        };
        var stats = {
            contributed: root.querySelector( '[data-stat="contributed"]' ),
            interest: root.querySelector( '[data-stat="interest"]' ),
            finalCompound: root.querySelector( '[data-stat="final-compound"]' ),
            finalSimple: root.querySelector( '[data-stat="final-simple"]' ),
        };
        var riskButtons = root.querySelectorAll( '.mad-cic__risk-btn' );
        var tableToggle = root.querySelector( '.mad-cic__table-toggle' );
        var tableWrap = root.querySelector( '.mad-cic__table-wrap' );
        var tableBody = root.querySelector( '[data-table-body]' );
        var canvas = root.querySelector( '.mad-cic__canvas' );
        var tooltip = root.querySelector( '.mad-cic__tooltip' );
        var canvasHolder = root.querySelector( '.mad-cic__canvas-holder' );

        inputs.initial.value = defaults.initial;
        inputs.monthly.value = defaults.monthly;
        inputs.rate.value = defaults.rate;
        inputs.years.value = defaults.years;

        var styles = getComputedStyle( root );
        var colors = {
            compound: styles.getPropertyValue( '--mad-cic-compound' ).trim() || '#2a78d6',
            simple: styles.getPropertyValue( '--mad-cic-simple' ).trim() || '#eb6834',
            grid: styles.getPropertyValue( '--mad-cic-grid' ).trim() || '#d9d7d0',
            textMuted: styles.getPropertyValue( '--mad-cic-text-muted' ).trim() || '#52514e',
        };

        var chart = new CicChart( canvas, colors );

        function currentValues() {
            return {
                initial: parseFloat( inputs.initial.value ) || 0,
                monthly: parseFloat( inputs.monthly.value ) || 0,
                rate: parseFloat( inputs.rate.value ) || 0,
                years: parseInt( inputs.years.value, 10 ) || 1,
            };
        }

        function updateRiskButtons( rate ) {
            riskButtons.forEach( function ( btn ) {
                var pressed = parseFloat( btn.getAttribute( 'data-rate' ) ) === rate;
                btn.setAttribute( 'aria-pressed', pressed ? 'true' : 'false' );
            } );
        }

        function renderTable( series ) {
            if ( ! tableBody ) return;
            var rows = '';
            for ( var i = 0; i < series.labels.length; i++ ) {
                rows += '<tr><td>' + series.labels[ i ] + '</td><td>' +
                    formatMoney( series.compound[ i ], currency ) + '</td><td>' +
                    formatMoney( series.simple[ i ], currency ) + '</td></tr>';
            }
            tableBody.innerHTML = rows;
        }

        function recalc() {
            var v = currentValues();

            outputs.initial.textContent = formatMoney( v.initial, currency );
            outputs.monthly.textContent = formatMoney( v.monthly, currency );
            outputs.rate.textContent = v.rate + ' %';
            outputs.years.textContent = v.years + ( v.years === 1 ? ' año' : ' años' );
            updateRiskButtons( v.rate );

            var series = computeSeries( v.initial, v.monthly, v.rate, v.years );

            var contributed = v.initial + v.monthly * 12 * v.years;
            var finalCompound = series.compound[ series.compound.length - 1 ];
            var finalSimple = series.simple[ series.simple.length - 1 ];
            var interest = finalCompound - contributed;

            stats.contributed.textContent = formatMoney( contributed, currency );
            stats.interest.textContent = formatMoney( interest, currency );
            stats.finalCompound.textContent = formatMoney( finalCompound, currency );
            stats.finalSimple.textContent = formatMoney( finalSimple, currency );

            chart.hoverIndex = null;
            chart.setData( series, currency );
            renderTable( series );
        }

        [ inputs.initial, inputs.monthly, inputs.rate, inputs.years ].forEach( function ( input ) {
            input.addEventListener( 'input', recalc );
        } );

        riskButtons.forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                inputs.rate.value = btn.getAttribute( 'data-rate' );
                recalc();
            } );
        } );

        if ( tableToggle && tableWrap ) {
            tableToggle.addEventListener( 'click', function () {
                var hidden = tableWrap.hasAttribute( 'hidden' );
                if ( hidden ) {
                    tableWrap.removeAttribute( 'hidden' );
                    tableToggle.setAttribute( 'aria-expanded', 'true' );
                    tableToggle.textContent = tableToggle.getAttribute( 'data-label-hide' ) || 'Ocultar tabla de datos';
                } else {
                    tableWrap.setAttribute( 'hidden', '' );
                    tableToggle.setAttribute( 'aria-expanded', 'false' );
                    tableToggle.textContent = tableToggle.getAttribute( 'data-label-show' ) || 'Ver tabla de datos';
                }
            } );
            tableToggle.setAttribute( 'data-label-show', tableToggle.textContent );
            tableToggle.setAttribute( 'data-label-hide', 'Ocultar tabla de datos' );
        }

        canvas.addEventListener( 'mousemove', function ( e ) {
            var rect = canvas.getBoundingClientRect();
            var mouseX = e.clientX - rect.left;
            var index = chart.indexFromX( mouseX );
            if ( index === null || ! chart.series ) return;

            chart.hoverIndex = index;
            chart.render();

            var series = chart.series;
            var year = series.labels[ index ];
            tooltip.innerHTML =
                '<div>' + ( year === 0 ? 'Inicio' : year + ( year === 1 ? ' año' : ' años' ) ) + '</div>' +
                '<div style="color:' + colors.compound + ';">● Interés compuesto: <strong>' + formatMoney( series.compound[ index ], currency ) + '</strong></div>' +
                '<div style="color:' + colors.simple + ';">● Sin invertir: <strong>' + formatMoney( series.simple[ index ], currency ) + '</strong></div>';

            var holderRect = canvasHolder.getBoundingClientRect();
            var left = e.clientX - holderRect.left;
            var top = e.clientY - holderRect.top;
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
            tooltip.hidden = false;
        } );

        canvas.addEventListener( 'mouseleave', function () {
            chart.hoverIndex = null;
            chart.render();
            tooltip.hidden = true;
        } );

        recalc();
    }

    function init() {
        var widgets = document.querySelectorAll( '.mad-cic' );
        widgets.forEach( initWidget );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})();
