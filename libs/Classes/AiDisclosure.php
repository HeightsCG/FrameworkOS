<?php
/**
 * AI disclosure rules in one place: what counts as AI media, how a cross-post is labelled, and what
 * an automated inbox reply must never do.
 *
 *  - Media is AI media when its provenance is 'generated' or 'edited' (media_assets.provenance).
 *  - Cross-posts: where the platform has its own AI label (TikTok, YouTube) that flag is set; everywhere
 *    else a disclosure line is added to the caption. On by default for any post with AI media.
 *  - Inbox: the first automated reply to each fan carries a disclosure that replies may be
 *    automated; a reply never denies being AI; and when a fan asks whether they are talking to a
 *    real person, a draft that does not confirm it is AI is held for the creator to approve.
 */
class AiDisclosure {

    const DEFAULT_LINE        = 'AI-generated content';
    const DEFAULT_FIRST_REPLY = 'Some replies here may be automated.';
    /** Shown on the public profile of an account that has published AI media. */
    const PROFILE_LINE        = 'Some content on this page is AI-generated.';

    /** Platforms whose API takes an AI-generated flag (Post for Me platform_configurations key => field). */
    const PLATFORM_FLAGS = array('tiktok' => 'is_ai_generated', 'tiktok_business' => 'is_ai_generated', 'youtube' => 'contains_synthetic_media');

    /** Platforms that accept a Story placement. */
    const STORY_PLATFORMS = array('instagram', 'facebook');

    public static function is_ai_asset($a){
        return is_array($a) && in_array((string) ($a['provenance'] ?? 'uploaded'), array('generated', 'edited'), true);
    }

    /** Does any of these post assets count as AI media? */
    public static function has_ai_media(array $assets){
        foreach ($assets as $a) { if (self::is_ai_asset($a)) { return true; } }
        return false;
    }

    /** Should this post be disclosed? $choice: the post's ai_disclosure (null = default, which is on for AI media). */
    public static function applies($choice, array $assets){
        if (!self::has_ai_media($assets)) { return false; }
        return ($choice === null || $choice === '') ? true : ((int) $choice === 1);
    }

    public static function flag_for($platform){ return self::PLATFORM_FLAGS[(string) $platform] ?? ''; }

    /** The disclosure line for an account: its own text, else the default. */
    public static function line($text){
        $t = trim(preg_replace('/\s+/', ' ', (string) $text));
        return ($t !== '') ? mb_substr($t, 0, 200) : self::DEFAULT_LINE;
    }

    /**
     * A caption with the disclosure line on its own last line, inside the platform's length limit
     * ($limit 0 = no limit): the caption is shortened, the line never is.
     */
    public static function caption_with_line($caption, $line, $limit = 0){
        $caption = trim((string) $caption); $line = self::line($line);
        if ($caption !== '' && mb_stripos($caption, $line) !== false) { return $caption; }   // already says it
        if ($caption === '') { return $line; }
        $sep = "\n\n";
        if ((int) $limit > 0 && mb_strlen($caption . $sep . $line) > (int) $limit) {
            $room = (int) $limit - mb_strlen($sep . $line) - 1;
            if ($room < 10) { return mb_substr($line, 0, (int) $limit); }
            $cut = mb_substr($caption, 0, $room);
            $sp  = mb_strrpos($cut, ' ');
            if ($sp !== false && $sp > $room * 0.6) { $cut = mb_substr($cut, 0, $sp); }
            $caption = rtrim($cut, " \t\n,.;:") . '…';
        }
        return $caption . $sep . $line;
    }

    /* ---- inbox ---- */

    /** The text sent with the first automated reply; never blank. */
    public static function first_reply_text($text){
        $t = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        return ($t !== '') ? mb_substr($t, 0, 300) : self::DEFAULT_FIRST_REPLY;
    }

