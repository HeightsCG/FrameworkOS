<?php /* Admin > Growth: the Founding Creator offer (CreatorPromo): offer price, regular price after it, and end date (app_settings creator_promo_*). */
$cp_h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<section class="adm-box">
<header class="adm-box__h"><h2 class="adm-box__t">Founding Creator Price</h2><span class="adm-box__note" id="admCpNote"><?php echo $cp_h(CreatorPromo::admin_note()); ?></span></header>
    <form class="adm-promo" id="admCreatorPromo" novalidate>
        <div class="adm-field">
            <label for="admCpPrice">Offer Price</label>
            <div class="adm-money"><span class="adm-money__sign">$</span><input type="number" id="admCpPrice" name="price" min="1" max="9999" step="1" inputmode="numeric" value="<?php echo (int) CreatorPromo::price(); ?>" required><span class="adm-money__unit">/ month</span></div>
        </div>
        <div class="adm-field">
            <label for="admCpRegular">Regular Price</label>
            <div class="adm-money"><span class="adm-money__sign">$</span><input type="number" id="admCpRegular" name="regular" min="1" max="9999" step="1" inputmode="numeric" value="<?php echo (int) CreatorPromo::regular(); ?>" required><span class="adm-money__unit">/ month</span></div>
        </div>
        <div class="adm-field">
            <label for="admCpEnds">Offer Ends</label>
            <input type="date" class="adm-promo__date" id="admCpEnds" name="ends" value="<?php echo $cp_h(substr(CreatorPromo::ends(), 0, 10)); ?>" required>
        </div>
        <button type="submit" class="adm-btn adm-btn--primary adm-promo__save">Save Changes</button>
    </form>
</section>
<script>
(function () {
    var f = document.getElementById('admCreatorPromo');
    if (!f) { return; }
    f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = f.querySelector('button'); btn.disabled = true;
        ApiDataSvc.apiCall('post', 'admin_set_creator_promo', {
            price: document.getElementById('admCpPrice').value,
            regular: document.getElementById('admCpRegular').value,
            ends: document.getElementById('admCpEnds').value
        }, function (data) {
            btn.disabled = false;
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { toastr.error((o && o.message) || 'Could not save the offer'); return; }
            document.getElementById('admCpNote').textContent = o.note;
            toastr.success(o.message);
        });
    });
})();
</script>
