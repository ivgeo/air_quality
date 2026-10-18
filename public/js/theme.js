/*
 * Light/dark theme toggle shared by every page. The remembered theme is applied by an inline
 * script in the layout's <head>, before the stylesheet; this only handles the button.
 */
(function () {
    'use strict';

    const themeToggle = document.getElementById('theme-toggle');
    const darkScheme = window.matchMedia('(prefers-color-scheme: dark)');

    function currentTheme() {
        return document.documentElement.dataset.theme || (darkScheme.matches ? 'dark' : 'light');
    }

    function updateThemeToggle() {
        themeToggle.setAttribute('aria-label', 'Switch to ' + (currentTheme() === 'dark' ? 'light' : 'dark') + ' theme');
    }

    function toggleTheme() {
        const theme = currentTheme() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;

        try {
            localStorage.setItem('theme', theme);
        } catch (e) {
            // Storage blocked (e.g. a private window): the choice lasts until the page is reloaded.
        }

        updateThemeToggle();
    }

    themeToggle.addEventListener('click', toggleTheme);
    darkScheme.addEventListener('change', updateThemeToggle);
    updateThemeToggle();
}());
