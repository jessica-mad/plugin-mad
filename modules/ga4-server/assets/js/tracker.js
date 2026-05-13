/**
 * MAD Ads Tracker — frontend funnel event capture
 * Detects gclid/fbclid/epik click sessions and tracks view_content,
 * add_to_cart and begin_checkout events back to the server.
 */
(function () {
    'use strict';

    if (typeof madAdsTracker === 'undefined') return;

    var STORAGE_KEY = 'mad_ads_session';
    var SESSION_TTL = 30 * 24 * 60 * 60 * 1000; // 30 days ms

    /* ---- Session helpers ---- */

    function readSession() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            var s = JSON.parse(raw);
            if (!s || !s.click_id || Date.now() > s.expires) {
                localStorage.removeItem(STORAGE_KEY);
                return null;
            }
            return s;
        } catch (e) { return null; }
    }

    function writeSession(click_id, platform) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                click_id : click_id,
                platform : platform,
                expires  : Date.now() + SESSION_TTL
            }));
        } catch (e) {}
    }

    /* ---- Click ID extraction ---- */

    function getUrlParam(name) {
        try {
            return new URLSearchParams(window.location.search).get(name) || null;
        } catch (e) { return null; }
    }

    function cookieMatch(pattern) {
        var m = document.cookie.match(pattern);
        return m ? m[1] : null;
    }

    // Returns {click_id, platform} or null
    function detectClickId() {
        // Google: gclid in URL (landing page hit)
        var gclid = getUrlParam('gclid');
        if (gclid) return { click_id: gclid, platform: 'google' };

        // Meta: fbclid in URL (landing page hit)
        var fbclid = getUrlParam('fbclid');
        if (fbclid) return { click_id: fbclid, platform: 'meta' };

        // Pinterest: epik in URL (landing page hit)
        var epik = getUrlParam('epik');
        if (epik) return { click_id: epik, platform: 'pinterest' };

        // Google: extract from _gcl_aw cookie on subsequent pages
        var gclidCookie = cookieMatch(/_gcl_aw=GCL\.\d+\.([^;]+)/);
        if (gclidCookie) return { click_id: gclidCookie, platform: 'google' };

        // Meta: extract fbclid from _fbc cookie (format: fb.1.{ts}.{fbclid})
        var fbc = cookieMatch(/_fbc=([^;]+)/);
        if (fbc) {
            var parts = fbc.split('.');
            if (parts.length >= 4 && parts[3]) {
                return { click_id: parts[3], platform: 'meta' };
            }
        }

        // Pinterest: _epik cookie
        var epicookie = cookieMatch(/_epik=([^;]+)/);
        if (epicookie) return { click_id: epicookie, platform: 'pinterest' };

        return null;
    }

    /* ---- Event sender ---- */

    function send(session, eventName) {
        if (!session || !session.click_id) return;

        var body = new FormData();
        body.append('action',   'mad_ads_track_event');
        body.append('nonce',    madAdsTracker.nonce);
        body.append('click_id', session.click_id);
        body.append('platform', session.platform);
        body.append('event',    eventName);

        // Fire-and-forget — we don't care about the response
        if (typeof fetch !== 'undefined') {
            fetch(madAdsTracker.ajaxUrl, { method: 'POST', body: body }).catch(function () {});
        } else if (typeof XMLHttpRequest !== 'undefined') {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', madAdsTracker.ajaxUrl, true);
            xhr.send(body);
        }
    }

    function hasClass(cls) {
        return document.body.classList.contains(cls);
    }

    /* ---- Init ---- */

    function init() {
        // 1. Detect current session (URL param > cookie > localStorage)
        var fresh   = detectClickId();
        var session = fresh || readSession();

        if (!session) return; // no active Ads click session

        // Persist / refresh TTL when we have a fresh click
        if (fresh) writeSession(fresh.click_id, fresh.platform);

        // 2. Page-level event (mutually exclusive — most specific wins)
        if (hasClass('woocommerce-checkout') && !hasClass('woocommerce-order-received')) {
            send(session, 'begin_checkout');

        } else if (hasClass('single-product')) {
            send(session, 'view_content');

        } else {
            // Home, category, blog, etc.
            send(session, 'page_view');
        }

        // 3. Add-to-cart (click-based, doesn't count as extra page view)
        //    WooCommerce fires the jQuery 'added_to_cart' event on document.body
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('added_to_cart', function () {
                send(session, 'add_to_cart');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
