<?php /* Admin > Growth: the Founding Creator offer's end date (CreatorPromo, app_settings creator_promo_ends). Extending the offer = a later date here. */
$cp_running = CreatorPromo::running();
$cp_ends    = substr(CreatorPromo::ends(), 0, 10);
?>
<section class="adm-box">
<header class="adm-box__h"><h2 class="adm-box__t">Founding Creator Price</h2><span class="adm-box__note" id="admCpNote"><?php
    echo $cp_running
        ? 'New signups pay $' . CreatorPromo::PRICE . ' a month until ' . htmlspecialchars(CreatorPromo::ends_label(), ENT_QUOTES, 'UTF-8') . ', 11:59 PM Eastern, then $' . CreatorPromo::REGULAR . '. Everyone who starts at $' . CreatorPromo::PRICE . ' keeps it.'
        : 'Ended ' . htmlspecialchars(CreatorPromo::ends_label(), ENT_QUOTES, 'UTF-8') . ': new signups pay $' . CreatorPromo::REGULAR . '. Accounts that started at $' . CreatorPromo::PRICE . ' keep it.';
?></span></header>
    <form class="adm-kwadd" id="admCreatorPromo">
        <label class="visually-hidden" for="admCpEnds">Offer Ends</label>
        <input type="date" id="admCpEnds" name="ends" value="<?php echo htmlspecialchars($cp_ends, ENT_QUOTES, 'UTF-8'); ?>" required>
        <button type="submit" class="adm-btn">Save Date</button>
    </form>
</section>
<script>
(function () {
    var f = document.getElementById('admCreatorPromo');
    if (!f) { return; }
    f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = f.querySelector('button'); btn.disabled = true;
        ApiDataSvc.apiCall('post', 'admin_set_creator_promo_end', { ends: document.getElementById('admCpEnds').value }, function (data) {
            btn.disabled = false;
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { toastr.error((o && o.message) || 'Could not save the date'); return; }
            document.getElementById('admCpNote').textContent = o.message.replace(/^Saved\. /, '');
            toastr.success(o.message);
        });
    });
})();
</script>
