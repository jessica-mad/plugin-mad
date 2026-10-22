/* global Chart, madCIData */
( function () {
    document.addEventListener( 'DOMContentLoaded', function () {
        var canvas = document.getElementById( 'mad-ci-monthly-chart' );
        if ( ! canvas || typeof Chart === 'undefined' || typeof madCIData === 'undefined' ) return;

        var months = madCIData.monthly.map( function ( row ) { return row.month; } );
        var newCounts    = madCIData.monthly.map( function ( row ) { return row.new_customers; } );
        var repeatCounts = madCIData.monthly.map( function ( row ) { return row.repeat_customers; } );

        new Chart( canvas, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [
                    {
                        label: madCIData.i18n.new,
                        data: newCounts,
                        backgroundColor: '#2271b1',
                    },
                    {
                        label: madCIData.i18n.repeat,
                        data: repeatCounts,
                        backgroundColor: '#d63638',
                    },
                ],
            },
            options: {
                responsive: true,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        } );
    } );
} )();
