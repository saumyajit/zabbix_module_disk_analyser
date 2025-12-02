<?php
namespace Modules\diskanalyser\views;

use Zabbix\Core\CView;
use Zabbix\Core\CText;

class Data {
    
    private $diskData;

    public function __construct($diskData) {
        $this->diskData = $diskData;
    }

    // Render the page
    public function render() {
        echo $this->getHeader();
        echo $this->getDiskUsageTable();
        echo $this->getFooter();
    }

    // Header of the page
    private function getHeader() {
        return '
        <div class="header">
            <h1>Disk Usage Analysis</h1>
            <p>Analytical view of disk usage, growth, and predictions for all monitored hosts.</p>
        </div>';
    }

    // Table showing disk usage details
    private function getDiskUsageTable() {
        $tableContent = '';

        foreach ($this->diskData as $data) {
            $tableContent .= '
            <tr>
                <td>' . $data['host'] . '</td>
                <td>' . $data['growthRate'] . ' GB/day</td>
                <td>' . $data['daysUntilFull'] . ' days</td>
            </tr>';
        }

        return '
        <div class="disk-usage-table">
            <table class="zabbix-table">
                <thead>
                    <tr>
                        <th>Host</th>
                        <th>Growth Rate (GB/day)</th>
                        <th>Days Until Full</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $tableContent . '
                </tbody>
            </table>
        </div>';
    }

    // Footer of the page
    private function getFooter() {
        return '
        <div class="footer">
            <p>Data sourced from Zabbix monitoring system.</p>
        </div>';
    }
}
