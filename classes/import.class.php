<?php
class bbconnect_import {
    private $upload_dir;
    private $option_name = 'bbconnect_import_stats';

    const STATUS_WAITING = 1; // Uploaded but not yet processed
    const STATUS_IN_PROGRESS = 2; // Partially processed
    const STATUS_PROCESSING = 3; // Actively being processed
    const STATUS_PENDING = 4; // Processed but not yet reported to the user
    const STATUS_COMPLETE = 5; // All done!

    public function __construct() {
        // Make sure our upload directory exists
        $wp_uploads = wp_get_upload_dir();
        $this->upload_dir = trailingslashit($wp_uploads['basedir']).'bbconnect-import/';
        if (!is_dir($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }

        if (is_admin()) {
            add_filter('bbconnect_push_menu', array($this, 'add_menu_item'));
        }
        if ($this->is_processing_file() && (!wp_doing_ajax() || $_REQUEST['action'] != 'bbconnect_import_do_import')) {
            add_action('shutdown', array($this, 'process_file'));
        }

        add_action('wp_ajax_bbconnect_import_do_import', array($this, 'ajax_do_import'));
        add_action('wp_ajax_nopriv_bbconnect_import_do_import', array($this, 'ajax_do_import'));
        add_action('wp_ajax_bbconnect_import_get_current_progress', array($this, 'ajax_get_current_progress'));
    }

    public function add_menu_item($menu_items) {
        $menu_items['bbconnect_import'] = add_submenu_page('bbconnect_options', 'Import Contacts', 'Import Contacts', 'add_users', 'bbconnect_import', array($this, 'create_admin_page'));
        return $menu_items;
    }

    public function create_admin_page() {
?>
<div class="wrap">
    <h2>Import Contacts</h2>
<?php
        if ($this->is_processing_file()) {
            $this->progress_page();
        } elseif ($this->is_pending_file()) {
            $this->result_page();
        } else {
            $this->upload_page();
        }
?>
</div>
<?php
    }

    private function upload_page() {
        if (!empty($_FILES['uploadedfile']['tmp_name'])) {
            $filename = $_FILES['uploadedfile']['name'];
            $unique_filename = wp_unique_filename($this->upload_dir, $filename);
            $file_path = $this->upload_dir.$unique_filename;
            if (move_uploaded_file($_FILES['uploadedfile']['tmp_name'], $file_path)) {
                $data = $this->csv_to_array($file_path);
                $record_count = count($data);
                $this->add_file($filename, $file_path, $record_count);
                echo '<p><strong>'.$filename.'</strong> containing '.$record_count.' records uploaded successfully. Processing of this file will begin momentarily.</p>'."\n";
                echo '<script>window.setTimeout("window.location.reload()", 5000);</script>'."\n";
            } else {
                echo '<p>An error occured while attempting to save the uploaded file.</p>'."\n";
                echo '<p><a href="">Try again?</a></p>';
            }
        } else {
            if (!empty($_FILES['uploadedfile']['error'])) {
                $upload_error_strings = array(
                        false,
                        __('The uploaded file exceeds the upload_max_filesize directive in php.ini.'),
                        __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.'),
                        __('The uploaded file was only partially uploaded.'),
                        __('No file was uploaded.'),
                        '',
                        __('Missing a temporary folder.'),
                        __('Failed to write file to disk.'),
                        __('File upload stopped by extension.'),
                );
?>
    <div class="error"><p><strong>There was an error uploading your file:</strong> <?php echo $upload_error_strings[$_FILES['uploadedfile']['error']]; ?></p></div>
<?php
            }
?>
    <p>Upload a CSV file containing your contacts from another system to import them into Connexions. Your CSV file must contain one record per line, with the first line of the file containing the field names.</p>
    <p>The following special fields are recognised:</p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Field Name</th>
                <th>Details</th>
            </tr>
        </thead>
        <tr>
            <th scope="row">email</th>
            <td>When processing your file, the system will attempt to locate an existing user with the provided email address. If not found, a new user will be created with the provided details. If an existing user is found, the system will then compare last names. If this also matches, the user will be <strong>updated</strong> with the details in the CSV. If the last names do not match, the existing user will be updated with a dummy email address and a new user created with the uploaded details.<br>
            <strong>If email is empty or missing, a dummy email address will be auto-generated.</strong></td>
        </tr>
        <tr>
            <th scope="row">import_id</th>
            <td><strong>Recommended</strong>. ID from external system, useful for matching subsequent imports. Will be used as a secondary matching criteria if email address doesn't produce a match.</td>
        </tr>
        <tr>
            <th scope="row">first_name</th>
            <td><strong>Strongly recommended for new contacts</strong>.</td>
        </tr>
        <tr>
            <th scope="row">last_name</th>
            <td><strong>Strongly recommended for new contacts</strong>. Used as a secondary matching criteria when a matching email address is found (see above).</td>
        </tr>
        <tr>
            <th scope="row">password</th>
            <td><strong>Optional. Recommended for new contacts if they require the ability to log in.</strong>. If not supplied a random password will be assigned (but not recorded) for new contacts. Ignored for existing contacts.</td>
        </tr>
        <tr>
            <th scope="row">addressee</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row">address1</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row">address2</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row">suburb</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row">state</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row">postcode</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row">country</th>
            <td></td>
        </tr>
        <tr>
            <th scope="row"><em>type</em>_telephone</th>
            <td>One or more phone number fields. By default <em>type</em> should be one of 'home', 'work', 'mobile' or 'other'; however additional types can be configured via <a href="<?php echo admin_url('admin.php?page=bbconnect_meta_options'); ?>" target="_blank">Manage Fields</a>.</td>
        </tr>
    </table>
    <p><strong>IMPORTANT:</strong> All other fields should match the field names set up in the system. See the <a href="<?php echo admin_url('admin.php?page=bbconnect_meta_options'); ?>" target="_blank">Manage Fields</a> page to view the defined fields.</p>
    <form enctype="multipart/form-data" action="#" method="POST">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo wp_max_upload_size(); ?>">
        <p><label>Choose a file to upload: <input name="uploadedfile" type="file"></label></p>
        <input type="submit" class="button" value="Import CSV File">
    </form>
<?php
        }
    }

    private function progress_page() {
        $details = $this->get_current_progress();
        $success_count = $details['result']['success_count'];
        $fail_count = $details['result']['fail_count'];
        $processed_count = $success_count+$fail_count;
        $total_count = $details['result']['total_count'];
        $fraction = $processed_count/$total_count;
        $percent = floor($fraction*100);
?>
        <p>Processing <strong><?php echo $details['filename']; ?></strong> uploaded on <?php echo $details['added']; ?></p>
        <style>
        .progress {
            width: 100%;
            height: 50px;
        }
        .progress-wrap {
            background: #25AAE1;
            margin: 20px 0;
            overflow: hidden;
            position: relative;
        }
        .progress-bar {
            background: #ddd;
            left: <?php echo $percent; ?>%;
            position: absolute;
            top: 0;
        }
        .progress-text {
            text-align: center;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 5;
        }
        </style>
        <div class="progress-wrap progress">
            <div class="progress-bar progress"></div>
            <p class="progress-text progress"><span class="percent"><?php echo $percent; ?></span>% complete</p>
        </div>
        <p style="text-align: center;" id="progress_message"><span class="processed"><?php echo $processed_count; ?></span> of <span class="total"><?php echo $total_count; ?></span> records processed.</p>
        <p><strong>The import system has been designed to continue processing in the background even if you navigate away from this page. However we have found that on some systems this does not work - if that is the case just leave this page open and the import process will run as expected.</strong></p>
        <script>
            jQuery(document).ready(function() {
                window.setTimeout('bbconnect_import_get_progress()', 1000);
                window.setTimeout('bbconnect_import_do_import()', 1000);
            });
            function bbconnect_import_get_progress() {
                var data = {
                        action: 'bbconnect_import_get_current_progress'
                }
                jQuery.post(ajaxurl, data, function(response) {
                    if (typeof response.result != 'undefined') {
                        // Grab the relevant values
                        var total = response.result.total_count;
                        var success = response.result.success_count;
                        var fail = response.result.fail_count;
                        var processed = success+fail;
                        var fraction = processed/total;
                        var percent = Math.floor(fraction*100);

                        // Update the display
                        var elem_processed = jQuery('.processed');
                        jQuery({Counter: elem_processed.text()}).animate({Counter: processed}, {
                            duration: 500,
                            easing: 'swing',
                            step: function () {
                                elem_processed.text(Math.ceil(this.Counter));
                            }
                        });
                        var elem_percent = jQuery('.percent');
                        jQuery({Counter: elem_percent.text()}).animate({Counter: percent}, {
                            duration: 500,
                            easing: 'swing',
                            step: function () {
                                elem_percent.text(Math.ceil(this.Counter));
                            }
                        });
                        jQuery('.progress-bar').animate({left: percent+'%'}, 500);
                        if (processed >= total) {
                            jQuery('#progress_message').text('Import complete. Please wait a moment...');
                            window.location.reload();
                        }
                    }
                    window.setTimeout('bbconnect_import_get_progress()', 1000);
                });
            }
            function bbconnect_import_do_import() {
                data = {
                        action: 'bbconnect_import_do_import'
                }
                jQuery.post(ajaxurl, data).always(function () {
                    window.setTimeout('bbconnect_import_do_import()', 10000);
                });
            }
        </script>
<?php
    }

    private function result_page() {
        $details = $this->get_current_progress();
        $success_count = $details['result']['success_count'];
        $fail_count = $details['result']['fail_count'];
        $processed_count = $success_count+$fail_count;
        $total_count = $details['result']['total_count'];
        $errors = $details['result']['errors'];
?>
        <p><strong><?php echo $details['filename']; ?></strong> uploaded on <?php echo $details['added']; ?> has been imported.</p>
        <ul>
            <li>Total Records: <strong><?php echo $total_count; ?></strong></li>
            <li>Successully Imported: <strong><?php echo $success_count; ?></strong></li>
            <li>Not Imported: <strong><?php echo $fail_count; ?></strong></li>
        </ul>
<?php
        if (!empty($errors)) {
            echo '<h3>Errors</h3>'."\n";
            echo '<ul>'."\n";
            foreach ($errors as $error) {
                echo '<li>'.$error.'</li>'."\n";
            }
            echo '</ul>'."\n";
        }

        echo '<p><a href="" class="button">Continue</a>'."\n";

        $details['status'] = self::STATUS_COMPLETE;
        $this->update_progress($details);
    }

    private function add_file($filename, $file_path, $count) {
        $details = array(
                'filename' => $filename,
                'path' => $file_path,
                'added' => current_time('mysql'),
                'updated' => current_time('mysql'),
                'status' => self::STATUS_WAITING,
                'pos' => 0,
                'result' => array(
                        'total_count' => $count,
                        'success_count' => 0,
                        'fail_count' => 0,
                        'errors' => array(),
                ),
        );
        $files = get_option($this->option_name);
        $files[] = $details;
        update_option($this->option_name, $files);
    }

    public function process_file() {
        if ($this->is_processing_file()) {
            $data = array(
                    'action' => 'bbconnect_import_do_import',
            );

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, admin_url('admin-ajax.php?action=bbconnect_import_do_import'));
            curl_setopt($ch, CURLOPT_REFERER, admin_url('admin.php?page=bbconnect_import'));
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 10);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest', 'Content-Type: application/json; charset=utf-8'));
            curl_exec($ch);
            curl_close($ch);
        }
    }

    public function ajax_do_import() {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_clean();
            ignore_user_abort(true);
            if (wp_doing_ajax()) {
                header("Connection: close\r\n");
                header("Content-Encoding: none\r\n");
                header("Content-Length: 0");
            }
            flush();
            if (session_id()) {
                session_write_close();
            }
        }
        if (false !== ($details = $this->get_current_progress())) {
            if ($details['status'] == self::STATUS_IN_PROGRESS || ($details['status'] == self::STATUS_PROCESSING && current_time('timestamp')-strtotime($details['updated']) > MINUTE_IN_SECONDS)) { // If not actively processing or it hasn't been updated in over a minute
                $details['status'] = self::STATUS_PROCESSING;
                $this->update_progress($details);
                $data = $this->csv_to_array($details['path']);


                $n = 0;
                while (array_key_exists($details['pos'], $data) && $n < 100) {
                    $d = $data[$details['pos']];
                    $e = $this->import_user($d);
                    if (!empty($e)) {
                        array_push($details['result']['errors'], $e);
                        $details['result']['fail_count']++;
                    } else {
                        $details['result']['success_count']++;
                    }
                    $details['pos']++;
                    $n++;
                    $this->update_progress($details);
                }

                if ($details['result']['success_count']+$details['result']['fail_count'] >= $details['result']['total_count']) {
                    $details['status'] = self::STATUS_PENDING;
                } else {
                    $details['status'] = self::STATUS_IN_PROGRESS;
                }
                $this->update_progress($details);
                if (wp_doing_ajax()) {
                    die($n.' records processed');
                }
            }
        }
        if (wp_doing_ajax()) {
            die('Nothing to do');
        }
    }

    private function csv_to_array($filename = '', $delimiter = ',') {
        if (!file_exists($filename) || !is_readable($filename)) {
            return false;
        }

        $header = null;
        $data = array();
        $line_endings = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', true);
        if (($file = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($file)) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($file);
        }
        ini_set('auto_detect_line_endings', $line_endings);

        return $data;
    }

    private function import_user($data) {
        // Set some defaults
        if (empty($data['first_name'])) {
            $data['first_name'] = 'Unknown';
        }
        if (empty($data['last_name'])) {
            $data['last_name'] = 'Unknown';
        }
        if (empty($data['email'])) {
            $data['email'] = $this->generate_email($data);
        }
        $role = !empty($data['role']) ? $data['role'] : get_option('default_role');
        unset($data['role']);

        // Core matching logic
        if (email_exists($data['email'])) { // Existing user
            $user = get_user_by('email', $data['email']);
            if (strcasecmp($user->user_lastname, $data['last_name']) !== 0) {
                /*
                 * Same email but different surnames
                 * We're going to assume then that this is a different contact with the same email address - e.g. multiple people using a generic company email
                 */

                // So we move the email address to the additional emails field
                bbconnect_maybe_add_additional_email($user->ID, $data['email'], 'archive');

                // And update the email address on this user to a dummy email
                $user->user_email = $this->generate_email(array('first_name' => $user->user_firstname, 'last_name' => $user->user_lastname, 'import_id' => get_user_meta($user->ID, 'import_id', true)));
                wp_update_user($user);

                // Now put aside that user so we can locate or add a different record
                unset($user);
            }
        }

        if (!($user instanceof WP_User) && !empty($data['import_id'])) { // Try looking up by import_id
            $args = array(
                    'meta_query' => array(
                            array(
                                    'key' => 'import_id',
                                    'value' => $data['import_id'],
                            ),
                    ),
            );
            $users = get_users($args);
            if (count($users) > 0) {
                $user = array_shift($users);
            }
        }

        if ($user instanceof WP_User) {
            $user_id = $user->ID;
            global $blog_id;
            if (is_multisite() && !is_user_member_of_blog($user_id, $blog_id)) {
                // Make sure user is a member of this site
                add_user_to_blog($blog_id, $user_id, $role);
            }
            $user->user_firstname = $data['first_name'];
            wp_update_user($user);
        } else { // New user
            $user_name = wp_generate_password(8, false);
            $user_pass = empty($data['password']) ? wp_generate_password(12, false) : $data['password'];
            unset($data['password']);

            $userdata = array(
                    'user_login' => $user_name,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'user_pass' => $user_pass,
                    'user_email' => $data['email'],
                    'user_nicename' => $data['first_name'],
                    'role' => $role,
            );
            if (!empty($data['user_registered'])) {
                $userdata['user_registered'] = $data['user_registered'];
            }
            $user_id = wp_insert_user($userdata);

            // On fail
            if (is_wp_error($user_id)) {
                return $data['email'].': Error creating user - '.$user_id->get_error_message();
            }

            update_user_meta($user_id, 'bbconnect_bbc_primary', 'address_1');
            update_user_meta($user_id, 'bbconnect_source', 'import');
        }

        unset($data['email'], $data['first_name'], $data['last_name'], $data['user_registered']);

        // Now we can put the rest of the data into the user meta
        foreach ($data as $key => $value) {
            $value = trim($value);
            if (empty($value)) { // We don't ever want to save an empty value
                continue;
            }
            // Address we allow simplified headers to make it easier
            switch ($key) {
                case 'addressee':
                    update_user_meta($user_id, 'bbconnect_address_recipient_1', $value);
                    continue(2);
                    break;
                case 'address1':
                    update_user_meta($user_id, 'bbconnect_address_one_1', $value);
                    continue(2);
                    break;
                case 'address2':
                    update_user_meta($user_id, 'bbconnect_address_two_1', $value);
                    continue(2);
                    break;
                case 'suburb':
                    update_user_meta($user_id, 'bbconnect_address_city_1', $value);
                    continue(2);
                    break;
                case 'state':
                    update_user_meta($user_id, 'bbconnect_address_state_1', $value);
                    continue(2);
                    break;
                case 'postcode':
                    update_user_meta($user_id, 'bbconnect_address_postal_code_1', $value);
                    continue(2);
                    break;
                case 'country':
                    $country = bbconnect_process_country(ucwords(strtolower($value)));
                    if (!$country) {
                        $country = $value;
                    }
                    update_user_meta($user_id, 'bbconnect_address_country_1', $country);
                    continue(2);
                    break;
            }

            // Telephone and Additional Email are very special cases
            if (strpos($key, 'telephone') !== false) {
                list($type, $junk) = explode('_', $key);
                bbconnect_maybe_add_telephone($user_id, $value, $type);
                continue;
            } elseif (strpos($key, 'additional_email') !== false) {
                list($type, $junk) = explode('_', $key);
                bbconnect_maybe_add_additional_email($user_id, $value, $type);
                continue;
            }

            // Everything else we just use the data key as the meta key
            update_user_meta($user_id, $key, $value);
        }

        return ''; // No error
    }

    /**
     * Generate a dummy email address
     * @param array $data Array containing first_name, last_name and optionally import_id
     * @return string Generated email address
     */
    private function generate_email(array $data) {
        $email = preg_replace('/[^0-9a-z_-]/i', '', $data['first_name'].'_'.$data['last_name'].'_');
        if (!empty($data['import_id'])) {
            $email .= $data['import_id'];
        } else {
            $email .= wp_generate_password(6, false);
        }
        $email .= '@example.com';
        return strtolower($email);
    }

    private function get_current_progress() {
        $history = get_option($this->option_name);
        if (is_array($history)) {
            foreach ($history as &$details) {
                if (in_array($details['status'], array(self::STATUS_WAITING, self::STATUS_IN_PROGRESS, self::STATUS_PROCESSING, self::STATUS_PENDING))) {
                    if ($details['status'] == self::STATUS_WAITING) {
                        $details['status'] = self::STATUS_IN_PROGRESS;
                        update_option($this->option_name, $history);
                    }
                    return $details;
                }
            }
        }
        return false;
    }

    public function ajax_get_current_progress() {
        header('Content-type: application/json');
        echo json_encode($this->get_current_progress());
        die();
    }

    private function update_progress($details) {
        $history = get_option($this->option_name);
        if (is_array($history)) {
            foreach ($history as $idx => $history_details) {
                if ($details['path'] == $history_details['path']) {
                    $details['updated'] = current_time('mysql');
                    $history[$idx] = $details;
                    update_option($this->option_name, $history);
                }
            }
        }
    }

    private function is_processing_file() {
        $history = get_option($this->option_name);
        if (is_array($history)) {
            foreach ($history as $details) {
                if (in_array($details['status'], array(self::STATUS_WAITING, self::STATUS_IN_PROGRESS, self::STATUS_PROCESSING))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function is_pending_file() {
        $history = get_option($this->option_name);
        if (is_array($history)) {
            foreach ($history as $details) {
                if ($details['status'] == self::STATUS_PENDING) {
                    return true;
                }
            }
        }
        return false;
    }
}

$bbconnect_import = new bbconnect_import();
