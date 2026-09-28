<?php
/**
 * Pays creators for event tickets once the event is over (run every minute by cron/scheduler.php). A ticket's share
 * is stored when the fan registers and added to the creator's wallet here, so a refund before the event never has to
 * take anything back. Each ticket is paid once (CreditsModel::release_event_earning locks and marks it); the creator
 * gets one notice per event per run.
 */
class EventEarnings {

    public static function run($limit = 500): int {
        $credits = new CreditsModel();
        $paid = array();   // event id => ['creator', 'title', 'credits']
        foreach ((new EventsModel())->earnings_due($limit) as $r) {
            $n = $credits->release_event_earning((int) $r['id']);
            if ($n <= 0) { continue; }
            $k = (int) $r['event_id'];
            if (!isset($paid[$k])) { $paid[$k] = array('creator' => (int) $r['creator_id'], 'title' => (string) $r['title'], 'credits' => 0); }
            $paid[$k]['credits'] += $n;
        }
        foreach ($paid as $id => $p) {
            $t = mb_substr(html_entity_decode($p['title'], ENT_QUOTES, 'UTF-8'), 0, 60);
            Notify::send($p['creator'], 'credits', 'Event earnings added', Notify::credits($p['credits']) . ' from "' . $t . '" is now in your balance.',
                '/account/settings?section=wallet&tab=cashout', 'fa-calendar-check');
        }
        return count($paid);
    }
}
