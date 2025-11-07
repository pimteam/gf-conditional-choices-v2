<?php
/**
 * Plugin Name: Gravity Forms - Conditional Choices V2 (Minimal Admin)
 * Description: Minimal admin UI to define "exact choices when condition matches". Saves config only. No frontend logic yet.
 * Version: 0.1.0
 * Author: CC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GFCC_V2_Admin {

    const META_KEY = 'gfcc_config';
    const AJAX_ACTION = 'gfcc_get_choices';
    const NONCE_ACTION = 'gfcc_admin_nonce';
    const SLUG = 'gfcc'; // form settings tab slug

    public static function init() {
        // Add a new tab in Form Settings
        add_filter( 'gform_form_settings_menu', [ __CLASS__, 'add_settings_tab' ], 10, 2 );
        // Render that tab
        add_action( 'gform_form_settings_page_' . self::SLUG, [ __CLASS__, 'render_settings_page' ] );

        // Enqueue admin assets only on our tab
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

        // AJAX: get choices for a field
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_get_choices' ] );
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
        // Load only on the GF form settings page, our tab
        if ( ! isset( $_GET['page'], $_GET['view'], $_GET['subview'], $_GET['id'] ) ) {
            return;
        }
        if ( $_GET['page'] !== 'gf_edit_forms' || $_GET['view'] !== 'settings' || $_GET['subview'] !== self::SLUG ) {
            return;
        }

        wp_enqueue_script(
            'gfcc-admin',
            plugin_dir_url( __FILE__ ) . 'js/admin.js',
            [ 'jquery' ],
            '0.1.0',
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
            ],
        ] );
    }

    public static function render_settings_page(  ) {
        // Permission check: use GF capabilities if available, otherwise fall back.
        $has_cap = false;
        if ( class_exists( 'GFCommon' ) ) {
            $has_cap = GFCommon::current_user_can_any( array( 'gform_full_access', 'gravityforms_edit_forms' ) );
        } else {
            // Fallback if GF classes aren't ready for some reason.
            $has_cap = current_user_can( 'manage_options' );
        }

        if ( ! $has_cap ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to access this page.', 'gfcc' ) . '</p></div>';
            return;
        }

        // Always output the standard GF header/footer for Form Settings pages
        GFFormSettings::page_header();

        $form_id = absint( rgget( 'id' ) );

        $form = GFAPI::get_form( $form_id );

        if ( ! $form ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Form not found.', 'gfcc' ) . '</p></div>';
            GFFormSettings::page_footer();
            return;
        }


        // Load existing config
        $config = GFFormsModel::get_form_meta( $form_id, self::META_KEY );
        if ( ! is_array( $config ) ) {
            $config = [
                'version' => 2,
                'mode'    => 'last_match', // or first_match
                'targets' => [],
            ];
        }

        // Handle save
        if ( isset( $_POST['gfcc_save'] ) ) {
            check_admin_referer( self::NONCE_ACTION, 'gfcc_nonce' );

            // Very minimal parsing for M1 (one target, one group, one rule)
            $mode                 = in_array( $_POST['gfcc_mode'] ?? 'last_match', [ 'first_match', 'last_match' ], true ) ? $_POST['gfcc_mode'] : 'last_match';
            $target_field_id      = absint( $_POST['gfcc_target_field'] ?? 0 );
            $group_label          = sanitize_text_field( $_POST['gfcc_group_label'] ?? '' );
            $rule_field_id        = absint( $_POST['gfcc_rule_field'] ?? 0 );
            $rule_operator        = in_array( $_POST['gfcc_rule_operator'] ?? 'is', [ 'is', 'isnot' ], true ) ? $_POST['gfcc_rule_operator'] : 'is';
            $rule_value           = isset( $_POST['gfcc_rule_value'] ) ? wp_unslash( $_POST['gfcc_rule_value'] ) : '';
            $selected_choices_raw = isset( $_POST['gfcc_target_choices'] ) && is_array( $_POST['gfcc_target_choices'] ) ? $_POST['gfcc_target_choices'] : [];
            $selected_choices     = array_values( array_filter( array_map( 'sanitize_text_field', $selected_choices_raw ), 'strlen' ) );

            // Build minimal config
            $new_targets = [];
            if ( $target_field_id && $rule_field_id && ! empty( $selected_choices ) ) {
                $new_targets[ (string) $target_field_id ] = [
                    'enabled'  => true,
                    'fallback' => [ 'type' => 'original', 'choices' => [] ],
                    'groups'   => [
                        [
                            'id'        => uniqid( 'grp_', true ),
                            'label'     => $group_label ?: 'Group 1',
                            'enabled'   => true,
                            'logicType' => 'all',
                            'rules'     => [
                                [
                                    'fieldId'  => $rule_field_id,
                                    'operator' => $rule_operator,
                                    'value'    => (string) $rule_value,
                                ],
                            ],
                            'choices'   => $selected_choices,
                        ],
                    ],
                ];
            }

            $config['mode']    = $mode;
            $config['targets'] = $new_targets;

            GFFormsModel::update_form_meta( $form_id, self::META_KEY, $config );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configuration saved.', 'gfcc' ) . '</p></div>';
        }

        // Reload (in case we just saved)
        $config = GFFormsModel::get_form_meta( $form_id, self::META_KEY );
        if ( ! is_array( $config ) ) {
            $config = [
                'version' => 2,
                'mode'    => 'last_match',
                'targets' => [],
            ];
        }

        $fields_all         = self::get_all_fields_list( $form );
        $fields_with_choices= self::get_fields_with_choices_list( $form );

        // Pre-fill UI from saved config (first/only target in M1)
        $saved_mode          = $config['mode'] ?? 'last_match';
        $saved_target_id     = 0;
        $saved_group_label   = '';
        $saved_rule_field    = 0;
        $saved_rule_operator = 'is';
        $saved_rule_value    = '';
        $saved_choices       = [];

        if ( ! empty( $config['targets'] ) ) {
            $firstTargetKey   = array_key_first( $config['targets'] );
            $targetCfg        = $config['targets'][ $firstTargetKey ];
            $saved_target_id  = (int) $firstTargetKey;
            if ( ! empty( $targetCfg['groups'] ) ) {
                $grp                 = $targetCfg['groups'][0];
                $saved_group_label   = $grp['label'] ?? '';
                if ( ! empty( $grp['rules'] ) ) {
                    $saved_rule_field    = (int) ( $grp['rules'][0]['fieldId'] ?? 0 );
                    $saved_rule_operator = in_array( $grp['rules'][0]['operator'] ?? 'is', [ 'is', 'isnot' ], true ) ? $grp['rules'][0]['operator'] : 'is';
                    $saved_rule_value    = (string) ( $grp['rules'][0]['value'] ?? '' );
                }
                $saved_choices = array_map( 'strval', $grp['choices'] ?? [] );
            }
        }

        ?>
        <div class="wrap">
            <h2><?php echo esc_html__( 'Conditional Choices', 'gfcc' ); ?></h2>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, 'gfcc_nonce' ); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="gfcc_mode"><?php esc_html_e( 'Mode', 'gfcc' ); ?></label></th>
                            <td>
                                <select name="gfcc_mode" id="gfcc_mode">
                                    <option value="first_match" <?php selected( $saved_mode, 'first_match' ); ?>><?php esc_html_e( 'First match wins', 'gfcc' ); ?></option>
                                    <option value="last_match"  <?php selected( $saved_mode, 'last_match' );  ?>><?php esc_html_e( 'Last match wins', 'gfcc' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Evaluation strategy for multiple groups (future). For now, there is one group.', 'gfcc' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="gfcc_target_field"><?php esc_html_e( 'Target field', 'gfcc' ); ?></label></th>
                            <td>
                                <select name="gfcc_target_field" id="gfcc_target_field">
                                    <option value=""><?php esc_html_e( '— Select —', 'gfcc' ); ?></option>
                                    <?php foreach ( $fields_with_choices as $f ): ?>
                                        <option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $saved_target_id, $f['id'] ); ?>>
                                            <?php echo esc_html( $f['label'] . ' (#' . $f['id'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Field whose choices will be dynamically restricted.', 'gfcc' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="gfcc_group_label"><?php esc_html_e( 'Group label', 'gfcc' ); ?></label></th>
                            <td>
                                <input type="text" name="gfcc_group_label" id="gfcc_group_label" class="regular-text" value="<?php echo esc_attr( $saved_group_label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Retired user', 'gfcc' ); ?>">
                                <p class="description"><?php esc_html_e( 'A friendly name for this condition group.', 'gfcc' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Rule (minimal M1)', 'gfcc' ); ?></th>
                            <td>
                                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                    <label for="gfcc_rule_field" class="screen-reader-text"><?php esc_html_e( 'Field', 'gfcc' ); ?></label>
                                    <select name="gfcc_rule_field" id="gfcc_rule_field">
                                        <option value=""><?php esc_html_e( '— Field —', 'gfcc' ); ?></option>
                                        <?php foreach ( $fields_all as $f ): ?>
                                            <option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $saved_rule_field, $f['id'] ); ?>>
                                                <?php echo esc_html( $f['label'] . ' (#' . $f['id'] . ')' ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <label for="gfcc_rule_operator" class="screen-reader-text"><?php esc_html_e( 'Operator', 'gfcc' ); ?></label>
                                    <select name="gfcc_rule_operator" id="gfcc_rule_operator">
                                        <option value="is"    <?php selected( $saved_rule_operator, 'is' ); ?>><?php esc_html_e( 'is', 'gfcc' ); ?></option>
                                        <option value="isnot" <?php selected( $saved_rule_operator, 'isnot' ); ?>><?php esc_html_e( 'is not', 'gfcc' ); ?></option>
                                    </select>

                                    <label for="gfcc_rule_value" class="screen-reader-text"><?php esc_html_e( 'Value', 'gfcc' ); ?></label>
                                    <input type="text" name="gfcc_rule_value" id="gfcc_rule_value" value="<?php echo esc_attr( $saved_rule_value ); ?>" placeholder="<?php esc_attr_e( 'e.g. 1', 'gfcc' ); ?>">
                                </div>
                                <p class="description"><?php esc_html_e( 'Minimal rule for M1: one field, one operator, one value.', 'gfcc' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="gfcc_target_choices"><?php esc_html_e( 'Choices to show when rule matches', 'gfcc' ); ?></label></th>
                            <td>
                                <select multiple name="gfcc_target_choices[]" id="gfcc_target_choices" style="min-width:260px; min-height:120px;">
                                    <?php
                                    // If we have a saved target and saved choices, try to pre-populate
                                    if ( $saved_target_id ) {
                                        $field = self::get_field_by_id( $form, $saved_target_id );
                                        if ( $field && is_array( $field->choices ) ) {
                                            foreach ( $field->choices as $ch ) {
                                                $val = (string) ( $ch['value'] ?? $ch['text'] ?? '' );
                                                if ( $val === '' ) continue;
                                                printf(
                                                    '<option value="%s" %s>%s</option>',
                                                    esc_attr( $val ),
                                                    selected( in_array( $val, $saved_choices, true ), true, false ),
                                                    esc_html( $ch['text'] ?? $val )
                                                );
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select exact choices to display when the above rule is true. Change the Target field to reload choices.', 'gfcc' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="gfcc_save" class="button button-primary"><?php esc_html_e( 'Save configuration', 'gfcc' ); ?></button>
                </p>
            </form>

            <h3><?php esc_html_e( 'Current config (debug)', 'gfcc' ); ?></h3>
            <textarea readonly rows="12" style="width:100%; font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
        </div>
        <?php

        GFFormSettings::page_footer();
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
            if ( $val === '' ) continue;
            $out[] = [
                'value' => $val,
                'text'  => (string) ( $ch['text'] ?? $val ),
            ];
        }
        wp_send_json_success( [ 'choices' => $out ] );
    }

    private static function get_all_fields_list( $form ) {
        $out = [];
        if ( empty( $form['fields'] ) ) {
            return $out;
        }
        foreach ( $form['fields'] as $f ) {
            if ( ! is_object( $f ) ) continue;
            $label = method_exists( $f, 'get_field_label' ) ? $f->get_field_label( true, '' ) : ( $f->label ?? 'Field' );
            $out[] = [
                'id'    => (int) $f->id,
                'label' => $label,
            ];
        }
        return $out;
    }

    private static function get_fields_with_choices_list( $form ) {
        $out = [];

        if ( empty( $form['fields'] ) ) {
            return $out;
        }
        foreach ( $form['fields'] as $f ) {
            if ( ! is_object( $f ) ) continue;
            if ( isset( $f->choices ) && is_array( $f->choices ) && ! empty( $f->choices ) ) {
                $label = method_exists( $f, 'get_field_label' ) ? $f->get_field_label( true, '' ) : ( $f->label ?? 'Field' );
                $out[] = [
                    'id'    => (int) $f->id,
                    'label' => $label,
                ];
            }
        }
        return $out;
    }

    private static function get_field_by_id( $form, $field_id ) {
        if ( empty( $form['fields'] ) ) {
            return null;
        }
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
        GFCC_V2_Admin::init();
    }
}, 5 );
