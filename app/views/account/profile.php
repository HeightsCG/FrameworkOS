<script>
$(function () {

    $('#save_profile').on('click', function () {

        if (empty($('#first_name').val())) {
            toastr.error('First name is required');
            return;
        }
        
        if (empty($('#last_name').val())) {
            toastr.error('Last name is required');
            return;
        }

        if (empty($('#user_email').val())) {
            toastr.error('Email is required');
            return;
        }

        if (empty($('#u_name').val())) {
            toastr.error('Username is required');
            return;
        }

        ApiDataSvc.apiCall('post', 'update_profile', {
            first_name: $('#first_name').val(),
            last_name:  $('#last_name').val(),
            user_email: $('#user_email').val(),
            user_phone: $('#user_phone').val(),
            u_name: $('#u_name').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('#update_password').on('click', function () {

        if (empty($('#current_password').val())) {
            toastr.error('Current password is required');
            return;
        }
        
        if (empty($('#new_password').val())) {
            toastr.error('New password is required');
            return;
        }
        
        if (empty($('#confirm_password').val())) {
            toastr.error('Confirm password is required');
            return;
        }

        if ($('#new_password').val() !== $('#confirm_password').val()) {
            toastr.error('New passwords do not match');
            return;
        }

        ApiDataSvc.apiCall('post', 'change_password', {
            current_password: $('#current_password').val(),
            p_word:           $('#new_password').val(),
            confirm_password: $('#confirm_password').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                $('#current_password, #new_password, #confirm_password').val('');
            } else {
                toastr.error(o.message);
            }
        });
    });
});
</script>

<div class="container">
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="form-floating"> 
                <input type="text" class="form-control" id="first_name" value="<?php echo htmlspecialchars((string) Session::get('first_name'), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="first_name">First Name</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-floating"> 
                <input type="text" class="form-control" id="last_name" value="<?php echo htmlspecialchars((string) Session::get('last_name'), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="last_name">Last Name</label>
            </div>
        </div>
    </div>
    <div class="row mb-3">
        <div class="col-md-4">
            <div class="form-floating"> 
                <input type="email" class="form-control" id="user_email" value="<?php echo htmlspecialchars((string) Session::get('user_email'), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="user_email">Email Address</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating"> 
                <input type="text" class="form-control" id="user_phone" value="<?php echo htmlspecialchars((string) Session::get('user_phone'), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="user_phone">Phone Number</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating"> 
                <input type="text" class="form-control" id="u_name" value="<?php echo htmlspecialchars((string) Session::get('u_name'), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="u_name">Username</label>
            </div>
        </div>
    </div>
    <div class="row mb-3">
        <div class="col-md-12">
            <button type="button" class="btn btn-primary" id="save_profile">Save</button>
        </div>
    </div>
</div>