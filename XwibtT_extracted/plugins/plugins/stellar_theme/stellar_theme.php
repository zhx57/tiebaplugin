<?php

/*
Plugin Name: Aurora Theme
Version: 1.0
Plugin URL: https://github.com/MoeNetwork/Tieba-Cloud-Sign
Description: 为云签到提供现代化玻璃拟态暗色主题 — Aurora / Stellar Glassmorphism Theme
Author: Community
Author Email: i@v4.hk
Author URL: https://github.com/MoeNetwork/Tieba-Cloud-Sign
For: V4.0+
*/

if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

function stellar_theme_core()
{
    echo '<meta name="color-scheme" content="light dark">';
    echo '<link rel="stylesheet" href="' . SYSTEM_URL . 'plugins/stellar_theme/css/stellar_b37248c5.css">';
    echo '<script>
(function() {
    var TOGGLE_ID = "stellar-theme-toggle";
    var STORAGE_KEY = "stellar-theme";
    var MODES = ["auto", "light", "dark"];
    var ICONS = { auto: "\u25D0", light: "\u2600", dark: "\u263E" };
    var TITLES = { auto: "Auto \u00B7 Follow System", light: "Light Mode", dark: "Dark Mode" };

    function getMode() {
        var stored = localStorage.getItem(STORAGE_KEY);
        if (stored && MODES.indexOf(stored) !== -1) return stored;
        return "auto";
    }

    function applyMode(mode) {
        if (mode === "auto") {
            document.documentElement.removeAttribute("data-theme");
            var cs = document.querySelector("meta[name=\'color-scheme\']");
            if (cs) cs.setAttribute("content", "light dark");
        } else {
            document.documentElement.setAttribute("data-theme", mode);
            var cs = document.querySelector("meta[name=\'color-scheme\']");
            if (cs) cs.setAttribute("content", mode);
        }
    }

    function updateToggle(btn, mode) {
        btn.textContent = ICONS[mode];
        btn.title = TITLES[mode];
    }

    // Apply immediately before DOMContentLoaded (avoids flash)
    applyMode(getMode());

    document.addEventListener("DOMContentLoaded", function() {
        var btn = document.getElementById(TOGGLE_ID);
        if (btn) { updateToggle(btn, getMode()); return; }

        btn = document.createElement("button");
        btn.id = TOGGLE_ID;
        btn.type = "button";
        btn.setAttribute("aria-label", "Toggle theme mode");

        var current = getMode();
        updateToggle(btn, current);

        btn.addEventListener("click", function() {
            var idx = MODES.indexOf(getMode());
            var next = MODES[(idx + 1) % MODES.length];
            localStorage.setItem(STORAGE_KEY, next);
            applyMode(next);
            updateToggle(btn, next);
        });

        // Insert into navbar-header (always visible) or fall back to navbar-collapse
        var target = document.querySelector(".navbar-header");
        if (!target) {
            target = document.querySelector(".navbar-collapse");
        }
        if (!target) return;

        // On mobile: insert before hamburger toggle; on desktop: append
        var hamburger = target.querySelector(".navbar-toggle");
        if (hamburger) {
            target.insertBefore(btn, hamburger);
        } else {
            target.appendChild(btn);
        }
    });
})();
</script>';
}

addAction('header', 'stellar_theme_core');