    /** Is the fan asking whether they are talking to a real person, an AI or a bot? */
    public static function asks_if_real($text){
        $t = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', (string) $text)) . ' ';
        $t = str_replace(array("’", "`"), "'", $t);
        $who   = "(?:are|r|is|was|am i (?:talking|speaking|chatting) (?:to|with))";
        $thing = "(?:real|a real (?:person|girl|woman|guy|man|human)|human|a human|an? ai|ai|artificial|a bot|bot|a robot|robot|a chatbot|chatbot|automated|a machine|a program|fake|a person|actually (?:you|real|a person))";
        $patterns = array(
            "/\\b(?:are|r)\\s*(?:you|u|ya)\\b[^.?!]{0,40}\\b{$thing}\\b/u",
            "/\\bis\\s+(?:this|that|it)\\b[^.?!]{0,30}\\b{$thing}\\b/u",
            "/\\bam i (?:talking|speaking|chatting|texting) (?:to|with)\\b[^.?!]{0,30}\\b{$thing}\\b/u",
            "/\\b(?:you|u)(?:'re| are)?\\s+(?:a |an )?(?:bot|ai|robot|chatbot)\\b[^.!]{0,20}\\?/u",
            "/\\b(?:real person|real human|actual person|bot or (?:real|human)|human or (?:bot|ai)|ai or (?:real|human)|real or (?:fake|ai|bot))\\b/u",
            "/\\b(?:is|was) (?:this|that) (?:really|actually) you\\b/u",
            "/\\b(?:do|does) (?:you|u|she|he) (?:really |actually )?(?:write|type|answer|reply|send)\\b[^.?!]{0,30}\\b(?:yourself|herself|himself|these)\\b/u",
        );
        foreach ($patterns as $p) { if (preg_match($p, $t)) { return true; } }
        return false;
    }

    /** Does the reply say plainly that it is AI or automated (and not deny it)? */
    public static function confirms_ai($text){
        $t = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', (string) $text)) . ' ';
        $t = str_replace(array("’", "`"), "'", $t);
        if (self::denies_ai($t)) { return false; }
        return (bool) preg_match("/\\b(?:i'm|i am|im|this is|you're (?:talking|chatting|speaking) (?:to|with)|you are (?:talking|chatting|speaking) (?:to|with)|yes,? (?:i'm|i am|this is))\\s+(?:actually |really |just |partly |in fact )?(?:an? )?(?:ai|a\\.i\\.|artificial|virtual|digital|ai-generated|ai generated|computer-generated|automated|a bot|bot|chatbot|an assistant|assistant)\\b/u", $t)
            || (bool) preg_match("/\\b(?:replies|messages|answers|responses)\\b[^.?!]{0,40}\\b(?:are|may be|can be|is)\\s+(?:sometimes |often |partly |all )?(?:automated|ai|ai-generated|written by (?:an? )?ai|generated by (?:an? )?ai)\\b/u", $t)
            || (bool) preg_match("/\\b(?:an? ai|ai)\\s+(?:helper|assistant|writes|answers|replies|is (?:writing|answering|replying))\\b/u", $t);
    }

    /** Does the reply claim to be a real person or deny being AI? */
    public static function denies_ai($text){
        $t = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', (string) $text)) . ' ';
        $t = str_replace(array("’", "`"), "'", $t);
        return (bool) preg_match("/\\b(?:i'm|i am|im)\\s+(?:not|no)\\s+(?:an? )?(?:ai|bot|robot|chatbot|machine|program|fake|automated)\\b/u", $t)
            || (bool) preg_match("/\\b(?:not|no|never)\\s+(?:an? )?(?:ai|bot|robot|chatbot)\\b[^.?!]{0,20}\\b(?:here|me|i promise|promise|lol|haha|silly)\\b/u", $t)
            || (bool) preg_match("/\\b(?:i'm|i am|im|it's|its|this is)\\s+(?:a |100% |totally |completely |definitely |very |really |actually )*(?:real|a real (?:person|girl|woman|guy|man|human)|human|a human|flesh and blood|really me|actually me)\\b/u", $t)
            || (bool) preg_match("/\\b(?:of course|yes|yep|yeah|obviously)[, ]+(?:i'm|i am|im|it's|its)\\s+(?:real|me|human)\\b/u", $t);
    }

    /**
     * Must this draft wait for the creator? Yes when the fan asked whether they are talking to a real
     * person and the draft does not confirm it is AI, and always when a draft denies being AI.
     */
    public static function should_hold($fan_text, $draft){
        if (self::denies_ai($draft)) { return true; }
        return self::asks_if_real($fan_text) && !self::confirms_ai($draft);
    }
}
