<?php
class bbconnect_import {
    public function __construct() {
        if (is_admin()) {
            add_action('admin_menu', array(
                    $this,
                    'add_plugin_page'
            ));
        }
    }

    public function add_plugin_page() {
        add_submenu_page('bbconnect_options', 'Import Contacts', 'Import Contacts', 'administrator', 'bbconnect_import', array($this, 'create_admin_page'));
    }

    public function create_admin_page() {
?>
<div class="wrap">
    <h2>Import Contacts</h2>
<?php
        if (!empty($_FILES['uploadedfile']['tmp_name'])) {
            $start = microtime(true);
            $data = $this->csv_to_array($_FILES['uploadedfile']['tmp_name']);

            unset($errors);
            $errors = array();

            // show imported data
            $plural = (count($data) != 1) ? 's' : '';
            $headers = false;
            echo '<p>' . count($data) . ' record' . $plural . ' available for processing.</p>';
            $user_list = '<table class="widefat striped">';
            foreach ($data as $d) {
                if (!$headers) {
                    $user_list .= '<tr>';
                    foreach ($d as $h => $v) {
                        $user_list .= '<th>'.$h.'</th>';
                    }
                    $user_list .= '</tr>';
                    $headers = true;
                }
                $user_list .= '<tr>';
                foreach ($d as $v) {
                    $user_list .= '<td>'.$v.'</td>';
                }
                $user_list .= '</tr>';
                $e = $this->import_user($d);
                if (strlen($e) > 0) {
                    array_push($errors, $e);
                }
            }
            $user_list .= '</table>';

            $done = count($data) - count($errors);
            $plural = ($done != 1) ? 's' : '';
            $end = microtime(true);
            $time = round($end-$start, 2);
            echo '<p><strong>' . $done . ' record' . $plural . ' imported in '.$time.' seconds.</strong></p>';

            if (count($errors) > 0) {
                $plural = (count($errors) > 1) ? 's' : '';
                echo '<p>' . count($errors) . ' record' . $plural . ' could not be imported.</p>';
                echo '<textarea rows="5" cols="100">';
                print_r($errors);
                echo '</textarea>';
            }
            echo $user_list;
            echo '<a class="button" href="">Process another file</a>';
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
            <td>When processing your file, the system will attempt to locate an existing user with the provided email address. If found, the user will be <strong>updated</strong> with the details in the CSV. If not found, a new user will be created with the provided details.<br>
            <strong>If email is empty or missing, a dummy email address will be auto-generated.</strong></td>
        </tr>
        <tr>
            <th scope="row">import_id</th>
            <td><strong>Recommended</strong>. ID from external system, useful for matching subsequent imports. Will be used as a secondary matching criteria if email address doesn't produce a match.</td>
        </tr>
        <tr>
            <th scope="row">first_name</th>
            <td><strong>Recommended for new contacts</strong>. Ignored for existing contacts.</td>
        </tr>
        <tr>
            <th scope="row">last_name</th>
            <td><strong>Recommended for new contacts</strong>. Ignored for existing contacts.</td>
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
        <p><strong>Please be patient after clicking "Import". Depending on the size of your file the process can take quite some time. Please do not click the button multiple times or navigate away from this page.</strong></p>
        <input type="submit" value="Import CSV File">
    </form>
</div>
<?php
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
        if (empty($data['first_name'])) {
            $data['first_name'] = 'Unknown';
        }
        if (empty($data['last_name'])) {
            $data['last_name'] = 'Unknown';
        }

        if (empty($data['email'])) {
            $email = preg_replace('/[^0-9a-z_-]/i', '', $data['first_name'].'_'.$data['last_name'].'_');
            if (!empty($data['import_id'])) {
                $email .= $data['import_id'];
            } else {
                $email .= wp_generate_password(6, false);
            }
            $email .= '@example.com';
            $data['email'] = strtolower($email);
        }

        if (email_exists($data['email'])) { // Existing user
            $user = get_user_by('email', $data['email']);
        } elseif (!empty($data['import_id'])) { // Try looking up by import_id
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
        } else { // New user
            $user_name = wp_generate_password(8, false);
            $random_password = wp_generate_password(12, false);

            $userdata = array(
                    'user_login' => $user_name,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'user_pass' => $random_password,
                    'user_email' => $data['email'],
                    'user_nicename' => $data['first_name'],
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
                    update_user_meta($user_id, 'bbconnect_recipient_one_1', $value);
                    continue;
                    break;
                case 'address1':
                    update_user_meta($user_id, 'bbconnect_address_one_1', $value);
                    continue;
                    break;
                case 'address2':
                    update_user_meta($user_id, 'bbconnect_address_two_1', $value);
                    continue;
                    break;
                case 'suburb':
                    update_user_meta($user_id, 'bbconnect_address_city_1', $value);
                    continue;
                    break;
                case 'state':
                    update_user_meta($user_id, 'bbconnect_address_state_1', $value);
                    continue;
                    break;
                case 'postcode':
                    update_user_meta($user_id, 'bbconnect_address_postal_code_1', $value);
                    continue;
                    break;
                case 'country':
                    $country = bbconnect_process_country(ucwords(strtolower($value)));
                    if (!$country) {
                        $country = $value;
                    }
                    update_user_meta($user_id, 'bbconnect_address_country_1', $country);
                    continue;
                    break;
            }

            // Telephone is a very special case
            if (strpos($key, 'telephone') !== false) {
                list($type, $junk) = explode('_', $key);
                $phone_data = maybe_unserialize(get_user_meta($user_id, 'telephone', true));
                $phone_exists = false;
                if (is_array($phone_data)) {
                    foreach ($phone_data as $idx => $existing_phone) {
                        if (isset($existing_phone['value']) && $existing_phone['value'] == $value) {
                            $phone_data[$idx]['type'] = $type;
                            $phone_exists = true;
                            break;
                        }
                    }
                }
                if (!$phone_exists) {
                    $phone_data[] = array(
                            'value' => $value,
                            'type' => $type,
                    );
                }
                update_user_meta($user_id, 'telephone', $phone_data);
                continue;
            }

            // Everything else we just use the data key as the meta key
            update_user_meta($user_id, $key, $value);
        }

        return ''; // No error
    }
}

$bbconnect_import = new bbconnect_import();
