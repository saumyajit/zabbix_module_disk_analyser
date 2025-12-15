<?php
namespace Modules\diskanalyser\actions;

use CController;
use CControllerResponseData;
use API;
use Zabbix\Core\CView; // Keep for consistency

class DiskAnalysis extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    /**
     * Allow only regular Zabbix users, administrators, and super administrators.
     */
    protected function checkPermissions(): bool {
        $permit_user_types = [USER_TYPE_ZABBIX_USER, USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN];

        // Ensure the constants are correctly defined and user type is checked.
        return in_array($this->getUserType(), $permit_user_types, true);
    }

    protected function doAction(): void {
        // 1. Fetch Data
        $hosts = $this->getHosts();
        $diskData = $this->getAllDiskData($hosts);

        // 2. Calculate global summary metrics
        $summary = $this->calculateSummary($diskData);

        // 3. Prepare Data for View
        $data = [
            'diskData' => $diskData,
            'summary' => $summary,
            // Pass the formatting helper to the view for use in template
            'formatBytes' => [$this, 'formatBytes'] 
        ];

        // 4. Pass data to the view template ('data' view defined in manifest)
        $response = new CControllerResponseData($data);
        $this->setResponse($response);
    }
    
    // --- Helper Functions ---

    private function getHosts() {
        return API::Host()->get([
            'output' => ['hostid', 'name']
        ]);
    }

    private function getAllDiskData(array $hosts): array {
        $diskData = [];
        foreach ($hosts as $host) {
            $data = $this->getDiskUsageForHost($host);
            if (!empty($data)) {
                $diskData = array_merge($diskData, $data);
            }
        }
        return $diskData;
    }

    private function getDiskUsageForHost($host) {
        $hostDiskData = [];

        $items = API::Item()->get([
            'hostids' => $host['hostid'],
            'search' => ['key_' => 'vfs.fs.size'],
            'output' => ['itemid', 'key_', 'name', 'lastvalue'],
            'selectTriggers' => 'extend',
            'webitems' => true
        ]);

        foreach ($items as $item) {
            if (preg_match('/vfs\.fs\.size\[(.*),free\]/i', $item['key_'], $matches)) {
                $mountPoint = trim($matches[1], '"') ?: '/';

                $total_item_key = str_replace('free', 'total', $item['key_']);
                $total_item = API::Item()->get([
                    'hostids' => $host['hostid'],
                    'search' => ['key_' => $total_item_key],
                    'output' => ['lastvalue'],
                    'limit' => 1
                ]);

                $totalRaw = $total_item[0]['lastvalue'] ?? 0;
                if ($totalRaw == 0) continue;

                $usedRaw = $totalRaw - $item['lastvalue'];
                $usagePct = round(($usedRaw / $totalRaw) * 100, 1);
                
                // --- Simple/Mocked Prediction ---
                $growthRate = round(rand(10, 500) / 100, 2); // 0.10 - 5.00 GB/day
                $days = $growthRate > 0 ? floor(($totalRaw - $usedRaw) / 1073741824 / $growthRate) : 999;

                $daysUntilFull = match(true) {
                    $days <= 0 => 'Already full',
                    $days > 365 => floor($days / 365) . ' years ' . floor(($days % 365) / 30) . ' months',
                    $days > 30 => floor($days / 30) . ' months ' . ($days % 30) . ' days',
                    default => $days . ' days'
                };

                $fsCritical = 0;
                $fsWarnings = 0;
                if (!empty($item['triggers'])) {
                    foreach ($item['triggers'] as $trigger) {
                        if (in_array($trigger['priority'], [4, 5])) $fsCritical++;
                        elseif (in_array($trigger['priority'], [2, 3])) $fsWarnings++;
                    }
                }

                $hostDiskData[] = [
                    'host' => $host['name'],
                    'mount' => $mountPoint,
                    'totalRaw' => $totalRaw,
                    'usedRaw' => $usedRaw,
                    'totalSpace' => $this->formatBytes($totalRaw),
                    'usedSpace' => $this->formatBytes($usedRaw),
                    'usagePct' => $usagePct,
                    'growthRate' => $growthRate, // GB/day
                    'daysUntilFull' => $daysUntilFull,
                    'fsWarnings' => $fsWarnings,
                    'fsCritical' => $fsCritical
                ];
            }
        }
        return $hostDiskData;
    }

    private function calculateSummary(array $diskData): array {
        $totalRaw = 0.0;
        $usedRaw = 0.0;
        $totalGrowth = 0.0;
        
        foreach ($diskData as $d) {
            $totalRaw += $d['totalRaw'];
            $usedRaw += $d['usedRaw'];
            $totalGrowth += $d['growthRate'];
        }
        
        $usagePct = $totalRaw > 0 ? round(($usedRaw / $totalRaw) * 100, 1) : 0;
        
        return [
            'totalStorage' => $this->formatBytes($totalRaw),
            'usedStorage' => $this->formatBytes($usedRaw) . ' (' . $usagePct . '% of total capacity)',
            'avgGrowth' => (count($diskData) > 0 ? round($totalGrowth / count($diskData), 2) : 0) . ' GB/day',
            'usagePct' => $usagePct,
            'riskyFilesystems' => $this->getTopRiskyFilesystems($diskData)
        ];
    }
    
    private function getTopRiskyFilesystems(array $diskData): array {
        $riskyData = array_filter($diskData, fn($item) => $item['growthRate'] > 0);
        
        usort($riskyData, function($a, $b) {
            // Sort by usage percentage for a simple "risky" ranking
            return $b['usagePct'] <=> $a['usagePct']; 
        });

        return array_slice($riskyData, 0, 5);
    }
    
    public function formatBytes($bytes, $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
