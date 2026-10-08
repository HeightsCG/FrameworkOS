<?php /* Admin > Growth: which plans may cross-promote on /promote (comma-separated PlanTiers keys, AdminSettingsModel 'cross_promo_plans'). */ ?>
<div class="adm-sec__head" style="margin-top:1.6rem;">
    <h2 class="adm-sec__title">Cross-Promotion</h2>
    <form class="adm-kwadd" id="admPromoPlans">
        <label class="visually-hidden" for="admPromoPlansIn">Cross-Promotion Plans</label>
        <input type="text" id="admPromoPlansIn" name="plans" value="<?php echo htmlspecialchars((string) $this->cross_promo_plans, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars(implode(', ', array_keys(PlanTiers::TIERS)), ENT_QUOTES, 'UTF-8'); ?>" maxlength="120" style="width:260px;" title="Cross-Promotion Plans">
        <button type="submit" class="adm-btn adm-btn--ok">Save Plans</button>
    </form>
</div>
<script>
(function () {
    var f = document.getElementById('admPromoPlans');
    if (!f) { return; }
    f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = f.querySelector('button'); btn.disabled = true;
        ApiDataSvc.apiCall('post', 'admin_set_cross_promo_plans', { plans: document.getElementById('admPromoPlansIn').value }, function (data) {
            btn.disabled = false;
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { toastr.error((o && o.message) || 'Could not save the plans'); return; }
            document.getElementById('admPromoPlansIn').value = o.plans.split(',').join(', ');
            toastr.success(o.message);
        });
    });
})();
</script>
