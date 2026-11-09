<?php /* Edit User dialog on /admin/user/<id>. Needs $u and $e. Saved by public/js/admin-user.js → admin_update_user. */ ?>
<div class="modal fade" id="admEditUserModal" tabindex="-1" aria-hidden="true" aria-labelledby="admEditUserTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="admEditUserTitle">Edit user</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="adm-grid2">
                    <div class="adm-field"><label for="eu_first_name">First Name</label><input type="text" class="form-control" id="eu_first_name" value="<?php echo $e($u['first_name']); ?>" autocomplete="off"></div>
                    <div class="adm-field"><label for="eu_last_name">Last Name</label><input type="text" class="form-control" id="eu_last_name" value="<?php echo $e($u['last_name']); ?>" autocomplete="off"></div>
                </div>
                <div class="adm-field"><label for="eu_u_name">Username</label><input type="text" class="form-control" id="eu_u_name" value="<?php echo $e($u['u_name']); ?>" placeholder="username" autocomplete="off"></div>
                <div class="adm-field"><label for="eu_user_email">Email</label><input type="email" class="form-control" id="eu_user_email" value="<?php echo $e($u['user_email']); ?>" placeholder="name@example.com" autocomplete="off"></div>
                <div class="adm-field"><label for="eu_user_phone">Phone</label><input type="tel" class="form-control" id="eu_user_phone" value="<?php echo $e($u['user_phone']); ?>" placeholder="+1 555 123 4567" autocomplete="off"></div>
                <div class="adm-field"><label for="eu_business_name">Business</label><input type="text" class="form-control" id="eu_business_name" value="<?php echo $e($u['business_name']); ?>" autocomplete="off"></div>
                <div class="adm-field"><label for="eu_website_url">Website</label><input type="url" class="form-control" id="eu_website_url" value="<?php echo $e($u['website_url']); ?>" placeholder="https://example.com" autocomplete="off"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="adm-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="adm-btn adm-btn--primary" id="euSave">Save Changes</button>
            </div>
        </div>
    </div>
</div>
