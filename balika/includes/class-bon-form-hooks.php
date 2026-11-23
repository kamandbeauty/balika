<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس اتصال به فرم‌ساز Contact Form 7 - در صورت فعال بودن این پلاگین روی سایت،
 * هر فرم ارسالی موفق به‌صورت خودکار برای ادمین در بله ارسال می‌شود.
 * اگر Contact Form 7 نصب نباشد، این کلاس کاملاً بی‌اثر است (بدون خطا).
 */
class Bale_Order_Notifier_Form_Hooks {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // فقط در صورتی که هرکدام از این فرم‌سازها روی سایت فعال باشند، قلاب مربوطه اثر می‌کند
        add_action( 'wpcf7_mail_sent', [ $this, 'on_cf7_submitted' ] );
        add_action( 'wpforms_process_complete', [ $this, 'on_wpforms_submitted' ], 10, 4 );
        add_action( 'gform_after_submission', [ $this, 'on_gravity_submitted' ], 10, 2 );
        add_action( 'elementor_pro/forms/new_record', [ $this, 'on_elementor_submitted' ], 10, 2 );
    }

    public function on_cf7_submitted( $contact_form ) {
        if ( ! bon_get_option( 'form_notifications_enabled', false ) ) {
            return;
        }
        if ( ! class_exists( 'WPCF7_Submission' ) ) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if ( ! $submission ) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        $form_title  = method_exists( $contact_form, 'title' ) ? $contact_form->title() : 'فرم تماس';

        $rows = [];
        foreach ( $posted_data as $key => $value ) {
            if (!self::allowed('cf7:' . $contact_form->id(), $key)) continue;
            if ( is_array( $value ) ) {
                $value = implode( '، ', $value );
            }
            // فیلدهای فنی/داخلی CF7 که نمایش آن‌ها بی‌فایده است، رد می‌شوند
            if ( in_array( $key, [ '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', '_wpcf7_posted_data_hash' ], true ) ) {
                continue;
            }
            if ( $value === '' ) {
                continue;
            }
            $rows[] = "▫️ {$key}: {$value}";
        }

        $this->send_form_notification($form_title, $rows);
    }

    /**
     * ارسال اعلان فرم WPForms
     */
    public function on_wpforms_submitted( $fields, $entry, $form_data, $entry_id ) {
        if ( ! bon_get_option( 'form_notifications_enabled', false ) ) {
            return;
        }
        $form_title = $form_data['settings']['form_title'] ?? ( $form_data['id'] ?? 'فرم WPForms' );
        $rows = [];
        foreach ( $fields as $key => $field ) {
            if (!self::allowed('wpforms:' . ($form_data['id'] ?? ''), $field['id'] ?? $key, $field['type'] ?? '')) continue;
            $label = $field['name'] ?? ( $field['label'] ?? '' );
            $value = is_array( $field['value'] ?? null ) ? implode( '، ', $field['value'] ) : ( $field['value'] ?? '' );
            if ( $label === '' || $value === '' ) continue;
            $rows[] = "▫️ {$label}: {$value}";
        }
        $this->send_form_notification( $form_title, $rows );
    }

    /**
     * ارسال اعلان فرم Gravity Forms
     */
    public function on_gravity_submitted( $entry, $form ) {
        if ( ! bon_get_option( 'form_notifications_enabled', false ) ) {
            return;
        }
        $form_title = $form['title'] ?? 'فرم Gravity Forms';
        $rows = [];
        foreach ( $form['fields'] as $field ) {
            if (!self::allowed('gravity:' . ($form['id'] ?? ''), $field->id, $field->type ?? '')) continue;
            $label = $field->label ?? '';
            $value = rgar( $entry, (string) $field->id );
            if ( $label === '' || $value === '' ) continue;
            $rows[] = "▫️ {$label}: {$value}";
        }
        $this->send_form_notification( $form_title, $rows );
    }

    /**
     * ارسال اعلان فرم‌های المنتور پرو (ویجت فرم معمولی)
     */
    public function on_elementor_submitted( $record, $handler ) {
        if ( ! bon_get_option( 'form_notifications_enabled', false ) ) {
            return;
        }
        $form_name = $record->get_form_settings( 'form_name' ) ?: 'فرم المنتور';
        $raw_fields = $record->get( 'fields' );
        $rows = [];
        foreach ( (array) $raw_fields as $key => $field ) {
            if (!self::allowed('elementor:' . $form_name, $field['id'] ?? $key, $field['type'] ?? '')) continue;
            $label = $field['title'] ?? '';
            $value = is_array( $field['value'] ?? null ) ? implode( '، ', $field['value'] ) : ( $field['value'] ?? '' );
            if ( $label === '' || $value === '' ) continue;
            $rows[] = "▫️ {$label}: {$value}";
        }
        $this->send_form_notification( $form_name, $rows );
    }

    /**
     * متد مشترک ساخت و ارسال پیام اعلان فرم (برای همه فرم‌سازها)
     */
    public static function allowed($form, $field, $type = '') {
        // Never export common credential/payment fields, even if explicitly listed.
        if (preg_match('/password|passwd|secret|token|credit.?card|cvv|رمز/i', (string) $field . ' ' . (string) $type)) return false;
        if (!bon_get_option('form_rules_enabled', false)) return true;
        foreach (preg_split('/\r?\n/', (string) bon_get_option('form_rules', '')) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2 || trim($parts[0]) !== (string) $form) continue;
            return in_array((string) $field, array_map('trim', explode(',', $parts[1])), true);
        }
        return false;
    }

    private function send_form_notification( $form_title, $rows ) {
        if ( empty( $rows ) ) {
            return;
        }
        $message = "📝 فرم جدید ثبت شد: {$form_title}\n\n" . implode( "\n", $rows );
        Bale_Order_Notifier_Dispatcher::broadcast( trim( $message ) );
        Bale_Order_Notifier_Logger::log( 'success', "فرم «{$form_title}» ثبت و اعلان آن ارسال شد." );
    }
}
