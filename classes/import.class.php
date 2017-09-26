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
            $data = $this->csv_to_array($_FILES['uploadedfile']['tmp_name']);

            unset($errors);
            $errors = array();

            // show imported data
            $plural = (count($data) != 1) ? 's' : '';
            $headers = false;
            echo '<p>' . count($data) . ' record' . $plural . ' available for processing.</p>';
            echo '<table class="widefat striped">';
            foreach ($data as $d) {
                if (!$headers) {
                    echo '<tr>';
                    foreach ($d as $h => $v) {
                        echo '<th>'.$h.'</th>';
                    }
                    echo '</tr>';
                    $headers = true;
                }
                echo '<tr>';
                foreach ($d as $v) {
                    echo '<td>'.$v.'</td>';
                }
                echo '</tr>';
                $e = $this->import_user($d);
                if (strlen($e) > 0) {
                    array_push($errors, $e);
                }
            }
            echo '</table>';
            if (count($errors) > 0) {
                $plural = (count($errors) > 1) ? 's' : '';
                echo '<p>' . count($errors) . ' record' . $plural . ' could not be imported.</p>';
                echo '<textarea rows="5" cols="100">';
                print_r($errors);
                echo '</textarea>';
            }

            $done = count($data) - count($errors);
            $plural = ($done != 1) ? 's' : '';
            echo '<p><strong>' . $done . ' record' . $plural . ' imported.</strong></p>';
            echo '<a class="button" href="">Process another file</a>';
        } else {
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
            <th scope="col">email</th>
            <td><strong>Required</strong>. When processing your file, the system will attempt to locate an existing user with the provided email address. If found, the user will be updated with the details in the CSV. If not found, a new user will be created with the provided details.</td>
        </tr>
        <tr>
            <th scope="col">firstname</th>
            <td><strong>Required for new contacts</strong>. Ignored for existing contacts.</td>
        </tr>
        <tr>
            <th scope="col">lastname</th>
            <td><strong>Required for new contacts</strong>. Ignored for existing contacts.</td>
        </tr>
        <tr>
            <th scope="col">address1</th>
            <td></td>
        </tr>
        <tr>
            <th scope="col">address2</th>
            <td></td>
        </tr>
        <tr>
            <th scope="col">suburb</th>
            <td></td>
        </tr>
        <tr>
            <th scope="col">state</th>
            <td></td>
        </tr>
        <tr>
            <th scope="col">postcode</th>
            <td></td>
        </tr>
        <tr>
            <th scope="col">country</th>
            <td></td>
        </tr>
        <tr>
            <th scope="col"><em>type</em>_telephone</th>
            <td>One or more phone number fields. By default <em>type</em> should be one of 'home', 'work', 'mobile' or 'other'; however additional types can be configured via <a href="<?php echo admin_url('admin.php?page=bbconnect_meta_options'); ?>" target="_blank">Manage Fields</a>.</td>
        </tr>
    </table>
    <p><strong>IMPORTANT:</strong> All other fields should match the field names set up in the system. See the <a href="<?php echo admin_url('admin.php?page=bbconnect_meta_options'); ?>" target="_blank">Manage Fields</a> page to view the defined fields.</p>
    <form enctype="multipart/form-data" action="#" method="POST">
        <input type="hidden" name="MAX_FILE_SIZE" value="100000">
        <p><label>Choose a file to upload: <input name="uploadedfile" type="file"></label></p>
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
        if (empty($data['email'])) {
            return 'You must specify an email address';
        }

        if (email_exists($data['email'])) { // Existing user
            $user = get_user_by('email', $data['email']);
            $user_id = $user->ID;
            $active = get_user_meta($user_id, 'active', true);
            if ($active == 'false') {
                update_user_meta($user_id, 'receives_letters', 'true');
                update_user_meta($user_id, 'receives_newsletters', 'true');
                update_user_meta($user_id, 'active', 'true');
            }
        } else { // New user
            $user_name = wp_generate_password(8, false);
            $random_password = wp_generate_password(12, false);

            if (empty($data['firstname']) || empty($data['lastname'])) {
                return 'First name and last name are required for new users';
            }

            $userdata = array(
                    'user_login' => $user_name,
                    'first_name' => $data['firstname'],
                    'last_name' => $data['lastname'],
                    'user_pass' => $random_password,
                    'user_email' => $data['email'],
                    'user_nicename' => $data['firstname'],
            );
            $user_id = wp_insert_user($userdata);

            // On fail
            if (is_wp_error($user_id)) {
                return 'Error creating user: '.$user_id->get_error_message();
            }

            update_user_meta($user_id, 'active', 'true');
            update_user_meta($user_id, 'receives_letters', 'true');
            update_user_meta($user_id, 'receives_newsletters', 'true');
            update_user_meta($user_id, 'bbconnect_bbc_primary', 'address_1');
        }

        unset($data['email'], $data['firstname'], $data['lastname']);

        // Now we can put the rest of the data into the user meta
        foreach ($data as $key => $value) {
            if (empty($value)) { // We don't ever want to save an empty value
                continue;
            }
            // Address we allow simplified headers to make it easier
            switch ($key) {
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
                    $country = bbconnect_process_country($value);
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
