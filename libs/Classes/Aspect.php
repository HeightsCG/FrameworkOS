<?php
/**
 * The one list of output shapes every generator offers. Keys are the ratio itself ('3:4');
 * the older square | portrait | landscape keys (saved automations, MCP callers) are read as
 * their ratio forever, so nothing stored needs rewriting.
 *
 * What a model can actually render lives on its catalog entry (InfluencerConfig::MODELS
 * 'aspects' => [ratio => the value that provider endpoint takes]); a ratio missing there is
 * unsupported and the pickers disable it.
 */
class Aspect {

    /** Display order. w/h are the ratio terms. */
    const RATIOS = array(
        '3:4'  => array('label' => '3:4',  'name' => 'Portrait',  'w' => 3, 'h' => 4),
        '4:5'  => array('label' => '4:5',  'name' => 'Feed',      'w' => 4, 'h' => 5),
        '9:16' => array('label' => '9:16', 'name' => 'Story',     'w' => 9, 'h' => 16),
        '1:1'  => array('label' => '1:1',  'name' => 'Square',    'w' => 1, 'h' => 1),
        '4:3'  => array('label' => '4:3',  'name' => 'Landscape', 'w' => 4, 'h' => 3),
    );

    const LEGACY = array('square' => '1:1', 'portrait' => '3:4', 'landscape' => '4:3');

    const DEFAULT_IMAGE = '3:4';    // feed images
    const DEFAULT_VIDEO = '9:16';   // video and stories

    public static function keys(){ return array_keys(self::RATIOS); }

    /** Any accepted spelling -> a ratio key; $default (itself normalised) when unknown or empty. */
    public static function normalize($value, $default = self::DEFAULT_IMAGE){
        $v = strtolower(trim((string) $value));
        if (isset(self::LEGACY[$v])) { return self::LEGACY[$v]; }
        if (isset(self::RATIOS[$v])) { return $v; }
        $d = strtolower(trim((string) $default));
        if (isset(self::LEGACY[$d])) { return self::LEGACY[$d]; }
        return isset(self::RATIOS[$d]) ? $d : self::DEFAULT_IMAGE;
    }

    public static function valid($value){
        $v = strtolower(trim((string) $value));
        return isset(self::RATIOS[$v]) || isset(self::LEGACY[$v]);
    }

    public static function label($key){
        $k = self::normalize($key);
        return self::RATIOS[$k]['name'] . ' ' . self::RATIOS[$k]['label'];
    }

    /** Ratio keys a catalog model can render, in display order. A model with no 'aspects' follows its source image. */
    public static function supported(array $model){
        $map = (array) ($model['aspects'] ?? array());
        return array_values(array_filter(self::keys(), function ($k) use ($map) { return array_key_exists($k, $map); }));
    }

    public static function supports(array $model, $key){
        return in_array(self::normalize($key), self::supported($model), true);
    }

    /** The ratio to use for this model: the one asked for when it can render it, else its first supported one. */
    public static function for_model(array $model, $key, $default = self::DEFAULT_IMAGE){
        $k   = self::normalize($key, $default);
        $sup = self::supported($model);
        if (empty($sup) || in_array($k, $sup, true)) { return $k; }
        $d = self::normalize($default);
        return in_array($d, $sup, true) ? $d : $sup[0];
    }

    /** What the provider endpoint takes for this ratio (a preset string, a ratio string or ['width','height']); null when unsupported. */
    public static function value_for(array $model, $key){
        $map = (array) ($model['aspects'] ?? array());
        $k   = self::normalize($key);
        return array_key_exists($k, $map) ? $map[$k] : null;
    }

    /** Picker rows for the UI: [['key','label','name','supported'], ...]. */
    public static function options($model = null){
        $sup = is_array($model) ? self::supported($model) : self::keys();
        $out = array();
        foreach (self::RATIOS as $k => $r) {
            $out[] = array('key' => $k, 'label' => $r['label'], 'name' => $r['name'], 'supported' => in_array($k, $sup, true));
        }
        return $out;
    }

    /** The list for page scripts: ratio keys in display order, the older keys they replace, display names, defaults. */
    public static function client(){
        $names = array();
        foreach (self::RATIOS as $k => $r) { $names[$k] = $r['name'] . ' ' . $r['label']; }
        return array('keys' => self::keys(), 'legacy' => self::LEGACY, 'names' => $names,
            'default_image' => self::DEFAULT_IMAGE, 'default_video' => self::DEFAULT_VIDEO);
    }

    /** Segmented-control buttons for a view: one per ratio with a small outline of the shape. $attr is the data attribute carrying the key. */
    public static function seg_buttons($class, $attr, $selected = self::DEFAULT_IMAGE, $model = null){
        $sel = self::normalize($selected);
        $out = '';
        foreach (self::options($model) as $o) {
            $on = ($o['key'] === $sel && $o['supported']);
            $out .= '<button type="button" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ($on ? ' is-on' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '" '
                . htmlspecialchars($attr, ENT_QUOTES, 'UTF-8') . '="' . $o['key'] . '" title="' . $o['name'] . '" aria-label="' . $o['name'] . ' ' . $o['label'] . '"' . ($o['supported'] ? '' : ' disabled') . '>'
                . '<i class="cs-ratio" style="--rw:' . (int) self::RATIOS[$o['key']]['w'] . ';--rh:' . (int) self::RATIOS[$o['key']]['h'] . '" aria-hidden="true"></i><span>' . $o['label'] . '</span></button>';
        }
        return $out;
    }

    /** Does a width x height match the ratio (within $tolerance, 3% by default)? */
    public static function matches($width, $height, $key, $tolerance = 0.03){
        $w = (int) $width; $h = (int) $height;
        if ($w <= 0 || $h <= 0) { return false; }
        $r = self::RATIOS[self::normalize($key)];
        $want = $r['w'] / $r['h'];
        return abs(($w / $h) - $want) <= $want * (float) $tolerance;
    }

    /** The closest ratio key to a width x height (for reading an existing image or video). */
    public static function nearest($width, $height){
        $w = (int) $width; $h = (int) $height;
        if ($w <= 0 || $h <= 0) { return self::DEFAULT_IMAGE; }
        $best = self::DEFAULT_IMAGE; $gap = PHP_FLOAT_MAX;
        foreach (self::RATIOS as $k => $r) {
            $d = abs(($w / $h) - ($r['w'] / $r['h']));
            if ($d < $gap) { $gap = $d; $best = $k; }
        }
        return $best;
    }
}
