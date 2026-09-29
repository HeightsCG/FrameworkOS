<?php /* Adjust Balance dialog, shared by /admin/user/<id> and the requester panel on a support request. Needs \$u. */ ?>
<div class="modal fade" id="admAdjustModal" tabindex="-1" aria-hidden="true" aria-labelledby="admAdjustTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="admAdjustTitle">Adjust Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="adm-field"><span class="adm-field__label">Which Balance</span>
                    <div class="adm-bal" role="radiogroup" aria-label="Which balance">
                        <label class="adm-bal__opt">
                            <input type="radio" name="adj_wallet" value="credits" checked>
                            <span class="adm-bal__body"><b>Wallet credits</b><span>Buy content: unlocks, tickets, bookings. Creators' earnings.</span><em>Now <?php echo number_format((int) $u['credit_balance']); ?></em></span>
                        </label>
                        <label class="adm-bal__opt">
                            <input type="radio" name="adj_wallet" value="ai">
                            <span class="adm-bal__body"><b>AI credits</b><span>Make content: AI images, videos, AI influencers.</span><em>Now <?php echo number_format((int) $u['ai_credit_balance']); ?></em></span>
                        </label>
                    </div>
                </div>
                <div class="adm-field"><label for="adj_amount">Amount</label><input type="number" class="form-control" id="adj_amount" step="1" placeholder="100 or -100"></div>
                <div class="adm-field"><label for="adj_reason">Reason</label><input type="text" class="form-control" id="adj_reason" maxlength="200" placeholder="Goodwill credit for failed unlock"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="adm-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="adm-btn adm-btn--primary" id="adjSave">Save Adjustment</button>
            </div>
        </div>
    </div>
</div>
