<?php
/*
Plugin Name: WP-DMARC Aggregate Collection and Parsing
Version: 0.1.0
Plugin URI: https://zabreznik.net/category/wp-dmarc/
Description: Collect and visualise DMARC Aggregate reports.
Author: Marko Zabreznik
Author URI: https://zabreznik.net
*/

define('WPDMARC_DATABASE_VERSION', 5);

class WPDMARC
{

    private static $instance = null;

    public function __construct()
    {

        register_activation_hook(__FILE__, array($this, 'on_activate_function'));
        register_deactivation_hook(__FILE__, array($this, 'on_deactivate_function'));

        add_action('init', array($this, 'init'));

        if (is_admin()) {
            add_action('admin_menu', array($this, 'init_admin'));
        }

        add_action('wpdmarc_cron', array($this, 'cron_action'));

    }

    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public static function plugin_action_links($links)
    {
        $links[] = '<a href="' . esc_url(get_admin_url(null, 'tools.php?page=wpdmarc-reports')) . '">Reports</a>';
        $links[] = '<a href="' . esc_url(get_admin_url(null, 'tools.php?page=wpdmarc-reports&reparse')) . '">Reparse</a>';
        $links[] = '<a href="' . esc_url(get_admin_url(null, 'tools.php?page=wpdmarc-reports&fetch')) . '">Fetch</a>';
        return $links;
    }

    public static function on_activate_function()
    {
        if (wp_next_scheduled('wpdmarc_cron') === FALSE) {
            wp_schedule_event(time(), 'daily', 'wpdmarc_cron');
        }
    }

    public static function on_deactivate_function()
    {
        wp_clear_scheduled_hook('wpdmarc_cron');
    }

    public function init()
    {

        // todo
        // this is a simple way to keep the emails safe
        // will fail on nginx tho
        if (!defined('WPDMARC_DIR')) {
            define('WPDMARC_DIR', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'dmarc' . DIRECTORY_SEPARATOR);
            if (!file_exists(WPDMARC_DIR . '.htaccess')) {
                file_put_contents(WPDMARC_DIR . '.htaccess', 'deny from all');
            }
        }

        if (get_option('obrazci_database_version', 0, true) < WPDMARC_DATABASE_VERSION) {

            global $wpdb;

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "
            CREATE TABLE {$wpdb->prefix}wpdmarc_report (
              id BIGINT(20) NOT NULL AUTO_INCREMENT,
              slug varchar(40) NOT NULL,
              filename varchar(255) NOT NULL,
              org_name varchar(255) NOT NULL,
              report_id varchar(255) NOT NULL,
              begin DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
              end DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
              domain varchar(255),
              adkim varchar(20),
              aspf varchar(20),
              p varchar(20),
              sp varchar(20),
              pct varchar(20),
              UNIQUE KEY slug (slug),
              PRIMARY KEY  (id)
            ) $charset_collate;
            CREATE TABLE {$wpdb->prefix}wpdmarc_record (
              id BIGINT(20) NOT NULL AUTO_INCREMENT,
              report_slug varchar(40) NOT NULL,
              header_from varchar(255),
              source_ip varchar(39),
              count int(10) NOT NULL,
              disposition varchar(20),
              dkim varchar(20),
              spf varchar(20),
              auth_results LONGTEXT,
              PRIMARY KEY  (id)
            ) $charset_collate;
            ";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            update_option('wpdmarc_database_version', WPDMARC_DATABASE_VERSION);
        }

    }

    public function init_admin()
    {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        add_management_page('DMARC Reports', 'DMARC Reports', 'manage_options', 'wpdmarc-reports', array($this, 'render_reports'));
    }

    public function render_reports()
    {

        $messages = array();

        if (array_key_exists('reparse', $_GET)) {
            $emails = glob(WPDMARC_DIR . '*.eml');
            $updated = $this->email_parse($emails);

            $messages = array(
                array('notice', "Reparse updated $updated records.")
            );
        }

        if (array_key_exists('fetch', $_GET)) {
            $updated = $this->cron_action();
            if ($updated === false) {
                $messages = array(
                    array('notice', "Cound not fetch email. Please check POP/IMAP settings.")
                );
            } else {
                $messages = array(
                    array('notice', "Fetched $updated records.")
                );
            }

        }

        require_once(__DIR__ . '/admin-list.php');
    }

