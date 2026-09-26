<?php
/**
 * Row actions for admin tables live in one ⋯ menu (no buttons in table rows). adm_row_menu($label, $items):
 * $items = [ ['text' => 'Refund…', 'attrs' => 'data-refund', 'danger' => true], ['text' => 'Edit', 'href' => '/x'] ].
 * The page's click handlers find their row from the clicked item, so they work unchanged inside the menu.
 */
if (!function_exists('adm_row_menu')) {
    function adm_row_menu(string $label, array $items): string {
        $items = array_values(array_filter($items));
        if (empty($items)) { return ''; }
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $out = '<div class="dropdown"><button type="button" class="adm-more" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="' . $h($label) . '"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button><ul class="dropdown-menu dropdown-menu-end adm-menu">';
        foreach ($items as $it) {
            $cls = 'dropdown-item' . (!empty($it['danger']) ? ' adm-menu__danger' : '');
            $out .= isset($it['href'])
                ? '<li><a class="' . $cls . '" href="' . $h($it['href']) . '">' . $h($it['text']) . '</a></li>'
                : '<li><button type="button" class="' . $cls . '" ' . ($it['attrs'] ?? '') . '>' . $h($it['text']) . '</button></li>';
        }
        return $out . '</ul></div>';
    }
}
