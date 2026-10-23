/* global Chart, madCIData */
( function () {
    document.addEventListener( 'DOMContentLoaded', function () {
        renderMonthlyChart();
        initSortableTables();
    } );

    function renderMonthlyChart() {
        var canvas = document.getElementById( 'mad-ci-monthly-chart' );
        if ( ! canvas || typeof Chart === 'undefined' || typeof madCIData === 'undefined' ) return;

        var periods       = madCIData.periods.map( function ( row ) { return row.label; } );
        var newCounts     = madCIData.periods.map( function ( row ) { return row.new_customers; } );
        var repeatCounts  = madCIData.periods.map( function ( row ) { return row.repeat_customers; } );

        var gridClr = 'rgba(0,0,0,.06)';
        var textClr = '#3c434a';

        // order más bajo = Chart.js lo dibuja ANTES = queda detrás. Las
        // barras llevan order:1 y las líneas de comparación order:2+ para
        // que siempre queden pintadas por encima, no debajo.
        var datasets = [
            {
                type: 'bar',
                label: madCIData.i18n.new,
                data: newCounts,
                backgroundColor: '#4f8cff',
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 34,
                stack: 'total',
                order: 1,
            },
            {
                type: 'bar',
                label: madCIData.i18n.repeat,
                data: repeatCounts,
                backgroundColor: '#ff7a59',
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 34,
                stack: 'total',
                order: 1,
            },
        ];

        function lineDataset( label, data, color, order ) {
            return {
                type: 'line',
                label: label,
                data: data,
                borderColor: color,
                backgroundColor: color,
                borderDash: [ 6, 4 ],
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: color,
                fill: false,
                tension: 0.25,
                spanGaps: true,
                order: order,
            };
        }

        if ( madCIData.compare ) {
            datasets.push( lineDataset( madCIData.i18n.newPrev, madCIData.compare.new, '#a9c6ff', 2 ) );
            datasets.push( lineDataset( madCIData.i18n.repeatPrev, madCIData.compare.repeat, '#ffbfa8', 3 ) );
            datasets.push( lineDataset( madCIData.i18n.totalPrev, madCIData.compare.total, '#6b7280', 4 ) );
        }

        new Chart( canvas, {
            type: 'bar',
            data: {
                labels: periods,
                datasets: datasets,
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, color: textClr, font: { size: 13 } },
                    },
                    tooltip: {
                        backgroundColor: '#1d2327',
                        padding: 10,
                        cornerRadius: 6,
                        titleFont: { weight: '600' },
                    },
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { color: textClr },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: gridClr, drawTicks: false },
                        border: { display: false },
                        ticks: { precision: 0, color: textClr },
                    },
                },
            },
        } );
    }

    /**
     * Tablas ordenables: cualquier <table class="mad-ci-sortable"> con
     * <th data-sort="num|text"> en el thead. Click alterna asc/desc.
     * Usa el atributo data-value del <td> si existe (para columnas con
     * formato, moneda o fechas) y, si no, el texto visible.
     */
    function initSortableTables() {
        document.querySelectorAll( '.mad-ci-sortable' ).forEach( function ( table ) {
            var headers = table.querySelectorAll( 'thead th[data-sort]' );
            headers.forEach( function ( th, index ) {
                th.classList.add( 'mad-ci-sort-header' );
                th.addEventListener( 'click', function () {
                    var dir = th.getAttribute( 'data-dir' ) === 'asc' ? 'desc' : 'asc';
                    headers.forEach( function ( h ) { h.removeAttribute( 'data-dir' ); } );
                    th.setAttribute( 'data-dir', dir );
                    sortTableByColumn( table, index, th.getAttribute( 'data-sort' ), dir );
                } );
            } );
        } );
    }

    function sortTableByColumn( table, columnIndex, type, dir ) {
        var tbody = table.querySelector( 'tbody' );
        if ( ! tbody ) return;
        var rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) );

        rows.sort( function ( rowA, rowB ) {
            var a = cellValue( rowA, columnIndex, type );
            var b = cellValue( rowB, columnIndex, type );
            var cmp = type === 'num' ? ( a - b ) : String( a ).localeCompare( String( b ), undefined, { sensitivity: 'base' } );
            return dir === 'asc' ? cmp : -cmp;
        } );

        rows.forEach( function ( row ) { tbody.appendChild( row ); } );
    }

    function cellValue( row, columnIndex, type ) {
        var cell = row.children[ columnIndex ];
        if ( ! cell ) return type === 'num' ? 0 : '';
        var raw = cell.hasAttribute( 'data-value' ) ? cell.getAttribute( 'data-value' ) : cell.textContent.trim();
        if ( type === 'num' ) {
            var n = parseFloat( String( raw ).replace( ',', '.' ) );
            return isNaN( n ) ? 0 : n;
        }
        return raw;
    }
} )();
