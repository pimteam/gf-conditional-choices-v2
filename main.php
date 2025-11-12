<?php
/**
 * Plugin Name: Gravity Forms - Conditional Choices V2
 * Description: Define conditional choices for Gravity Forms fields.
 * Version: 2.0.0
 * Author: Cerebral Consulting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GFCC_V2_Plugin {

    const META_KEY = 'gfcc_config_v2';
    const AJAX_ACTION = 'gfcc_v2_get_choices';
    const NONCE_ACTION = 'gfcc_v2_admin_nonce';
    const SLUG = 'gfcc_v2';

    public static function init() {
        add_filter( 'gform_form_settings_menu', [ __CLASS__, 'add_settings_tab' ], 10, 2 );
        add_action( 'gform_form_settings_page_' . self::SLUG, [ __CLASS__, 'render_settings_page' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_get_choices' ] );

        // Frontend Hooks
        add_action( 'gform_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_scripts' ], 10, 2 );
        //add_filter( 'gform_pre_render', [ __CLASS__, 'apply_conditions_server_side' ], 100 );
        add_filter( 'gform_pre_validation', [ __CLASS__, 'apply_conditions_server_side' ], 100 );
        add_filter( 'gform_pre_submission_filter', [ __CLASS__, 'apply_conditions_server_side' ], 100 );
    }

    public static function add_settings_tab( $menu_items, $form_id ) {
        $menu_items[] = [
            'name'  => self::SLUG,
            'label' => esc_html__( 'Conditional Choices', 'gfcc' ),
            'icon'  => 'dashicons-filter',
        ];
        return $menu_items;
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( ! isset( $_GET['page'], $_GET['view'], $_GET['subview'], $_GET['id'] ) ||
             $_GET['page'] !== 'gf_edit_forms' || $_GET['view'] !== 'settings' || $_GET['subview'] !== self::SLUG ) {
            return;
        }

        wp_enqueue_style('gfcc-admin-css', plugin_dir_url(__FILE__) . 'css/admin.css', [], '2.0.0');
        wp_enqueue_script(
            'gfcc-admin',
            plugin_dir_url( __FILE__ ) . 'js/admin.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            '2.0.0',
            true
        );

        $form_id = absint( $_GET['id'] );
        wp_localize_script( 'gfcc-admin', 'GFCC_ADMIN', [
            'ajaxurl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
            'formId'       => $form_id,
            'strings'      => [
                'loading' => __( 'Loading choices...', 'gfcc' ),
                'error'   => __( 'Error loading choices.', 'gfcc' ),
                'delete_rule' => __('Delete rule', 'gfcc'),
                'delete_group' => __('Delete group', 'gfcc'),
                'confirm_delete_group' => __('Are you sure you want to delete this condition group?', 'gfcc'),
                'confirm_delete_target' => __('Are you sure you want to delete this entire configuration? This cannot be undone.', 'gfcc'),
            ],
        ] );
    }

    public static function render_settings_page() {
        if ( ! GFCommon::current_user_can_any( [ 'gravityforms_edit_forms' ] ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        GFFormSettings::page_header();

        $form_id = absint( rgget( 'id' ) );
        $action = sanitize_text_field( rgget( 'action' ) );
        $target_id = rgget( 'target_id' ); // Can be 'new' or an integer

        self::handle_edit_page_actions($form_id, $target_id);

        if ( $action === 'edit' && !empty($target_id) ) {
            self::render_edit_page( $form_id, $target_id );
        } else {
            self::render_list_page( $form_id );
        }

        GFFormSettings::page_footer();
    }

    private static function render_list_page( $form_id ) {
        $form = GFAPI::get_form( $form_id );
        $config = rgar( $form, self::META_KEY, [] );
        $targets = $config['targets'] ?? [];
        $base_url = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . self::SLUG . '&id=' . $form_id );

        if ( isset( $_GET['deleted'] ) && $_GET['deleted'] === 'true' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configuration deleted.', 'gfcc' ) . '</p></div>';
        }
        if ( isset( $_GET['saved'] ) && $_GET['saved'] === 'true' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configuration saved.', 'gfcc' ) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2><?php esc_html_e( 'Conditional Choices Configurations', 'gfcc' ); ?></h2>
                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'edit', 'target_id' => 'new' ], $base_url ) ); ?>" class="button button-primary button-hero">
                    <?php esc_html_e( 'Add New', 'gfcc' ); ?>
                </a>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 40%;"><?php esc_html_e( 'Target Field', 'gfcc' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'ID', 'gfcc' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Condition Groups', 'gfcc' ); ?></th>
                        <th scope="col" style="width: 15%;"><?php esc_html_e( 'Actions', 'gfcc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $targets ) ): ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e( 'No configurations found. Click "Add New" to get started.', 'gfcc' ); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ( $targets as $tid => $tconfig ): ?>
                            <?php
                            $field = self::get_field_by_id( $form, $tid );
                            $label = $field ? ($field->get_field_label(true, '') . " (#{$tid})") : sprintf(__( 'Field #%d (Not found)', 'gfcc' ), $tid);
                            $edit_url = add_query_arg( [ 'action' => 'edit', 'target_id' => $tid ], $base_url );
                            $delete_url = add_query_arg( [ 'action' => 'delete', 'target_id' => $tid, '_wpnonce' => wp_create_nonce( 'gfcc_delete_target_' . $tid ) ], $base_url );
                            ?>
                            <tr>
                                <td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $label ); ?></a></strong></td>
                                <td><?php echo esc_html( $tid ); ?></td>
                                <td><?php echo count( $tconfig['groups'] ?? [] ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Edit', 'gfcc' ); ?></a>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __('Are you sure you want to delete this entire configuration? This cannot be undone.', 'gfcc') ); ?>');"><?php esc_html_e( 'Delete', 'gfcc' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_edit_page( $form_id, $target_id ) {
        $form = GFAPI::get_form( $form_id );
        $config = rgar( $form, self::META_KEY, [] );
        $is_new = $target_id === 'new';

        $current_target = null;
        if (!$is_new) {
            $current_target = $config['targets'][$target_id] ?? null;
        }

        if ( !$is_new && !$current_target ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Configuration not found.', 'gfcc' ) . '</p></div>';
            return;
        }

        $fields_all = self::get_all_fields_list( $form );
        $fields_with_choices = self::get_fields_with_choices_list( $form );
        $base_url = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . self::SLUG . '&id=' . $form_id );

        // Get IDs of fields that are already configured.
        $configured_target_ids = isset($config['targets']) ? array_keys($config['targets']) : [];

        // Default structure for a new target
        if ($is_new) {
            $current_target = [
                'enabled' => true,
                'groups' => [
                    [
                        'id' => 'group_' . uniqid(),
                        'label' => 'Condition Group 1',
                        'enabled' => true,
                        'logicType' => 'all',
                        'rules' => [
                            ['fieldId' => '', 'operator' => 'is', 'value' => '']
                        ],
                        'choices' => []
                    ]
                ]
            ];
        }

        ?>
        <div class="wrap gfcc-edit-page">
            <h1 class="wp-heading-inline">
                <?php $is_new ? esc_html_e( 'Add New Configuration', 'gfcc' ) : esc_html_e( 'Edit Configuration', 'gfcc' ); ?>
            </h1>
            <a href="<?php echo esc_url($base_url); ?>" class="page-title-action"><?php esc_html_e('Back to List', 'gfcc'); ?></a>
            <hr class="wp-header-end">

            <form method="post" id="gfcc_edit_form">
                <input type="hidden" name="gfcc_form_id" value="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="gfcc_target_id_hidden" value="<?php echo esc_attr($target_id); ?>">
                <?php wp_nonce_field( self::NONCE_ACTION, 'gfcc_nonce' ); ?>

                <div id="poststuff">
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e('Target Field', 'gfcc'); ?></h2>
                        <div class="inside">
                            <p><?php esc_html_e('This is the field whose choices will be changed.', 'gfcc'); ?></p>
                            <select name="gfcc_target_field_id" id="gfcc_target_field_selector" <?php echo !$is_new ? 'disabled' : ''; ?>>
                                <option value=""><?php esc_html_e('— Select a Target Field —', 'gfcc'); ?></option>
                                <?php foreach ($fields_with_choices as $f):
                                    // On 'Add New' page, skip fields that are already configured.
                                    if ($is_new && in_array($f['id'], $configured_target_ids)) {
                                        continue;
                                    }
                                ?>
                                    <option value="<?php echo esc_attr($f['id']); ?>" <?php selected($is_new ? '' : $target_id, $f['id']); ?>>
                                        <?php echo esc_html($f['label'] . ' (#' . $f['id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$is_new): ?>
                                <input type="hidden" name="gfcc_target_field_id" value="<?php echo esc_attr($target_id); ?>">
                                <p class="description"><?php esc_html_e('The target field cannot be changed after creation.', 'gfcc'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="gfcc-groups-container" class="postbox">
                        <h2 class="hndle"><?php esc_html_e('Condition Groups', 'gfcc'); ?></h2>
                        <div class="inside">
                            <p><?php esc_html_e('Define one or more groups of conditions. If the conditions in a group are met, the specified choices will be shown.', 'gfcc'); ?></p>
                            <div class="gfcc-groups-wrapper">
                                <?php foreach ($current_target['groups'] as $g_idx => $group): ?>
                                    <?php self::render_group_template($g_idx, $group, $fields_all); ?>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" id="gfcc_add_group"><?php esc_html_e('Add Condition Group', 'gfcc'); ?></button>
                        </div>
                    </div>

                     <div id="gfcc-choices-box" class="postbox" style="<?php echo $is_new ? 'display:none;' : ''; ?>">
                        <h2 class="hndle"><?php esc_html_e('Available Choices', 'gfcc'); ?></h2>
                        <div class="inside">
                            <p><?php esc_html_e('These are the choices available for the selected target field. Drag them into the "Choices to Show" area in your condition groups.', 'gfcc'); ?></p>
                            <ul id="gfcc-available-choices" class="gfcc-choices-list">
                                <?php
                                if (!$is_new) {
                                    $target_field_obj = self::get_field_by_id($form, $target_id);
                                    if ($target_field_obj && !empty($target_field_obj->choices)) {
                                        foreach ($target_field_obj->choices as $choice) {
                                            printf('<li data-value="%s">%s</li>', esc_attr($choice['value']), esc_html($choice['text']));
                                        }
                                    } else {
                                        echo '<li>' . esc_html__('No choices found for this field.', 'gfcc') . '</li>';
                                    }
                                } else {
                                     echo '<li>' . esc_html__('Select a target field to see available choices.', 'gfcc') . '</li>';
                                }
                                ?>
                            </ul>

                            <p class="submit">
                                <button type="submit" name="gfcc_save" class="button button-primary button-large"><?php esc_html_e( 'Save Configuration', 'gfcc' ); ?></button>
                            </p>
                        </div>
                    </div>
                </div>


            </form>
        </div>

        <!-- JS Templates -->
        <script type="text/html" id="tmpl-gfcc-group">
            <?php self::render_group_template('__GROUP_ID__', [], $fields_all); ?>
        </script>
        <script type="text/html" id="tmpl-gfcc-rule">
            <?php self::render_rule_template('__GROUP_ID__', '__RULE_ID__', [], $fields_all); ?>
        </script>
        <?php
    }

    private static function render_group_template($g_idx, $group, $fields_all) {
        $group_id = $group['id'] ?? 'group_' . uniqid();
        $label = $group['label'] ?? 'New Condition Group';
        $logic_type = $group['logicType'] ?? 'all';
        $rules = $group['rules'] ?? [['fieldId' => '', 'operator' => 'is', 'value' => '']];
        $choices = $group['choices'] ?? [];
        ?>
        <div class="gfcc-group postbox" data-group-id="<?php echo esc_attr($g_idx); ?>">
            <input type="hidden" name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][id]" value="<?php echo esc_attr($group_id); ?>">
            <div class="handlediv" title="Click to toggle"><br></div>
            <h3 class="hndle">
                <span class="gfcc-group-label-text"><?php echo esc_html($label); ?></span>
                <input type="text" name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][label]" value="<?php echo esc_attr($label); ?>" class="gfcc-group-label-input" style="display:none;">
                <button type="button" class="button-link gfcc-delete-group"><?php esc_html_e('Delete', 'gfcc'); ?></button>
            </h3>
            <div class="inside">
                <div class="gfcc-group-logic">
                    <strong><?php esc_html_e('Show choices in this group if', 'gfcc'); ?></strong>
                    <select name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][logicType]">
                        <option value="all" <?php selected($logic_type, 'all'); ?>><?php esc_html_e('All', 'gfcc'); ?></option>
                        <option value="any" <?php selected($logic_type, 'any'); ?>><?php esc_html_e('Any', 'gfcc'); ?></option>
                    </select>
                    <strong><?php esc_html_e('of the following rules match:', 'gfcc'); ?></strong>
                </div>
                <div class="gfcc-rules-wrapper">
                    <?php foreach ($rules as $r_idx => $rule): ?>
                        <?php self::render_rule_template($g_idx, $r_idx, $rule, $fields_all); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button gfcc-add-rule"><?php esc_html_e('Add Rule', 'gfcc'); ?></button>
                <hr>
                <div class="gfcc-group-choices">
                    <strong><?php esc_html_e('Choices to Show:', 'gfcc'); ?></strong>
                    <p class="description"><?php esc_html_e('Drag choices from the "Available Choices" box on the right.', 'gfcc'); ?></p>
                    <ul class="gfcc-choices-list gfcc-assigned-choices">
                        <?php foreach ($choices as $choice_val): ?>
                            <li data-value="<?php echo esc_attr($choice_val); ?>">
                                <?php echo esc_html($choice_val); // Note: We don't have the label here, just the value. JS will fix. ?>
                                <input type="hidden" name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][choices][]" value="<?php echo esc_attr($choice_val); ?>">
                                <a href="#" class="gfcc-remove-choice">×</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>


                </div>
            </div>
        </div>
        <?php
    }

    private static function render_rule_template($g_idx, $r_idx, $rule, $fields_all) {
        $field_id = $rule['fieldId'] ?? '';
        $operator = $rule['operator'] ?? 'is';
        $value = $rule['value'] ?? '';
        ?>
        <div class="gfcc-rule" data-rule-id="<?php echo esc_attr($r_idx); ?>">
            <select name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][rules][<?php echo esc_attr($r_idx); ?>][fieldId]">
                <option value=""><?php esc_html_e('— Select Field —', 'gfcc'); ?></option>
                <?php foreach ($fields_all as $f): ?>
                    <option value="<?php echo esc_attr($f['id']); ?>" <?php selected($field_id, $f['id']); ?>>
                        <?php echo esc_html($f['label'] . ' (#' . $f['id'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][rules][<?php echo esc_attr($r_idx); ?>][operator]">
                <option value="is" <?php selected($operator, 'is'); ?>><?php esc_html_e('is', 'gfcc'); ?></option>
                <option value="isnot" <?php selected($operator, 'isnot'); ?>><?php esc_html_e('is not', 'gfcc'); ?></option>
                <option value=">" <?php selected($operator, '>'); ?>><?php esc_html_e('greater than', 'gfcc'); ?></option>
                <option value="<" <?php selected($operator, '<'); ?>><?php esc_html_e('less than', 'gfcc'); ?></option>
                <option value="contains" <?php selected($operator, 'contains'); ?>><?php esc_html_e('contains', 'gfcc'); ?></option>
                <option value="starts_with" <?php selected($operator, 'starts_with'); ?>><?php esc_html_e('starts with', 'gfcc'); ?></option>
                <option value="ends_with" <?php selected($operator, 'ends_with'); ?>><?php esc_html_e('ends with', 'gfcc'); ?></option>
            </select>
            <input type="text" name="gfcc_config[groups][<?php echo esc_attr($g_idx); ?>][rules][<?php echo esc_attr($r_idx); ?>][value]" value="<?php echo esc_attr($value); ?>" placeholder="<?php esc_attr_e('Value', 'gfcc'); ?>">
            <button type="button" class="button-link button-link-delete gfcc-delete-rule">
                <span class="dashicons dashicons-trash"></span>
                <span class="screen-reader-text"><?php esc_html_e('Delete Rule', 'gfcc'); ?></span>
            </button>
        </div>
        <?php
    }

    private static function handle_edit_page_actions($form_id, &$target_id) {
        $base_url = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . self::SLUG . '&id=' . $form_id );

        // Handle Delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['target_id']) && isset($_GET['_wpnonce'])) {
            $tid_to_delete = sanitize_text_field($_GET['target_id']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'gfcc_delete_target_' . $tid_to_delete)) {
                $form = GFAPI::get_form($form_id);
                $config = rgar($form, self::META_KEY, []);
                if (isset($config['targets'][$tid_to_delete])) {
                    unset($config['targets'][$tid_to_delete]);
                    $form[self::META_KEY] = $config;
                    GFAPI::update_form($form);
                    wp_redirect(add_query_arg(['deleted' => 'true'], $base_url));
                    exit;
                }
            }
        }

        // Handle Save
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['gfcc_save'] ) ) {
            if ( ! isset( $_POST['gfcc_nonce'] ) || ! wp_verify_nonce( $_POST['gfcc_nonce'], self::NONCE_ACTION ) ) {
                wp_die( 'Nonce verification failed.' );
            }

            $form = GFAPI::get_form($form_id);
            $config = rgar($form, self::META_KEY, []);
            if (!is_array($config)) $config = [];
            if (!isset($config['targets'])) $config['targets'] = [];

            $posted_tid = sanitize_text_field($_POST['gfcc_target_field_id']);
            $posted_config = $_POST['gfcc_config'] ?? [];

            if (empty($posted_tid)) {
                 echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Error: Target field is required.', 'gfcc' ) . '</p></div>';
                 return;
            }

            $new_target_config = [
                'enabled' => true,
                'groups' => [],
            ];

            if (!empty($posted_config['groups'])) {
                foreach ($posted_config['groups'] as $group_data) {
                    $rules = [];
                    if (!empty($group_data['rules'])) {
                        foreach ($group_data['rules'] as $rule_data) {
                            if (empty($rule_data['fieldId'])) continue;
                            $rules[] = [
                                'fieldId' => absint($rule_data['fieldId']),
                                'operator' => sanitize_text_field($rule_data['operator']),
                                'value' => wp_unslash(sanitize_text_field($rule_data['value'])),
                            ];
                        }
                    }

                    // Only add group if it has rules
                    if (!empty($rules)) {
                        $new_target_config['groups'][] = [
                            'id' => sanitize_text_field($group_data['id']),
                            'label' => sanitize_text_field($group_data['label']),
                            'enabled' => true,
                            'logicType' => sanitize_text_field($group_data['logicType']),
                            'rules' => $rules,
                            'choices' => array_map('sanitize_text_field', $group_data['choices'] ?? []),
                        ];
                    }
                }
            }

            $config['targets'][$posted_tid] = $new_target_config;
            $form[self::META_KEY] = $config;
            $result = GFAPI::update_form($form);

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Error saving configuration: ', 'gfcc' ) . $result->get_error_message() . '</p></div>';
            } else {
                $redirect_url = add_query_arg(['action' => 'edit', 'target_id' => $posted_tid, 'saved' => 'true'], $base_url);
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    public static function ajax_get_choices() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $form_id  = absint( $_POST['form_id'] ?? 0 );
        $field_id = absint( $_POST['field_id'] ?? 0 );

        if ( ! $form_id || ! $field_id ) {
            wp_send_json_error( [ 'message' => 'Missing form_id or field_id.' ] );
        }

        $form  = GFAPI::get_form( $form_id );
        $field = self::get_field_by_id( $form, $field_id );

        if ( ! $field || ! is_array( $field->choices ) ) {
            wp_send_json_error( [ 'message' => 'Field not found or has no choices.' ] );
        }

        $out = [];
        foreach ( $field->choices as $ch ) {
            $val = (string) ( $ch['value'] ?? $ch['text'] ?? '' );
            $out[] = [
                'value' => $val,
                'text'  => (string) ( $ch['text'] ?? $val ),
            ];
        }
        wp_send_json_success( [ 'choices' => $out ] );
    }

    // Frontend Logic
    public static function enqueue_frontend_scripts( $form, $is_ajax ) {
        $config = rgar( $form, self::META_KEY );
        if ( ! is_array( $config ) || empty( $config['targets'] ) ) {
            return;
        }

        $form_id = (int) $form['id'];
        $data = [
            'formId'  => $form_id,
            'targets' => [],
        ];

        foreach ( $config['targets'] as $target_id => $target_cfg ) {
            if ( empty( $target_cfg['enabled'] ) ) continue;

            $field_obj = self::get_field_by_id($form, $target_id);
            if ( !$field_obj ) continue;

            $orig_choices = [];
            if (is_array($field_obj->choices)) {
                foreach ($field_obj->choices as $ch) {
                    $orig_choices[] = ['value' => $ch['value'] ?? '', 'text' => $ch['text'] ?? ''];
                }
            }

            $data['targets'][ (int)$target_id ] = [
                'groups'          => $target_cfg['groups'],
                'originalChoices' => $orig_choices,
            ];
        }

        if (empty($data['targets'])) return;

        $script_handle = 'gfcc-frontend';
        if ( ! wp_script_is( $script_handle, 'enqueued' ) ) {
            wp_enqueue_script(
                $script_handle,
                plugin_dir_url( __FILE__ ) . 'js/frontend.js',
                [ 'jquery' ],
                '2.0.2',
                true
            );
        }

        $inline_script = sprintf(
            'window.GFCC_FORMS = window.GFCC_FORMS || {}; window.GFCC_FORMS[%d] = %s;',
            $form_id,
            wp_json_encode( $data )
        );
        wp_add_inline_script( $script_handle, $inline_script, 'before' );
    }

    public static function apply_conditions_server_side( $form ) {
        $config = rgar( $form, self::META_KEY );
        if ( ! is_array( $config ) || empty( $config['targets'] ) ) {
            return $form;
        }
    
        foreach ( $config['targets'] as $target_field_id => $target_cfg ) {
            if ( empty( $target_cfg['enabled'] ) || empty( $target_cfg['groups'] ) ) {
                continue;
            }
    
            $target_field = self::get_field_by_id( $form, $target_field_id );
            if ( ! $target_field ) {
                continue;
            }
    
            $original_choices = $target_field->choices;
            $matched_choices  = null;
    
            foreach ( $target_cfg['groups'] as $group ) {
                if ( empty( $group['enabled'] ) || empty( $group['rules'] ) ) {
                    continue;
                }
    
                $group_match = self::evaluate_group_rules( $group['rules'], rgar( $group, 'logicType', 'all' ) );
    
                if ( $group_match ) {
                    $allowed_values = array_map( 'strval', (array) rgar( $group, 'choices', [] ) );
                    $filtered         = [];
                    foreach ( $original_choices as $ch ) {
                        if ( in_array( (string) $ch['value'], $allowed_values, true ) ) {
                            $filtered[] = $ch;
                        }
                    }
                    // For now, we only support one group match. 'first_match' is implied.
                    $matched_choices = $filtered;
                    break; 
                }
            }
    
            if ( $matched_choices !== null ) {
                $target_field->choices = $matched_choices;
            }
        }
    
        return $form;
    }

    private static function evaluate_group_rules( $rules, $logic_type = 'all' ) {
        $logic_type = strtolower( $logic_type ) === 'any' ? 'any' : 'all';
        $results = [];
    
        foreach ( $rules as $rule ) {
            $field_id = (int) ( $rule['fieldId'] ?? 0 );
            $operator = $rule['operator'] ?? 'is';
            $value    = (string) ( $rule['value'] ?? '' );
    
            if ( ! $field_id ) {
                $results[] = false;
                continue;
            }
    
            $submitted_value = self::get_submitted_value( $field_id );
            $match = false;
    
            switch ( $operator ) {
                case 'is':
                    $match = $submitted_value === $value;
                    break;
                case 'isnot':
                    $match = $submitted_value !== $value;
                    break;
                case '>':
                    $match = is_numeric($submitted_value) && is_numeric($value) && (float)$submitted_value > (float)$value;
                    break;
                case '<':
                    $match = is_numeric($submitted_value) && is_numeric($value) && (float)$submitted_value < (float)$value;
                    break;
                case 'contains':
                    $match = strpos($submitted_value, $value) !== false;
                    break;
                case 'starts_with':
                    $match = strpos($submitted_value, $value) === 0;
                    break;
                case 'ends_with':
                    $match = substr($submitted_value, -strlen($value)) === $value;
                    break;
            }
            $results[] = $match;
        }
    
        if ( empty($results) ) return false;
        $is_match = $logic_type === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true);
        return $is_match;
    }
    
    private static function get_submitted_value( $field_id ) {
        $input_name = 'input_' . $field_id;
        if ( isset( $_POST[ $input_name ] ) ) {
            $val = $_POST[ $input_name ];
            return is_array($val) ? (string)reset($val) : (string)$val;
        }
        return '';
    }


    // Helper Functions
    private static function get_all_fields_list( $form ) {
        $out = [];
        if ( empty( $form['fields'] ) ) return $out;
        foreach ( $form['fields'] as $f ) {
            if ( ! is_object( $f ) ) continue;
            $label = method_exists( $f, 'get_field_label' ) ? $f->get_field_label( true, '' ) : ( $f->label ?? 'Field' );
            $out[] = ['id' => (int) $f->id, 'label' => $label];
        }
        return $out;
    }

    private static function get_fields_with_choices_list( $form ) {
        $out = [];
        if ( empty( $form['fields'] ) ) return $out;
        foreach ( $form['fields'] as $f ) {
            if ( ! is_object( $f ) ) continue;
            if ( isset( $f->choices ) && is_array( $f->choices ) && ! empty( $f->choices ) ) {
                $label = method_exists( $f, 'get_field_label' ) ? $f->get_field_label( true, '' ) : ( $f->label ?? 'Field' );
                $out[] = ['id' => (int) $f->id, 'label' => $label];
            }
        }
        return $out;
    }

    private static function get_field_by_id( $form, $field_id ) {
        if ( empty( $form['fields'] ) ) return null;
        foreach ( $form['fields'] as $f ) {
            if ( is_object( $f ) && (int) $f->id === (int) $field_id ) {
                return $f;
            }
        }
        return null;
    }
}

add_action( 'gform_loaded', function() {
    if ( class_exists( 'GFForms' ) ) {
        GFCC_V2_Plugin::init();
    }
}, 5 );
