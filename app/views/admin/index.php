<h1 class="mb-3">Admin</h1>

<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_users">Users</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_roles">Roles</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_companies">Companies</a></li>
</ul>

<div class="tab-content pt-3">

    <div class="tab-pane fade show active" id="tab_users">
        <div class="row g-2 mb-3">
            <input type="hidden" id="user_id">
            <div class="col-md-3"><input type="text" id="user_u_name" class="form-control" placeholder="Username"></div>
            <div class="col-md-3"><input type="password" id="user_p_word" class="form-control" placeholder="Password"></div>
            <div class="col-md-3"><input type="text" id="user_first_name" class="form-control" placeholder="First name"></div>
            <div class="col-md-3"><input type="text" id="user_last_name" class="form-control" placeholder="Last name"></div>
            <div class="col-md-3"><input type="email" id="user_email" class="form-control" placeholder="Email"></div>
            <div class="col-md-3"><select id="user_role_id" class="form-control"></select></div>
            <div class="col-md-3"><select id="user_company_id" class="form-control"></select></div>
            <div class="col-md-3">
                <button type="button" id="save_user" class="btn btn-primary">Save</button>
                <button type="button" id="clear_user" class="btn btn-secondary">Clear</button>
            </div>
        </div>
        <table id="users_table" class="table table-striped" style="width:100%">
            <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Company</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="tab-pane fade" id="tab_roles">
        <div class="row g-2 mb-3">
            <input type="hidden" id="role_id">
            <div class="col-md-4"><input type="text" id="role_name" class="form-control" placeholder="Role name"></div>
            <div class="col-md-4">
                <button type="button" id="save_role" class="btn btn-primary">Save</button>
                <button type="button" id="clear_role" class="btn btn-secondary">Clear</button>
            </div>
        </div>
        <table id="roles_table" class="table table-striped" style="width:100%">
            <thead><tr><th>Role</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="tab-pane fade" id="tab_companies">
        <div class="row g-2 mb-3">
            <input type="hidden" id="company_id">
            <div class="col-md-4"><input type="text" id="company_name" class="form-control" placeholder="Company name"></div>
            <div class="col-md-4">
                <button type="button" id="save_company" class="btn btn-primary">Save</button>
                <button type="button" id="clear_company" class="btn btn-secondary">Clear</button>
            </div>
        </div>
        <table id="companies_table" class="table table-striped" style="width:100%">
            <thead><tr><th>Company</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>

</div>

