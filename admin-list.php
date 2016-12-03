<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPDMARC_Admin_List extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'report',
            'plural' => 'reports',
            'ajax' => false
        ));
    }

    public function column_default($item, $column_name)
    {
        echo $item[$column_name];
    }

    public function column_date($item)
    {
        echo $item['begin'] . '</br>' . $item['end'];
    }

    public function column_disposition($item)
    {
        echo $item['disposition'] . "</br> Count: {$item['count']}";
    }

    public function column_status($item)
    {
        echo "SPF: <span class='status-{$item['spf']}'>{$item['spf']}</span></br>DKIM: <span class='status-{$item['dkim']}'>{$item['dkim']}</span>";
    }

    public function column_source_ip($item)
    {
        echo "<a target=\"_blank\" href=\"http://www.ip-tracker.org/locator/ip-lookup.php?ip={$item['source_ip']}\">" . $item['source_ip'] . '</a></br>' . $item['header_from'];
    }

    public function column_filename($item)
    {
        echo "<span title='{$item['report_slug']} '>{$item['filename']}</span><br>{$item['org_name']}";
    }

    public function prepare_items()
    {
        global $wpdb;

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns()
        );

        $per_page = 50;

        // search
        $search = null;
        if (isset($_REQUEST['s'])) {
            $search = trim($_REQUEST['s']);
        }

        if ($search) {
            $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpdmarc_record WHERE `source_ip` LIKE %s", '%' . $search . '%'));
        } else {
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpdmarc_record");
        }


        $this->paged = filter_input(INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT);
        if (isset($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array('id', 'created', 'status'))) {
            $orderby = $_REQUEST['orderby'];
        } else {
            $orderby = 'p.begin';
        }
        $order = (isset($_REQUEST['order']) && in_array($_REQUEST['order'], array('asc', 'desc'))) ? $_REQUEST['order'] : 'desc';

        if ($search) {
            $items = $wpdb->get_results(
                $wpdb->prepare("
SELECT *
FROM {$wpdb->prefix}wpdmarc_record r 
INNER JOIN {$wpdb->prefix}wpdmarc_report p ON r.report_slug = p.slug WHERE `source_ip` LIKE %s
ORDER BY $orderby $order LIMIT %d OFFSET %d", '%' . $search . '%', $per_page, $this->paged * $per_page),
                ARRAY_A
            );
        } else {
            $items = $wpdb->get_results(
                $wpdb->prepare("
SELECT *
FROM {$wpdb->prefix}wpdmarc_record r 
INNER JOIN {$wpdb->prefix}wpdmarc_report p ON r.report_slug = p.slug 
ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $this->paged * $per_page),
                ARRAY_A
            );
        }

        $this->items = $items;
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }

    public function get_columns()
    {
        return array(
            'id' => __('ID'),
            'date' => 'Date range',
            'source_ip' => 'Source IP',
            'disposition' => 'Disposition',
            'status' => 'Status',
            'filename' => 'Source'
        );
    }

    public function get_sortable_columns()
    {
        $items = array(
            'id' => array('id', true),
            'source_ip' => array('source_ip', true),
            'disposition' => array('disposition', true),
            'filename' => array('filename', true),
            'domain' => array('domain', true),
            'date' => array('begin', true),
        );
        return $items;
    }

    protected function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="bulk-action[]" value="%s" />', $item['id']);
    }
}

?>
<style>
    .column-id {
        width: 40px;
    }

    .column-date {
        width: 160px !important;
    }

    .status-fail {
        color: red;
    }
</style>
<div class="wrap">
    <div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
    <h1>DMARC Reports</h1>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        google.charts.load('current', {'packages': ['corechart']});
        google.charts.setOnLoadCallback(drawChart);
        function drawChart() {

            var data = google.visualization.arrayToDataTable([
                ['Source IP', 'Count'],
                <?php
                global $wpdb;
                foreach ($wpdb->get_results(
                    "SELECT `source_ip`, SUM(`count`) as sum FROM {$wpdb->prefix}wpdmarc_report report INNER JOIN {$wpdb->prefix}wpdmarc_record record ON `record`.`report_slug` = `report`.`slug` WHERE `begin` > DATE_ADD(NOW(), INTERVAL -3 MONTH) AND `disposition` != 'none' GROUP BY `source_ip` HAVING SUM(`count`) > 1",
                    ARRAY_A
                ) as $result) {
                    echo "['{$result['source_ip']}', {$result['sum']} ], \n";
                }

                ?>
            ]);

            var options = {
                title: 'Quarantined IPs > 1 in the last 3 months'
            };

            var chart = new google.visualization.PieChart(document.getElementById('piechart'));

            chart.draw(data, options);
        }
    </script>

    <div id="piechart" style="width: 100%; height: 300px;"></div>

    <?
    $table = new WPDMARC_Admin_List();
    $table->prepare_items();
    foreach ($messages as $message) { ?>
    <div class="notice is-dismissible notice-<?php echo $message[0] ?> "><p><?php echo $message[1]; ?></p></div><?php
    }
    ?>
    <form method="POST">
        <?php $table->search_box('Search IP..', 'obrazci'); ?>
        <?php $table->display() ?>
        <?php
        printf('<input type="hidden" name="paged" value="%d" />', $table->paged);
        ?>
    </form>
</div>
