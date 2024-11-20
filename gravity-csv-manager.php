<?php
/*
Plugin Name: Gravity Forms CSV Exporter
Description: Export Gravity Form submissions to a CSV file in the uploads/form-data directory.
Version: 1.6.5
Author: StratLab Marketing
Author URI: https://strategylab.ca
Text Domain: gravity-csv-manager
Requires at least: 6.0
Requires PHP: 7.0
Update URI: https://github.com/carterfromsl/gravity-csv-manager/
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Connect with the StratLab Auto-Updater for plugin updates
add_action('plugins_loaded', function() {
    if (class_exists('StratLabUpdater')) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $plugin_file = __FILE__;
        $plugin_data = get_plugin_data($plugin_file);

        do_action('stratlab_register_plugin', [
            'slug' => plugin_basename($plugin_file),
            'repo_url' => 'https://api.github.com/repos/carterfromsl/gravity-csv-manager/releases/latest',
            'version' => $plugin_data['Version'], 
            'name' => $plugin_data['Name'],
            'author' => $plugin_data['Author'],
            'homepage' => $plugin_data['PluginURI'],
            'description' => $plugin_data['Description'],
            'access_token' => '', // Add if needed for private repo
        ]);
    }
});

class GF_CSV_Exporter {

    public function __construct() {
        add_action('gform_after_submission', [$this, 'export_to_csv'], 10, 2);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'process_form_submission']);
    }

    // Hook into Gravity Form submission
    public function export_to_csv($entry, $form) {
        $form_id = $form['id'];

        // Get saved settings for form and fields
        $settings = get_option('gf_csv_exporter_settings', []);

        if (!isset($settings[$form_id])) {
            return;
        }

        $fields_to_export = $settings[$form_id]['fields'];
        $strip_html = isset($settings[$form_id]['strip_html']) ? (bool) $settings[$form_id]['strip_html'] : false;
        $strip_punctuation = isset($settings[$form_id]['strip_punctuation']) ? (bool) $settings[$form_id]['strip_punctuation'] : false;
        $entry_limit = isset($settings[$form_id]['entry_limit']) ? absint($settings[$form_id]['entry_limit']) : null;

        if (empty($fields_to_export)) {
            return;
        }

        // Get the CSV path
        $csv_path = $this->get_csv_path($form_id);

        // Rebuild the CSV from the most recent entries up to the limit (or all entries if no limit)
        $this->regenerate_csv($form_id, $fields_to_export, $strip_html, $strip_punctuation, $entry_limit);
    }

    // Ensure /form-data/ directory exists and get CSV file path
    private function get_csv_path($form_id) {
        $upload_dir = wp_upload_dir();
        $form_data_dir = trailingslashit($upload_dir['basedir']) . 'form-data';

        // Check if the form-data directory exists, if not, create it
        if (!file_exists($form_data_dir)) {
            wp_mkdir_p($form_data_dir);
        }

        // Set the path for the CSV file
        return trailingslashit($form_data_dir) . "gf_form_{$form_id}_submissions.csv";
    }

    // Generate the public URL to access the CSV file
    private function get_csv_url($form_id) {
        $upload_dir = wp_upload_dir();
        $form_data_dir_url = trailingslashit($upload_dir['baseurl']) . 'form-data';
        return trailingslashit($form_data_dir_url) . "gf_form_{$form_id}_submissions.csv";
    }

    // Register the admin menu for the plugin
    public function register_admin_page() {
        add_submenu_page(
            'tools.php',                  // Parent slug - we attach it under "Tools"
            'GF CSV Exporter',             // Page title
            'GF CSV Exporter',             // Menu title
            'manage_options',              // Capability
            'gf-csv-exporter',             // Menu slug
            [$this, 'admin_page_content']  // Callback function to display the page content
        );
    }

    // Render the admin page
    public function admin_page_content() {
        $forms = GFAPI::get_forms();
        $settings = get_option('gf_csv_exporter_settings', []);
        $form_id_to_edit = isset($_GET['edit_form_id']) ? absint($_GET['edit_form_id']) : null;
        ?>
        <div class="wrap">
            <h1>Gravity Forms CSV Exporter</h1>
            <form method="post" action="">
                <input type="hidden" name="action" value="save_settings">
                <?php wp_nonce_field('gf_csv_exporter_save_settings'); ?>
                <h2>Select Form and Fields</h2>
                <select name="form_id" id="form_id">
                    <option value="">Select a Form</option>
                    <?php foreach ($forms as $form): ?>
                        <option value="<?php echo $form['id']; ?>" <?php selected($form['id'], $form_id_to_edit); ?>><?php echo esc_html($form['title']); ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($form_id_to_edit): ?>
                    <?php
                    $form = GFAPI::get_form($form_id_to_edit);
                    $selected_fields = isset($settings[$form_id_to_edit]['fields']) ? $settings[$form_id_to_edit]['fields'] : [];
                    $strip_html = isset($settings[$form_id_to_edit]['strip_html']) ? (bool) $settings[$form_id_to_edit]['strip_html'] : false;
                    $strip_punctuation = isset($settings[$form_id_to_edit]['strip_punctuation']) ? (bool) $settings[$form_id_to_edit]['strip_punctuation'] : false;
                    $entry_limit = isset($settings[$form_id_to_edit]['entry_limit']) ? absint($settings[$form_id_to_edit]['entry_limit']) : '';
                    ?>
                    <h3>Select Fields</h3>
                    <?php foreach ($form['fields'] as $field): ?>
                        <label>
                            <input type="checkbox" name="fields[]" value="<?php echo esc_attr($field->id); ?>" <?php checked(in_array($field->id, $selected_fields)); ?>>
                            <?php echo esc_html($field->label); ?>
                        </label><br>
                    <?php endforeach; ?>

                    <h3>Strip HTML</h3>
                    <label>
                        <input type="checkbox" name="strip_html" value="1" <?php checked($strip_html); ?>>
                        Strip HTML from field values before exporting to CSV.
                    </label><br>
                
                    <h3>Remove Punctuation</h3>
                    <label>
                        <input type="checkbox" name="strip_punctuation" value="1" <?php checked($strip_punctuation); ?>>
                        Remove punctuation from field values before exporting to CSV.
                    </label><br>

                    <h3>Number of Entries</h3>
                    <label>
                        <input type="number" name="entry_limit" value="<?php echo esc_attr($entry_limit); ?>" min="1" placeholder="Leave blank for no limit">
                        Limit the number of entries in the CSV. (Optional)
                    </label><br>
                <?php endif; ?>
                
                <br><input type="submit" class="button-primary" value="Save Settings">
            </form>

            <h2>Exported CSV Files</h2>
            <?php if (!empty($settings)): ?>
                <ul>
                    <?php foreach ($settings as $form_id => $config): ?>
                        <?php
                        $csv_url = $this->get_csv_url($form_id);
                        ?>
                        <li>
                            Form ID <?php echo $form_id; ?>: <a href="<?php echo esc_url($csv_url); ?>" target="_blank">View CSV</a>

                            <!-- "Delete Form Data" Button -->
                            <form method="post" action="" style="display:inline;">
                                <input type="hidden" name="action" value="delete_form_data">
                                <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
                                <?php wp_nonce_field('gf_csv_exporter_delete_form_data'); ?>
                                <input type="submit" class="button-secondary" value="Delete Form Data" onclick="return confirm('Are you sure you want to delete this form\'s CSV and uncheck all fields? This action is irreversible.')">
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No CSV files generated yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // Handle form submission from admin page
    public function process_form_submission() {
        // Handle save settings
        if (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
            check_admin_referer('gf_csv_exporter_save_settings');
            $form_id = absint($_POST['form_id']);
            $fields = isset($_POST['fields']) ? array_map('absint', $_POST['fields']) : [];
            $strip_html = isset($_POST['strip_html']) ? 1 : 0;
            $strip_punctuation = isset($_POST['strip_punctuation']) ? 1 : 0;
            $entry_limit = isset($_POST['entry_limit']) ? absint($_POST['entry_limit']) : null;

            if ($form_id && !empty($fields)) {
                $settings = get_option('gf_csv_exporter_settings', []);
                $settings[$form_id] = [
                    'fields' => $fields,
                    'strip_html' => $strip_html,
                    'strip_punctuation' => $strip_punctuation,
                    'entry_limit' => $entry_limit
                ];
                update_option('gf_csv_exporter_settings', $settings);

                // Optionally, re-generate CSV from all past submissions
                $this->regenerate_csv($form_id, $fields, $strip_html, $strip_punctuation, $entry_limit);
            }

            wp_redirect(add_query_arg(['edit_form_id' => $form_id], admin_url('admin.php?page=gf-csv-exporter')));
            exit;
        }

        // Handle delete form data action
        if (isset($_POST['action']) && $_POST['action'] === 'delete_form_data') {
            check_admin_referer('gf_csv_exporter_delete_form_data');
            $form_id = absint($_POST['form_id']);

            if ($form_id) {
                // Delete the CSV file
                $csv_path = $this->get_csv_path($form_id);
                if (file_exists($csv_path)) {
                    unlink($csv_path); // Delete the CSV file
                }

                // Remove form settings
                $settings = get_option('gf_csv_exporter_settings', []);
                if (isset($settings[$form_id])) {
                    unset($settings[$form_id]);
                    update_option('gf_csv_exporter_settings', $settings);
                }

                wp_redirect(admin_url('admin.php?page=gf-csv-exporter'));
                exit;
            }
        }
    }

    // Regenerate the CSV from all previous entries for the form
    public function regenerate_csv($form_id, $fields, $strip_html,  $strip_punctuation, $entry_limit) {
        // Get all form entries, or limit if entry limit is set
        $criteria = ['status' => 'active'];
        if ($entry_limit) {
            $paging = ['offset' => 0, 'page_size' => $entry_limit];
        } else {
            $paging = null; // No limit, get all entries
        }

        $entries = GFAPI::get_entries($form_id, $criteria, null, $paging);
        $csv_path = $this->get_csv_path($form_id);

        // Open the file for writing (overwrite mode)
        $file_handle = fopen($csv_path, 'w');

        if ($file_handle) {
            // Write header row with field labels (lowercase, spaces replaced by underscores)
            $header_row = [];
            foreach ($fields as $field_id) {
                $field = GFAPI::get_field($form_id, $field_id);
                $label = !empty($field->adminLabel) ? $field->adminLabel : $field->label;
                $header_row[] = strtolower(str_replace(' ', '_', $label));
            }
            fputcsv($file_handle, $header_row);

            foreach ($entries as $entry) {
                $row = [];
                foreach ($fields as $field_id) {
                    $field_value = rgar($entry, $field_id);

                    // Strip HTML if the option is enabled
                    if ($strip_html) {
                        $field_value = wp_strip_all_tags($field_value);
                    }

                    // Purge punctuation and slashes/brackets based on the new option
                    $field_value = $this->sanitize_field_value($field_value, $strip_punctuation);

                    $row[] = $field_value;
                }
                fputcsv($file_handle, $row);
            }
            fclose($file_handle);
        }
    }

    // Sanitize field values to remove commas, slashes, brackets, and non-standard Unicode characters
    private function sanitize_field_value($value, $strip_punctuation) {
        // Replace slashes and brackets with spaces
        $value = str_replace(['/', '\\', '(', ')', '[', ']', '<', '>', '{', '}', '|', "\r\n", "\r", "\n", "\u{00A0}"], ' ', $value);

        // Remove punctuation if the option is enabled
        if ($strip_punctuation) {
            // Replace punctuation with spaces
            $value = str_replace([',', '.', ':', ';', '?', '!', '&', '"', '“', '”', '…', '^', '#', '*'], ' ', $value);
        }

        // Remove non-ASCII characters (keep standard text only)
        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
        
        // Reduce multiple consecutive spaces to a single space
        $value = preg_replace('/\s+/', ' ', $value);

        // Trim any leading or trailing spaces
        return trim($value);
    }
}

// Initialize the plugin
new GF_CSV_Exporter();

// Function for redirecting the user to the created post on submission
add_action('gform_after_submission', 'redirect_to_created_post', 10, 2);
function redirect_to_created_post($entry, $form) {
    // Check if a post was created (i.e., 'post_id' is set in the entry)
    if (isset($entry['post_id']) && !empty($entry['post_id'])) {
        // Get the post ID
        $post_id = $entry['post_id'];

        // Get the permalink (URL) of the post
        $post_url = get_permalink($post_id);

        // Redirect to the post URL
        if ($post_url) {
            wp_redirect($post_url);
            exit; // Make sure the script stops here after the redirect
        }
    }
}