<script>
$(document).ready(function() {

    var users_cache     = [];
    var roles_cache     = [];
    var companies_cache = [];

    function api(action, body, done) {
        ApiDataSvc.apiCall('post', action, body, function(data) {
            done(JSON.parse(data));
        });
    }

    function reset_table(selector) {
        if ($.fn.DataTable.isDataTable(selector)) {
            $(selector).DataTable().destroy();
        }
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function row_actions(css, id) {
        return '<td class="text-end">'
            + '<button type="button" class="btn btn-sm btn-link edit_' + css + '" data-id="' + esc(id) + '">Edit</button>'
            + '<button type="button" class="btn btn-sm btn-link text-danger delete_' + css + '" data-id="' + esc(id) + '">Delete</button>'
            + '</td>';
    }

    function load_users() {
        api('get_users', {}, function(res) {
            if (!res.success) { toastr.error(res.message); return; }
            users_cache = res.data;
            reset_table('#users_table');
            var rows = '';
            $.each(res.data, function(i, u) {
                rows += '<tr>'
                    + '<td>' + esc(u.u_name) + '</td>'
                    + '<td>' + esc(u.first_name) + ' ' + esc(u.last_name) + '</td>'
                    + '<td>' + esc(u.user_email) + '</td>'
                    + '<td>' + esc(u.role_name) + '</td>'
                    + '<td>' + esc(u.company_name) + '</td>'
                    + row_actions('user', u.user_id)
                    + '</tr>';
            });
            $('#users_table tbody').html(rows);
            $('#users_table').DataTable();
        });
    }

    function load_roles() {
        api('get_roles', {}, function(res) {
            if (!res.success) { toastr.error(res.message); return; }
            roles_cache = res.data;
            reset_table('#roles_table');
            var rows = '';
            var options = '<option value="">&mdash; role &mdash;</option>';
            $.each(res.data, function(i, r) {
                rows += '<tr><td>' + esc(r.role_name) + '</td>' + row_actions('role', r.id) + '</tr>';
                options += '<option value="' + esc(r.id) + '">' + esc(r.role_name) + '</option>';
            });
            $('#roles_table tbody').html(rows);
            $('#roles_table').DataTable();
            $('#user_role_id').html(options);
        });
    }

    function load_companies() {
        api('get_companies', {}, function(res) {
            if (!res.success) { toastr.error(res.message); return; }
            companies_cache = res.data;
            reset_table('#companies_table');
            var rows = '';
            var options = '<option value="">&mdash; company &mdash;</option>';
            $.each(res.data, function(i, c) {
                rows += '<tr><td>' + esc(c.company_name) + '</td>' + row_actions('company', c.id) + '</tr>';
                options += '<option value="' + esc(c.id) + '">' + esc(c.company_name) + '</option>';
            });
            $('#companies_table tbody').html(rows);
            $('#companies_table').DataTable();
            $('#user_company_id').html(options);
        });
    }

    load_roles();
    load_companies();
    load_users();

    // ---- users ----
    function clear_user() {
        $('#user_id, #user_u_name, #user_p_word, #user_first_name, #user_last_name, #user_email').val('');
        $('#user_role_id, #user_company_id').val('');
    }

    $('#save_user').on('click', function() {
        var user_id = $('#user_id').val();
        var body = {
            user_id:    user_id,
            u_name:     $('#user_u_name').val(),
            p_word:     $('#user_p_word').val(),
            first_name: $('#user_first_name').val(),
            last_name:  $('#user_last_name').val(),
            user_email: $('#user_email').val(),
            role_id:    $('#user_role_id').val(),
            company_id: $('#user_company_id').val()
        };
        api(user_id ? 'update_user' : 'add_user', body, function(res) {
            if (res.success) { toastr.success(res.message); clear_user(); load_users(); }
            else { toastr.error(res.message); }
        });
    });

    $('#clear_user').on('click', clear_user);

    $('#users_table').on('click', '.edit_user', function() {
        var id = String($(this).data('id'));
        var u = users_cache.filter(function(x){ return String(x.user_id) === id; })[0];
        if (!u) { return; }
        $('#user_id').val(u.user_id);
        $('#user_u_name').val(u.u_name);
        $('#user_p_word').val('');
        $('#user_first_name').val(u.first_name);
        $('#user_last_name').val(u.last_name);
        $('#user_email').val(u.user_email);
        $('#user_role_id').val(u.role_id || '');
        $('#user_company_id').val(u.company_id || '');
    });

    $('#users_table').on('click', '.delete_user', function() {
        api('delete_user', { user_id: $(this).data('id') }, function(res) {
            if (res.success) { toastr.success(res.message); load_users(); }
            else { toastr.error(res.message); }
        });
    });

    // ---- roles ----
    function clear_role() { $('#role_id, #role_name').val(''); }

    $('#save_role').on('click', function() {
        var id = $('#role_id').val();
        api(id ? 'update_role' : 'add_role', { id: id, role_name: $('#role_name').val() }, function(res) {
            if (res.success) { toastr.success(res.message); clear_role(); load_roles(); }
            else { toastr.error(res.message); }
        });
    });

    $('#clear_role').on('click', clear_role);

    $('#roles_table').on('click', '.edit_role', function() {
        var id = String($(this).data('id'));
        var r = roles_cache.filter(function(x){ return String(x.id) === id; })[0];
        if (!r) { return; }
        $('#role_id').val(r.id);
        $('#role_name').val(r.role_name);
    });

    $('#roles_table').on('click', '.delete_role', function() {
        api('delete_role', { id: $(this).data('id') }, function(res) {
            if (res.success) { toastr.success(res.message); load_roles(); }
            else { toastr.error(res.message); }
        });
    });

    // ---- companies ----
    function clear_company() { $('#company_id, #company_name').val(''); }

    $('#save_company').on('click', function() {
        var id = $('#company_id').val();
        api(id ? 'update_company' : 'add_company', { id: id, company_name: $('#company_name').val() }, function(res) {
            if (res.success) { toastr.success(res.message); clear_company(); load_companies(); }
            else { toastr.error(res.message); }
        });
    });

    $('#clear_company').on('click', clear_company);

    $('#companies_table').on('click', '.edit_company', function() {
        var id = String($(this).data('id'));
        var c = companies_cache.filter(function(x){ return String(x.id) === id; })[0];
        if (!c) { return; }
        $('#company_id').val(c.id);
        $('#company_name').val(c.company_name);
    });

    $('#companies_table').on('click', '.delete_company', function() {
        api('delete_company', { id: $(this).data('id') }, function(res) {
            if (res.success) { toastr.success(res.message); load_companies(); }
            else { toastr.error(res.message); }
        });
    });

});
</script>
