<?php
/**
 * Settings Styles - Estilos CSS para el panel de administración
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<style>
/* ==========================================
   ESTADÍSTICAS
   ========================================== */
.mads-ps-stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
    animation: fadeIn 0.3s ease-in;
}

.mads-ps-stat-card {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.mads-ps-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.stat-vip {
    border-left: 4px solid #FFD700;
}

.stat-products {
    border-left: 4px solid #2196F3;
}

.stat-discounts {
    border-left: 4px solid #E74C3C;
}

.stat-logs {
    border-left: 4px solid #27AE60;
}

.stat-content {
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-content .dashicons {
    font-size: 42px;
    width: 42px;
    height: 42px;
}

.stat-vip .dashicons {
    color: #FFD700;
}

.stat-products .dashicons {
    color: #2196F3;
}

.stat-discounts .dashicons {
    color: #E74C3C;
}

.stat-logs .dashicons {
    color: #27AE60;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    line-height: 1;
}

.stat-number-small {
    font-size: 18px;
    font-weight: bold;
    line-height: 1;
}

.stat-vip .stat-number {
    color: #FFD700;
}

.stat-products .stat-number {
    color: #2196F3;
}

.stat-discounts .stat-number {
    color: #E74C3C;
}

.stat-logs .stat-number-small {
    color: #27AE60;
}

.stat-label {
    color: #666;
    font-size: 12px;
    margin-top: 5px;
}

/* ==========================================
   TABS
   ========================================== */
.nav-tab-wrapper {
    margin: 20px 0 0 0;
}

.nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
}

.nav-tab .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.nav-tab:hover {
    background: #f0f0f1;
}

.mads-ps-badge-error {
    background: #dc3232;
    color: #fff;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 11px;
    margin-left: 5px;
    font-weight: bold;
}

/* ==========================================
   CONTENIDO DE TABS
   ========================================== */
.mads-ps-tab-content {
    background: #fff;
    padding: 30px;
    margin-top: 0;
    border-radius: 0 8px 8px 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    animation: slideIn 0.3s ease-in;
}

.mads-ps-tab-content h2 {
    margin-top: 0;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f1;
}

/* ==========================================
   FORMULARIOS
   ========================================== */
.form-table th {
    width: 220px;
    padding: 20px 10px 20px 0;
    vertical-align: top;
}

.form-table td {
    padding: 15px 10px;
}

.form-table code {
    background: #f0f0f1;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 13px;
    font-family: 'Courier New', monospace;
}

.form-table .description {
    color: #666;
    font-style: italic;
}

.button-hero {
    padding: 12px 36px !important;
    height: auto !important;
    font-size: 14px !important;
}

.button-hero .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    margin-right: 5px;
    vertical-align: middle;
}

.tooltip {
    cursor: help;
    color: #2196F3;
    font-size: 16px;
    width: 16px;
    height: 16px;
    vertical-align: middle;
    margin-left: 5px;
}

.danger-title {
    color: #dc3232;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ==========================================
   MODAL
   ========================================== */
.mads-ps-modal {
    display: none;
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    animation: fadeIn 0.2s ease;
}

.mads-ps-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 30px;
    border: 1px solid #ddd;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    animation: slideDown 0.3s ease;
}

.mads-ps-modal-close {
    color: #999;
    float: right;
    font-size: 32px;
    font-weight: bold;
    line-height: 1;
    cursor: pointer;
    transition: color 0.2s ease;
}

.mads-ps-modal-close:hover,
.mads-ps-modal-close:focus {
    color: #000;
}

.mads-ps-modal-content h2 {
    margin-top: 0;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f1;
}

.mads-ps-modal-content p {
    margin: 15px 0;
}

.mads-ps-modal-content label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.mads-ps-modal-content input[type="number"],
.mads-ps-modal-content select {
    padding: 8px;
}

/* ==========================================
   TABLAS
   ========================================== */
.wp-list-table {
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.wp-list-table thead {
    background: #f9f9f9;
}

.wp-list-table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
}

.wp-list-table .button-small {
    padding: 4px 10px;
    font-size: 12px;
}

.wp-list-table .button-link-delete {
    color: #dc3232;
}

.wp-list-table .button-link-delete:hover {
    color: #a00;
}

.discount-amount {
    display: inline-block;
    background: #e74c3c;
    color: #fff;
    padding: 4px 10px;
    border-radius: 15px;
    font-weight: bold;
    font-size: 13px;
}

/* ==========================================
   LOGS
   ========================================== */
.mads-ps-log-stats {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.log-stat-box ul {
    list-style: none;
    margin: 0;
    padding: 0;
}

.log-stat-box li {
    padding: 8px 0;
    border-bottom: 1px solid #e0e0e0;
}

.log-stat-box li:last-child {
    border-bottom: none;
}

.error-count strong,
.error-count {
    color: #dc3232;
}

.warning-count strong,
.warning-count {
    color: #f56e00;
}

.info-count strong,
.info-count {
    color: #2196F3;
}

.mads-ps-log-viewer {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 20px;
    border-radius: 8px;
    overflow-x: auto;
    max-height: 500px;
    overflow-y: auto;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.6;
}

.mads-ps-log-viewer pre {
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* ==========================================
   INFO BOXES
   ========================================== */
.mads-ps-users-info,
.mads-ps-products-info {
    background: #e7f3ff;
    border-left: 4px solid #2196F3;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.mads-ps-users-info p,
.mads-ps-products-info p {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 15px;
}

/* ==========================================
   ANIMACIONES
   ========================================== */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ==========================================
   RESPONSIVE
   ========================================== */
@media (max-width: 782px) {
    .mads-ps-stats-cards {
        grid-template-columns: 1fr;
    }
    
    .form-table th,
    .form-table td {
        display: block;
        width: 100%;
        padding: 10px 0;
    }
    
    .form-table th {
        padding-bottom: 5px;
    }
}
</style>