    private function email_parse($emails)
    {

        # I hate PHP so much.

        $updated = 0;

        foreach ($emails as $email) {

            $file = file_get_contents($email);

            // handle zips
            if ($file !== false && preg_match_all('/Content-(?:Type|Disposition): (?:application\/zip|attachment);[\s\r\n]+(?:file)?name="?([^"]+)\.zip"?.*?\r?\n\r?\n([^-=]+)/si', $file, $matches, PREG_SET_ORDER)) {

                # so php has a IMAP collection, but it cant work with already downloaded emails,
                # it has a ZIP function, but it can only work with already downloaded files

                # yes I know PHP has this magic lib for reading emails but I don't want to have another lib requierment
                # also, the MailParse suite is quite awful and needs another wrapper that would need another lib
                # so all that is replaced with 1 regex and some magic,
                # it wont work for everything but aint nobody got time for that

                # luckily, PHP can do magic, but this only works if the .xml is named exactly like the .zip

                $tmpfname = tempnam("/tmp", "wpdmrc");

                foreach ($matches as $match) {
                    # make a temp file

                    $handle = fopen($tmpfname, "w");

                    # save the zip into it
                    fwrite($handle, base64_decode($match[2]));
                    fclose($handle);

                    # use magic to get the XML out
                    $data = file_get_contents("zip://$tmpfname#{$match[1]}.xml");

                    $updated += $this->save_xml_to_db($data, basename($email));
                }

                # remove temp
                unlink($tmpfname);


            }
        }

        return $updated;
    }

    private function save_xml_to_db($string, $filename)
    {
        $xml = new SimpleXMLElement($string);

        $report_slug = $xml->report_metadata->org_name . '-' . $xml->report_metadata->report_id;

        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'wpdmarc_report',
            array(
                'slug' => $report_slug,
                'org_name' => $xml->report_metadata->org_name,
                'report_id' => $xml->report_metadata->report_id,
                'filename' => $filename,
                'begin' => date('Y-m-d H:i:s', (int)$xml->report_metadata->date_range->begin),
                'end' => date('Y-m-d H:i:s', (int)$xml->report_metadata->date_range->end),
                'domain' => $xml->policy_published->domain,
                'adkim' => $xml->policy_published->adkim,
                'aspf' => $xml->policy_published->aspf,
                'p' => $xml->policy_published->p,
                'sp' => $xml->policy_published->sp,
                'pct' => $xml->policy_published->pct,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        # delete old parsed values if any
        $wpdb->delete($wpdb->prefix . 'wpdmarc_record', array('report_slug' => $report_slug));

        $updated = 0;

        // parse records
        foreach ($xml->record as $record) {
            $wpdb->insert(
                $wpdb->prefix . 'wpdmarc_record',
                array(
                    'report_slug' => $report_slug,
                    'header_from' => $record->identifiers->header_from,
                    'source_ip' => $record->row->source_ip,
                    'count' => $record->row->count,
                    'disposition' => $record->row->policy_evaluated->disposition,
                    'dkim' => $record->row->policy_evaluated->dkim,
                    'spf' => $record->row->policy_evaluated->spf,
                    'auth_results' => json_encode($record->auth_results),
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
            );
            $updated += 1;
        }

        return $updated;

    }

    public function cron_action()
    {
        if (!defined('WPDMARC_SERV') || !defined('WPDMARC_NAME') || !defined('WPDMARC_PASS')) {
            return false;
        }
        $emails = $this->email_fetch(WPDMARC_SERV, WPDMARC_NAME, WPDMARC_PASS, WPDMARC_DIR);
        if ($emails) {
            return $this->email_parse($emails);
        }
        return 0;
    }

    private function email_fetch($serv, $name, $pass, $dir)
    {
        $emails = array();
        $emailconnection = imap_open($serv, $name, $pass);
        if (!$emailconnection) {
            throw new Exception('Could not connect to email server.');
        }
        $mails = imap_search($emailconnection, 'ALL', SE_UID);
        if (is_array($mails)) {
            foreach ($mails as $uid) {
                $id = uniqid();
                try {
                    if (!imap_savebody($emailconnection, $dir . 'email-' . $id . ".eml", $uid, null, FT_UID)) {
                        throw new Exception('Could not save email: ' . $id);
                    }
                    // email was saved, delete from server
                    imap_delete($emailconnection, $uid, FT_UID);
                    $emails[] = $dir . 'email-' . $id . ".eml";
                } catch (Exception $e) {
                    error_log($e);
                }
            }
        }
        imap_close($emailconnection, CL_EXPUNGE);
        return $emails;
    }

}

WPDMARC::get_instance();
