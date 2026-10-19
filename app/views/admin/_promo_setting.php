<?php /* Admin > Growth: which plans may use cross-promotion swaps on /promote (AdminSettingsModel 'cross_promo_plans'; none ticked = off). */
$pp_on = array_map('trim', explode(',', (string) $this->cross_promo_plans));
?>
<section class="adm-box">
<header class="adm-box__h"><h2 class="adm-box__t">Cross-Promotion</h2><span class="adm-box__note">Creators on these plans can swap profile features with each other on Promote</span></header>
    <div class="adm-plans" id="admPromoPlans">
        <?php foreach (PlanTiers::offered() as $t) { $k = (string) $t['key']; ?>
        <label class="adm-plans__opt" for="admPromoPlan_<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="checkbox" id="admPromoPlan_<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"<?php echo in_array($k, $pp_on, true) ? ' checked' : ''; ?>>
            <span><?php echo htmlspecialchars((string) $t['name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </label>
        <?php } ?>
    </div>
</section>
<script>
(function () {
    var box = document.getElementById('admPromoPlans');
    if (!box) { return; }
    box.addEventListener('change', function (ev) {
        var boxes = box.querySelectorAll('input[type=checkbox]'), keys = [];
        for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) { keys.push(boxes[i].value); } }
        ApiDataSvc.apiCall('post', 'admin_set_cross_promo_plans', { plans: keys.join(',') }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { ev.target.checked = !ev.target.checked; toastr.error((o && o.message) || 'Could not save the plans'); return; }
            toastr.success(o.message);
        });
    });
})();
</script>
