<?php
/**
 * Mizban (میزبان) - Lightweight i18n (fa / en)
 * ------------------------------------------------------------------
 * منبع ترجمه‌ها: پوشه lang/ (fa.json و en.json)
 * زبان جاری از این اولویت‌ها انتخاب می‌شود:
 *   ۱) پارامتر ?lang=fa|en
 *   ۲) کوکی mizban_lang
 *   ۳) پیش‌فرض: fa
 *
 * استفاده:
 *   echo t('nav.dashboard');
 *   echo t('api.modelNotActive', ['{model}' => $model]);
 */

if (!defined('MIZBAN_LANG')) {
    $mizbanLang = 'fa';
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['fa', 'en'], true)) {
        $mizbanLang = $_GET['lang'];
    } elseif (isset($_COOKIE['mizban_lang']) && in_array($_COOKIE['mizban_lang'], ['fa', 'en'], true)) {
        $mizbanLang = $_COOKIE['mizban_lang'];
    }
    define('MIZBAN_LANG', $mizbanLang);
}

/**
 * بارگذاری فرهنگ لغت یک زبان (با cache).
 */
function mizban_dict(string $lang): array {
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];
    $file = __DIR__ . '/lang/' . $lang . '.json';
    $cache[$lang] = [];
    if (is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data)) $cache[$lang] = $data;
    }
    return $cache[$lang];
}

/**
 * ترجمه یک کلید. جایگزینی متغیر: t('x', ['{model}' => 'gpt-4o']).
 * در صورت نبود کلید: fallback به فارسی، سپس خود کلید.
 */
function t(string $key, array $vars = []): string {
    $lang = defined('MIZBAN_LANG') ? MIZBAN_LANG : 'fa';
    $dict = mizban_dict($lang);
    $s = $dict[$key] ?? mizban_dict('fa')[$key] ?? $key;
    if ($vars) {
        foreach ($vars as $k => $v) {
            $s = str_replace($k, (string)$v, $s);
        }
    }
    return $s;
}

/** آیا زبان جاری انگلیسی است؟ */
function is_en(): bool {
    return (defined('MIZBAN_LANG') ? MIZBAN_LANG : 'fa') === 'en';
}
